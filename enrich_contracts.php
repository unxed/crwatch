<?php

require 'vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

// =============================================================================
// КОНФИГУРАЦИЯ
// =============================================================================
const INPUT_FILE = 'contracts.json';
const OUTPUT_FILE = 'contracts_enriched.json';

// =============================================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// =============================================================================

function fetchUrl(string $url): string|false
{
    echo "  Загрузка: " . substr($url, 0, 100) . "...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $html = curl_exec($ch);
    if (curl_errno($ch)) {
        echo '  ! Ошибка cURL: ' . curl_error($ch) . "\n";
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    return $html;
}

function displayProgressBar(int $current, int $total): void
{
    if ($total == 0) return;
    $progress = $current / $total;
    $barLength = 50;
    $filledLength = (int)($barLength * $progress);
    $bar = str_repeat('=', $filledLength) . str_repeat('-', $barLength - $filledLength);
    printf("\rПрогресс: [%s] %d/%d (%.2f%%)", $bar, $current, $total, $progress * 100);
}

function extractAuctionNumber(string $details): ?string
{
    if (preg_match('/№\s*(\d+)$/', $details, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Рекурсивно извлекает текст, сохраняя переносы от <br>.
 */
function getNodeTextWithNewlines(DomCrawler $crawlerNode): string
{
    if ($crawlerNode->count() === 0) {
        return '';
    }

    $text = '';
    // Используем нативный DOMNodeList и цикл foreach
    foreach ($crawlerNode->getNode(0)->childNodes as $node) {
        if ($node->nodeName === 'br') {
            $text .= "\n"; // Добавляем реальный перенос строки
        } elseif ($node->nodeType === XML_TEXT_NODE) {
            // Добавляем текстовое содержимое как есть, со всеми пробелами
            $text .= $node->nodeValue;
        } elseif ($node->nodeType === XML_ELEMENT_NODE) {
            // Для других тегов (например, <a> или <span>) рекурсивно вызываем функцию
            $text .= getNodeTextWithNewlines(new DomCrawler($node));
        }
    }

    // Умная многошаговая очистка:
    // 1. Заменяем множественные пробелы и табы на один пробел.
    $cleanedText = preg_replace('/[ \t]+/', ' ', $text);
    // 2. Разбиваем по переносам строк.
    $lines = explode("\n", $cleanedText);
    // 3. Обрезаем пробелы у каждой строки.
    $trimmedLines = array_map('trim', $lines);
    // 4. Удаляем пустые строки, которые могли образоваться.
    $nonEmptyLines = array_filter($trimmedLines);
    // 5. Собираем обратно с правильными переносами.
    return implode("\n", $nonEmptyLines);
}


/**
 * Обновленная функция парсинга, использующая getNodeTextWithNewlines.
 */
function parseAuctionPage(string $htmlContent): array
{
    $crawler = new DomCrawler($htmlContent);
    $enrichedData = [];

    $mainInfoNode = $crawler->filter('.cardMainInfo');
    if ($mainInfoNode->count() > 0) {
        $enrichedData['procurement_main_info'] = [
            'status' => $mainInfoNode->filter('.cardMainInfo__state')->count() ? trim($mainInfoNode->filter('.cardMainInfo__state')->text()) : null,
            'object' => getNodeTextWithNewlines($mainInfoNode->filter('.cardMainInfo__section .cardMainInfo__content')->eq(0)),
            'initial_price' => $mainInfoNode->filter('.cost')->count() ? trim($mainInfoNode->filter('.cost')->text()) : null,
            'published_date' => $mainInfoNode->filter('.date .cardMainInfo__content')->eq(0)->text(),
            'updated_date' => $mainInfoNode->filter('.date .cardMainInfo__content')->eq(1)->text(),
            'end_date' => $mainInfoNode->filter('.date .cardMainInfo__content')->eq(2)->text(),
        ];
    }

    $crawler->filter('.blockInfo')->each(function (DomCrawler $block) use (&$enrichedData) {
        $blockTitle = $block->filter('.blockInfo__title')->text();
        $sectionData = [];
        $block->filter('.section')->each(function (DomCrawler $section) use (&$sectionData) {
            $titleNode = $section->filter('.section__title');
            $infoNode = $section->filter('.section__info');

            if ($titleNode->count() && $infoNode->count()) {
                $title = trim($titleNode->text());
                $info = getNodeTextWithNewlines($infoNode);
                $sectionData[$title] = $info;
            }
        });
        $enrichedData[$blockTitle] = $sectionData;
    });

    return $enrichedData;
}


// --- ОСНОВНАЯ ЛОГИКА ЗАПУСКА ---
echo "Запуск скрипта обогащения данных...\n";

if (!file_exists(INPUT_FILE)) {
    die("Ошибка: Входной файл '" . INPUT_FILE . "' не найден. Сначала запустите первый скрипт.\n");
}
$sourceContracts = json_decode(file_get_contents(INPUT_FILE), true);
if (!is_array($sourceContracts)) {
    die("Ошибка: Не удалось прочитать данные из '" . INPUT_FILE . "'. Файл поврежден или имеет неверный формат.\n");
}
$totalContracts = count($sourceContracts);
echo "Найдено $totalContracts контрактов в '" . INPUT_FILE . "'.\n";

$enrichedContracts = [];
if (file_exists(OUTPUT_FILE)) {
    echo "Найден файл с результатами '" . OUTPUT_FILE . "'. Попытка возобновить работу...\n";
    $enrichedData = json_decode(file_get_contents(OUTPUT_FILE), true);
    if (is_array($enrichedData)) {
        $enrichedContracts = $enrichedData;
    }
}

$processedMap = [];
foreach ($enrichedContracts as $contract) {
    if (isset($contract['registry_number'])) {
        $processedMap[$contract['registry_number']] = true;
    }
}
$processedCount = count($processedMap);
echo "$processedCount контрактов уже было обработано.\n";

$currentIndex = 0;
foreach ($sourceContracts as $contract) {
    $currentIndex++;
    $registryNumber = $contract['registry_number'] ?? null;

    if (!$registryNumber || isset($processedMap[$registryNumber])) {
        displayProgressBar($currentIndex, $totalContracts);
        continue;
    }

    echo "\nОбработка контракта $currentIndex/$totalContracts ($registryNumber)...\n";

    $procurementDetails = $contract['procurement_details'] ?? null;
    if (!$procurementDetails) {
        echo "  - Пропущено: отсутствует поле 'procurement_details'.\n";
        continue;
    }

    $auctionNumber = extractAuctionNumber($procurementDetails);
    if (!$auctionNumber) {
        echo "  - Пропущено: не удалось извлечь номер аукциона из '$procurementDetails'.\n";
        continue;
    }

    $auctionUrl = "https://zakupki.gov.ru/epz/order/notice/ea615/view/common-info.html?regNumber=" . $auctionNumber;
    $auctionHtml = fetchUrl($auctionUrl);
    
    if (!$auctionHtml) {
        echo "  - Пропущено: не удалось загрузить страницу аукциона.\n";
        sleep(5);
        continue;
    }

    $auctionData = parseAuctionPage($auctionHtml);
    $mergedContract = array_merge($contract, ['procurement_info' => $auctionData]);
    
    $enrichedContracts[] = $mergedContract;
    file_put_contents(OUTPUT_FILE, json_encode($enrichedContracts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $processedMap[$registryNumber] = true;

    displayProgressBar($currentIndex, $totalContracts);
    //sleep(2);
}

echo "\n\n=================================================\n";
echo "Работа завершена.\n";
echo "Всего обработано контрактов: " . count($enrichedContracts) . "\n";
echo "Результаты сохранены в файл: " . OUTPUT_FILE . "\n";
echo "=================================================\n";
