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
    $addressBlock = preg_replace(['/\s+/', '/([,.])\1+/'], [' ', '$1'], $addressBlock);
    $addressBlock = preg_replace('/р\.\s*п\./i', 'рп.', $addressBlock);

    $parts = preg_split('/[;\r\n]+/', $addressBlock);
    if (count($parts) > 1) {
        $allAddresses = [];
        foreach ($parts as $i => $part) {
            $allAddresses = array_merge($allAddresses, splitAddresses(trim($part)));
        }
        return array_values(array_unique($allAddresses));
    }

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

    // --- Машина состояний ---
    $preparedBlock = str_replace(',', ' , ', $addressBlock);
    $preparedBlock = preg_replace('~(\S\.)([^\s.])~u', '$1 $2', $preparedBlock);
    $preparedBlock = trim(preg_replace('/\s+/', ' ', $preparedBlock));
    $tokens = explode(' ', $preparedBlock);

    $majorLocalityTypes = ['г', 'с', 'п', 'рп', 'пгт', 'село', 'город', 'деревня', 'станица', 'поселок', 'пос'];
    $subLocalityTypes = ['мкр', 'мкрн'];
    $localityTypes = array_merge($majorLocalityTypes, $subLocalityTypes);
    $streetTypes = ['ул', 'улица', 'пр', 'пр-т', 'проспект', 'пер', 'переулок', 'наб', 'набережная', 'б-р', 'бульвар', 'площадь', 'пл', 'ш', 'шоссе', 'пр-д', 'проезд', 'линия', 'кан', 'тер', 'дорога'];
    $housePartTypes = ['д', 'дом', 'корп', 'к', 'стр', 'строение', 'литера', 'лит'];
    $allMarkers = array_merge($localityTypes, $streetTypes, $housePartTypes);

    $prefix = '';
    $firstMarkerIndex = -1;
    foreach ($tokens as $i => $token) {
        if (in_array(mb_strtolower(rtrim($token, '.'), 'UTF-8'), $allMarkers)) {
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

    $finalAddresses = [];
    $currentAddressParts = [];

    $localityContextParts = [];
    $localityContextIsValid = false;
    $hasSeenLocality = false;
    $hasSeenStreet = false;
    $hasSeenHousePart = false;
    $addressPartCompleted = false;

    // Инициализация контекста из префикса
    if (!empty($prefix)) {
        $prefixTokens = explode(' ', str_replace(',', ' , ', $prefix));
        $lastCommaPos = array_search(',', array_reverse($prefixTokens, true));

        $potentialContext = ($lastCommaPos !== false) ? array_slice($prefixTokens, $lastCommaPos + 1) : $prefixTokens;
        $potentialContext = array_filter($potentialContext, 'trim'); 

        if (!empty($potentialContext)) {
            foreach ($potentialContext as $pToken) {
                if (in_array(mb_strtolower(rtrim($pToken, '.'), 'UTF-8'), $localityTypes)) {
                    $localityContextParts = $potentialContext;
                    $localityContextIsValid = true;
                    $hasSeenLocality = true;
                    break;
                }
            }
        }
    }

    foreach ($tokens as $token) {
        $cleanToken = mb_strtolower(rtrim($token, '.'), 'UTF-8');
        
        $isLocality = in_array($cleanToken, $localityTypes);
        $isMajorLocality = in_array($cleanToken, $majorLocalityTypes);
        $isStreet = in_array($cleanToken, $streetTypes);
        $isHousePart = in_array($cleanToken, $housePartTypes);
        $isMarker = $isLocality || $isStreet || $isHousePart;

        $startNewAddress = false;

        if (!empty($currentAddressParts)) {
            if ($isMajorLocality && ($hasSeenStreet || $hasSeenLocality)) {
                $startNewAddress = true;
            }
            if (!$startNewAddress && $addressPartCompleted && ($isStreet || (!$isMarker && $token !== ','))) {
                $startNewAddress = true;
            }
            if (!$startNewAddress && $isStreet && $hasSeenHousePart) {
                $startNewAddress = true;
            }
        }

        if ($startNewAddress) {
            $builtAddress = buildAddress($prefix, $currentAddressParts);
            $finalAddresses[] = $builtAddress;

            $currentAddressParts = $localityContextIsValid ? $localityContextParts : [];
            $hasSeenLocality = $localityContextIsValid;
            $hasSeenStreet = false;
            $hasSeenHousePart = false;
            $addressPartCompleted = false;
        }

        $currentAddressParts[] = $token;

        if ($isStreet && !$hasSeenStreet && !$localityContextIsValid) {
            $streetMarkerIndex = count($currentAddressParts) - 1;
            $streetNameStartIndex = $streetMarkerIndex;

            while ($streetNameStartIndex > 0 && $currentAddressParts[$streetNameStartIndex - 1] !== ',') {
                $streetNameStartIndex--;
            }

            $potentialContext = array_slice($currentAddressParts, 0, $streetNameStartIndex);

            $isNowValid = false;
            foreach ($potentialContext as $part) {
                if (in_array(mb_strtolower(rtrim($part, '.'), 'UTF-8'), $localityTypes)) {
                    $isNowValid = true;
                    break;
                }
            }
            if ($isNowValid) {
                $localityContextParts = $potentialContext;
                $localityContextIsValid = true;
            }
        }

        if ($isLocality) $hasSeenLocality = true;
        if ($isStreet) $hasSeenStreet = true;
        if ($isHousePart) $hasSeenHousePart = true;

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
    $address = preg_replace(['/\s*,\s*$/', '/\s+/'], ['', ' '], ' ' . $address);
    return trim($address);
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

    logMigrate("0. Удаление таблицы `procurement_locations`, если такая есть...");
    $pdo->exec("
        DROP TABLE IF EXISTS `procurement_locations`;
    ");
    logMigrate("Таблица `procurement_locations` удалена (если вообще существовала).");

    logMigrate("1. Создание таблицы `procurement_locations`...");
    $pdo->exec("
        CREATE TABLE `procurement_locations` (
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

    /*
    logMigrate("5. Удаление старого поля 'work_location' и его индекса...");
    $checkIndexStmt = $pdo->query("SHOW INDEX FROM procurements WHERE Key_name = 'work_location'");
    if ($checkIndexStmt->rowCount() > 0) {
        $pdo->exec("ALTER TABLE procurements DROP INDEX work_location;");
        logMigrate("Старый FULLTEXT индекс 'work_location' удален.");
    }
    $pdo->exec("ALTER TABLE procurements DROP COLUMN work_location;");
    logMigrate("Старое поле 'work_location' удалено.");
    */

    logMigrate("\n=== Миграция успешно завершена! ===");

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logMigrate("\nОШИБКА! Миграция прервана. " . $e->getMessage());
    die();
}
