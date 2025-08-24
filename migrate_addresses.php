<?php
// migrate_addresses.php (версия 7.0, с финальной эвристикой)

require_once 'config.php';

function logMigrate(string $message): void {
    echo $message . "\n";
    file_put_contents(MIGRATE_LOG_FILE, date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}

/**
 * Финальная, самая умная функция для разбивки адресов.
 */
function splitAddresses(string $addressBlock): array
{
    $addressBlock = trim($addressBlock);
    
    // Приоритет 1: Разделение по точке с запятой (самый надежный)
    if (str_contains($addressBlock, ';')) {
        return array_filter(array_map('trim', explode(';', $addressBlock)));
    }

    // Приоритет 2: Разделение по переносам строк
    if (preg_match('/\\r\\n|\\r|\\n/', $addressBlock)) {
        return array_filter(array_map('trim', preg_split('/\\r\\n|\\r|\\n/', $addressBlock)));
    }
    
    // Приоритет 3: Разделение по "Российская Федерация", если их несколько
    $delimiter = 'Российская Федерация';
    if (substr_count(mb_strtolower($addressBlock), mb_strtolower($delimiter)) > 1) {
        $parts = preg_split("/(" . preg_quote($delimiter, '/') . ")/i", $addressBlock, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $addresses = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            if (isset($parts[$i], $parts[$i + 1])) {
                $addresses[] = trim($parts[$i] . $parts[$i + 1]);
            }
        }
        if (!empty($addresses)) return $addresses;
    }
    
    // Приоритет 4: "Умное" разделение склеенных адресов через контекстный анализ
    // Паттерн для типов улиц
    $streetTypesPattern = '(?:ул|улица|пр|проспект|пер|переулок|наб|набережная|б-р|бульвар|площадь|пл|ш|шоссе|пр-д|проезд|линия|кан)\.?';
    // Паттерн для номера дома и всего, что после него (корпус, литера и т.д.)
    $housePattern = '(?:д|дом|двлд)\.?\s*[\w\d\s\/-]+?(?:\sлитера\s\w)?';
    
    // Ищем все вхождения "название улицы + номер дома"
    $fullAddressComponentPattern = '/([\d\w\s\S]+?' . $streetTypesPattern . '[\s\S]+?' . $housePattern . ')/iu';

    preg_match_all($fullAddressComponentPattern, $addressBlock, $matches);

    if (isset($matches[0]) && count($matches[0]) > 1) {
        logMigrate(" -> Обнаружен сложный случай склеенных адресов, применяется контекстный анализ...");
        
        $addressParts = $matches[0];
        
        // Определяем "префикс" (город/регион) - это все, что до первого найденного адреса
        $firstAddressPart = $addressParts[0];
        $prefixPos = mb_strpos($addressBlock, $firstAddressPart);
        $prefix = trim(mb_substr($addressBlock, 0, $prefixPos));

        $reconstructedAddresses = [];
        foreach ($addressParts as $part) {
            $reconstructedAddresses[] = trim($prefix . ' ' . trim($part));
        }
        
        logMigrate(" -> Успешно разделено на " . count($reconstructedAddresses) . " адресов.");
        return $reconstructedAddresses;
    }
    
    // Если ничего не сработало, возвращаем как есть
    return [trim($addressBlock)];
}

echo "Запуск миграции структуры адресов...\n";
logMigrate("=== Начало сеанса миграции ===");

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (Exception $e) {
    die("Ошибка подключения к БД: " . $e->getMessage() . "\n");
}

try {
    logMigrate("1. Проверка и создание таблицы `procurement_locations`...");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `procurement_locations` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `procurement_id` int(11) NOT NULL,
          `address` text NOT NULL,
          PRIMARY KEY (`id`),
          KEY `procurement_id` (`procurement_id`),
          FULLTEXT KEY `address_fulltext` (`address`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    logMigrate("Таблица `procurement_locations` готова.");

    $checkColumnStmt = $pdo->query("SHOW COLUMNS FROM `procurements` LIKE 'work_location'");
    if ($checkColumnStmt->rowCount() == 0) {
        logMigrate("Старое поле 'work_location' не найдено. Миграция уже была выполнена.");
        logMigrate("=== Сеанс миграции завершен успешно ===");
        exit;
    }
    
    logMigrate("2. Извлечение закупок для обработки...");
    $stmt = $pdo->query("SELECT id, work_location FROM procurements WHERE work_location IS NOT NULL AND work_location != ''");
    $procurements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($procurements);

    if ($total === 0) {
        logMigrate("Нет адресов для миграции. Удаляем старое пустое поле...");
    } else {
        logMigrate("Найдено $total закупок с адресами для миграции.");
        logMigrate("3. Очистка таблицы `procurement_locations` перед новой вставкой...");
        $pdo->exec("TRUNCATE TABLE `procurement_locations`;");

        logMigrate("4. Начало процесса переноса и разделения адресов...");
        
        $pdo->beginTransaction();
        $processed = 0;
        $totalAddresses = 0;
        foreach ($procurements as $proc) {
            $addresses = splitAddresses($proc['work_location']);
            
            foreach ($addresses as $address) {
                if (!empty($address)) {
                    $insertStmt = $pdo->prepare("INSERT INTO procurement_locations (procurement_id, address) VALUES (:proc_id, :addr)");
                    $insertStmt->execute([':proc_id' => $proc['id'], ':addr' => $address]);
                    $totalAddresses++;
                }
            }
            $processed++;
            printf("\rОбработано закупок: %d/%d | Перенесено адресов: %d", $processed, $total, $totalAddresses);
        }
        $pdo->commit();
        logMigrate("\nПеренос завершен. Всего перенесено $totalAddresses отдельных адресов.");
    }

    logMigrate("5. Удаление старого поля 'work_location' и его индекса...");
    $checkIndexStmt = $pdo->query("SHOW INDEX FROM procurements WHERE Key_name = 'work_location'");
    if ($checkIndexStmt->rowCount() > 0) {
        $pdo->exec("ALTER TABLE procurements DROP INDEX work_location;");
        logMigrate("Старый FULLTEXT индекс 'work_location' удален.");
    }
    $pdo->exec("ALTER TABLE procurements DROP COLUMN work_location;");
    logMigrate("Старое поле 'work_location' удалено.");

    logMigrate("\n=== Миграция успешно завершена! ===");

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logMigrate("\nОШИБКА! Миграция прервана. " . $e->getMessage());
    die();
}