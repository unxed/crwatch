<?php

require_once 'config.php';

function logMigrate(string $message): void {
    echo $message . "\n";
    file_put_contents(MIGRATE_LOG_FILE, date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}

// =============================================================================
// АЛГОРИТМ РАЗДЕЛЕНИЯ АДРЕСОВ
// =============================================================================

function splitAddresses(string $addressBlock): array
{
    if (empty(trim($addressBlock))) {
        return [];
    }

    $addressBlock = trim($addressBlock);
    // Сначала очищаем от множественных пробелов и знаков препинания
    $addressBlock = preg_replace(['/\s+/', '/([,.])\1+/'], [' ', '$1'], $addressBlock);
    
    // Предварительная обработка составных маркеров, чтобы они стали одним токеном
    $addressBlock = preg_replace('/р\.\s*п\./i', 'рп.', $addressBlock);

    // Приоритет 1: Разделение по надежным явным разделителям (точка с запятой, перенос строки)
    // Рекурсивно вызываем себя для каждой части
    $parts = preg_split('/[;\r\n]+/', $addressBlock);
    if (count($parts) > 1) {
        $allAddresses = [];
        foreach ($parts as $part) {
            if (!empty(trim($part))) {
                $allAddresses = array_merge($allAddresses, splitAddresses(trim($part)));
            }
        }
        return array_values(array_unique($allAddresses));
    }

    // Приоритет 2: Разделение по "Российская Федерация", если их несколько
    $delimiter = 'Российская Федерация';
    if (substr_count(mb_strtolower($addressBlock), mb_strtolower($delimiter)) > 1) {
        $parts = preg_split("/(" . preg_quote($delimiter, '/') . ")/i", $addressBlock, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $addresses = [];
        $currentAddress = '';
        foreach ($parts as $part) {
            if (mb_strtolower(trim($part)) === mb_strtolower($delimiter)) {
                if (!empty(trim($currentAddress))) {
                    $addresses = array_merge($addresses, splitAddresses(trim($currentAddress)));
                }
                $currentAddress = $part;
            } else {
                $currentAddress .= $part;
            }
        }
        if (!empty(trim($currentAddress))) {
            $addresses = array_merge($addresses, splitAddresses(trim($currentAddress)));
        }
        return array_values(array_unique($addresses));
    }

    // Приоритет 3: "Машина состояний" для разбора склеенных адресов
    
    // Подготовка токенов
    $preparedBlock = str_replace(',', ' , ', $addressBlock);
    $preparedBlock = preg_replace('~(\S\.)([^\s.])~u', '$1 $2', $preparedBlock);
    $preparedBlock = trim(preg_replace('/\s+/', ' ', $preparedBlock));
    $tokens = explode(' ', $preparedBlock);

    // Маркеры
    $majorLocalityTypes = ['г', 'с', 'п', 'рп', 'пгт', 'село', 'город', 'деревня', 'станица', 'поселок'];
    $subLocalityTypes = ['мкр', 'мкрн'];
    $localityTypes = array_merge($majorLocalityTypes, $subLocalityTypes);
    $streetTypes = ['ул', 'улица', 'пр', 'пр-т', 'проспект', 'пер', 'переулок', 'наб', 'набережная', 'б-р', 'бульвар', 'площадь', 'пл', 'ш', 'шоссе', 'пр-д', 'проезд', 'линия', 'кан', 'тер'];
    $housePartTypes = ['д', 'дом', 'корп', 'к', 'стр', 'строение', 'литера', 'лит'];
    $allMarkers = array_merge($localityTypes, $streetTypes, $housePartTypes);

    // Определение префикса (РФ, область, район)
    $prefix = '';
    $firstMarkerIndex = -1;
    foreach ($tokens as $i => $token) {
        if (in_array(rtrim($token, '.'), $allMarkers)) {
            $firstMarkerIndex = $i;
            break;
        }
    }

    if ($firstMarkerIndex != -1) {
        $startIndex = $firstMarkerIndex;
        while ($startIndex > 0 && $tokens[$startIndex - 1] !== ',') {
            $startIndex--;
        }
        if ($startIndex > 0) {
            $prefixTokens = array_slice($tokens, 0, $startIndex);
            $prefix = implode(' ', $prefixTokens);
            $tokens = array_slice($tokens, $startIndex);
        }
    } else {
        return [buildAddress('', $tokens)];
    }

    // Основной цикл машины состояний
    $finalAddresses = [];
    $currentAddressParts = [];
    
    $localityContextParts = [];
    $localityContextIsValid = false;
    $hasSeenLocality = false;
    $hasSeenStreet = false;
    $hasSeenHousePart = false;
    $addressPartCompleted = false;

    foreach ($tokens as $token) {
        $cleanToken = rtrim($token, '.');
        
        $isLocality = in_array($cleanToken, $localityTypes);
        $isMajorLocality = in_array($cleanToken, $majorLocalityTypes);
        $isStreet = in_array($cleanToken, $streetTypes);
        $isHousePart = in_array($cleanToken, $housePartTypes);
        $isMarker = $isLocality || $isStreet || $isHousePart;

        $startNewAddress = false;
        $splitByLocality = false;
        if (!empty($currentAddressParts)) {
            if ($isMajorLocality && ($hasSeenStreet || $hasSeenLocality)) {
                $startNewAddress = true;
                $splitByLocality = true;
            }
            if (!$startNewAddress && $addressPartCompleted && ($isStreet || (!$isMarker && $token !== ','))) {
                 $startNewAddress = true;
            }
            if (!$startNewAddress && $isStreet && $hasSeenHousePart) {
                $startNewAddress = true;
            }
        }

        if ($startNewAddress) {
            $finalAddresses[] = buildAddress($prefix, $currentAddressParts);
            
            if ($splitByLocality) {
                $currentAddressParts = [];
                $localityContextParts = [];
                $localityContextIsValid = false;
            } else {
                $currentAddressParts = $localityContextIsValid ? $localityContextParts : [];
            }

            $hasSeenLocality = $localityContextIsValid;
            $hasSeenStreet = false;
            $hasSeenHousePart = false;
            $addressPartCompleted = false;
        }

        $currentAddressParts[] = $token;

        if ($isLocality) $hasSeenLocality = true;
        if ($isStreet) $hasSeenStreet = true;
        if ($isHousePart) $hasSeenHousePart = true;

        if (!$hasSeenStreet) {
            $localityContextParts[] = $token;
            if ($isLocality) {
                $localityContextIsValid = true;
            }
        }

        if ($token === ',' && $hasSeenHousePart) {
            $addressPartCompleted = true;
        } elseif ($hasSeenHousePart) {
            $addressPartCompleted = false;
        }
    }

    if (!empty($currentAddressParts)) {
        $finalAddresses[] = buildAddress($prefix, $currentAddressParts);
    }

    return array_values(array_unique($finalAddresses));
}

function buildAddress(string $prefix, array $parts): string {
    $address = $prefix . ' ' . implode(' ', $parts);
    $address = trim($address);
    $address = preg_replace('/\s*,\s*/', ', ', $address);
    $address = preg_replace('/ ,/', ',', $address);
    $address = preg_replace(['/\s*,\s*$/', '/\s+/'], ['', ' '], $address);
    return $address;
}


// =============================================================================
// ОСНОВНАЯ ЛОГИКА МИГРАЦИИ
// =============================================================================

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
        logMigrate("Старое поле 'work_location' не найдено. Похоже, миграция уже была выполнена.");
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
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logMigrate("\nОШИБКА! Миграция прервана. " . $e->getMessage());
    die();
}
