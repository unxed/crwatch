<?php
// bot.php (финальная версия с гибридным поиском)

require 'vendor/autoload.php';
require_once 'config.php';

use Telegram\Bot\Api;

// =============================================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// =============================================================================

function logMessage(string $message): void {
    file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}

function escapeMarkdownV2(string $text): string {
    $escapeChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
    return str_replace($escapeChars, array_map(fn($char) => '\\' . $char, $escapeChars), $text);
}

function truncateForTelegram(string $text, int $maxLength = 1000): string {
    if (mb_strlen($text) > $maxLength) {
        return mb_substr($text, 0, $maxLength) . '...';
    }
    return $text;
}

// =============================================================================
// ИНИЦИАЛИЗАЦИЯ
// =============================================================================

try {
    $telegram = new Api(BOT_TOKEN);
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (Exception $e) {
    logMessage("Критическая ошибка инициализации: " . $e->getMessage());
    die("Критическая ошибка инициализации: " . $e->getMessage());
}

// =============================================================================
// ОСНОВНОЙ ЦИКЛ БОТА
// =============================================================================

echo "Бот запущен... Нажмите Ctrl+C для остановки.\n";
logMessage("Бот запущен.");

logMessage("Очистка очереди старых сообщений...");
$updates = $telegram->getUpdates(['offset' => -1]);
$offset = $updates ? end($updates)->getUpdateId() : 0;
logMessage("Очередь очищена. Начинаем слушать новые сообщения.");


while (true) {
    try {
        $updates = $telegram->getUpdates(['offset' => $offset + 1, 'timeout' => 30]);

        foreach ($updates as $update) {
            $offset = $update->getUpdateId();
            $message = $update->getMessage();
            if (!$message) continue;

            $chatId = $message->getChat()->getId();
            $text = $message->getText();
            if (empty($text)) continue;

            if ($text === '/start') {
                $startMessage = "Здравствуйте\! 👋\n\nЯ бот для поиска закупок по адресу\. Просто отправьте мне адрес или его часть, например:";
                $exampleBlock = "```\nБурятия Саган-Нур Больничная 12\n```";
                $telegram->sendMessage(['chat_id' => $chatId, 'text' => $startMessage . "\n" . $exampleBlock, 'parse_mode' => 'MarkdownV2']);
                continue;
            }

            logMessage("Получен поисковый запрос от chat_id $chatId: '$text'");
            
            // --- НОВАЯ ЛОГИКА ПОДГОТОВКИ ЗАПРОСА ---
            
            // 1. Очистка и разбивка на слова
            $stopWords = ['улица', 'ул', 'область', 'обл', 'район', 'р-н', 'рн', 'город', 'гор', 'г', 'поселок', 'пос', 'п', 'село', 'с', 'деревня', 'дер', 'д', 'дом', 'проспект', 'пр-т', 'пр', 'бульвар', 'б-р', 'республика', 'респ'];
            $cleanedText = preg_replace('/[.,\/#!$%\^&\*;:{}=\-`~()]/', ' ', mb_strtolower($text));
            $keywords = preg_split('/\s+/', $cleanedText, -1, PREG_SPLIT_NO_EMPTY);
            
            $searchableKeywords = array_diff($keywords, $stopWords);

            if (empty($searchableKeywords)) {
                $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Пожалуйста, введите более конкретный адрес для поиска."]);
                continue;
            }

            // 2. Разделение слов на длинные (для MATCH) и короткие (для LIKE)
            $fulltext_words = [];
            $like_conditions = [];
            $params = [];
            foreach ($searchableKeywords as $index => $keyword) {
                if (mb_strlen($keyword) >= 3) {
                    $fulltext_words[] = '+' . $keyword . '*';
                } else {
                    $placeholder = ":like" . $index;
                    $like_conditions[] = "pl.address LIKE " . $placeholder;
                    $params[$placeholder] = '%' . $keyword . '%';
                }
            }
            
            // 3. Динамическая сборка SQL запроса
            $sql = "
                SELECT 
                    p.reg_number, pl.address AS work_location, p.procurement_object,
                    c.name AS customer_name, contr.price, contr.conclusion_date
                FROM procurement_locations AS pl
                JOIN procurements AS p ON pl.procurement_id = p.id
                LEFT JOIN customers AS c ON p.customer_id = c.id
                LEFT JOIN contracts AS contr ON p.id = contr.procurement_id
                WHERE 1=1 
            ";
            
            if (!empty($fulltext_words)) {
                $fulltext_string = implode(' ', $fulltext_words);
                $sql .= " AND MATCH(pl.address) AGAINST(:search_string IN BOOLEAN MODE)";
                $params[':search_string'] = $fulltext_string;
            }

            if (!empty($like_conditions)) {
                $sql .= " AND " . implode(' AND ', $like_conditions);
            }
            
            $sql .= " LIMIT 10";
            
            logMessage("Сформирован SQL: " . preg_replace('/\s+/', ' ', $sql));
            logMessage("Параметры: " . json_encode($params));
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();

            // 4. Формирование и отправка ответа
            if (empty($results)) {
                $telegram->sendMessage(['chat_id' => $chatId, 'text' => "😔 К сожалению, по вашему запросу ничего не найдено."]);
            } else {
                $count = count($results);
                $telegram->sendMessage(['chat_id' => $chatId, 'text' => "✅ Найдено закупок: *" . $count . "*", 'parse_mode' => 'MarkdownV2']);
                
                foreach ($results as $index => $row) {
                    $priceFormatted = $row['price'] ? number_format($row['price'], 2, ',', ' ') . ' ₽' : 'Нет данных';
                    $objectText = truncateForTelegram($row['procurement_object'] ?? 'Нет данных', 500);
                    $locationText = truncateForTelegram($row['work_location'] ?? 'Нет данных', 250);
                    $detailsUrl = "https://zakupki.gov.ru/epz/order/notice/ea615/view/common-info.html?regNumber=" . $row['reg_number'];

                    $itemBlock = ["*" . escapeMarkdownV2(($index + 1) . ". Закупка №" . $row['reg_number']) . "*", "", "*Объект:* " . escapeMarkdownV2($objectText), "*Адрес:* " . escapeMarkdownV2($locationText), "*Цена контракта:* " . escapeMarkdownV2($priceFormatted), "*Дата заключения:* " . escapeMarkdownV2($row['conclusion_date'] ?? 'Нет данных'), "*Заказчик:* " . escapeMarkdownV2($row['customer_name'] ?? 'Нет данных'), "[Подробнее на сайте](" . $detailsUrl . ")"];
                    $reply = implode("\n", $itemBlock);
                    
                    try {
                        $telegram->sendMessage(['chat_id' => $chatId, 'text' => $reply, 'parse_mode' => 'MarkdownV2', 'disable_web_page_preview' => true]);
                    } catch (Exception $e) {
                        $plainTextReply = preg_replace('/([_*\[\]()~`>#+\-=|{}.!\\\\])/', '', $reply);
                        $telegram->sendMessage(['chat_id' => $chatId, 'text' => $plainTextReply]);
                        logMessage('Ошибка форматирования Markdown: ' . $e->getMessage());
                    }
                    usleep(300000);
                }
            }
        }
    } catch (Exception $e) {
        logMessage("Произошла ошибка в основном цикле: " . $e->getMessage());
    }
    sleep(1);
}
