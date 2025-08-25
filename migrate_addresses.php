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
    $addressBlock = preg_replace('/р\.\s*п\./i', 'рп.', $addressBlock);

    $parts = preg_split('/[;\r\n]+/', $addressBlock);
    if (count($parts) > 1) {
        $allAddresses = [];
        foreach ($parts as $part) {
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

    $regionTypes = ['обл', 'респ', 'край', 'ао'];
    $baseMajorLocalityTypes = ['г', 'с', 'п', 'рп', 'пгт', 'село', 'город', 'деревня', 'станица', 'поселок', 'пос'];
    $districtTypes = ['р-н', 'район'];
    $subLocalityTypes = ['мкр', 'мкрн'];
    $streetTypes = ['ул', 'улица', 'пр', 'пр-т', 'проспект', 'пер', 'переулок', 'наб', 'набережная', 'б-р', 'бульвар', 'площадь', 'пл', 'ш', 'шоссе', 'пр-д', 'проезд', 'линия', 'кан', 'тер', 'дорога'];
    $housePartTypes = ['д', 'дом', 'корп', 'к', 'стр', 'строение', 'литера', 'лит'];
    
    $addressPartStarters = array_merge($baseMajorLocalityTypes, $districtTypes, $subLocalityTypes, $streetTypes, $housePartTypes);
    $allMarkers = array_merge($regionTypes, $addressPartStarters);

    $groupSeparators = ['го:', 'мр:'];

    $minTokensForHeuristic = 20;
    $maxMarkerRatioForText = 0.05;
    $heuristicMarkers = array_diff($allMarkers, ['с', 'к', 'п']);

    $heuristicTokens = explode(' ', preg_replace(['/\s+/', '/([,.])\1+/'], [' ', '$1'], $addressBlock));
    $tokenCount = count($heuristicTokens);

    if ($tokenCount > $minTokensForHeuristic) {
        $markerCount = 0;
        foreach ($heuristicTokens as $token) {
            if (in_array(mb_strtolower(rtrim($token, '.,:'), 'UTF-8'), $heuristicMarkers)) {
                $markerCount++;
            }
        }
        $markerRatio = ($tokenCount > 0) ? $markerCount / $tokenCount : 0;
        
        if ($markerRatio < $maxMarkerRatioForText) {
            return [$addressBlock];
        }
    }

    $addressBlock = preg_replace(['/\s+/', '/([,.])\1+/'], [' ', '$1'], $addressBlock);
    $preparedBlock = str_replace(',', ' , ', $addressBlock);
    $preparedBlock = preg_replace('~(\S\.)([^\s.])~u', '$1 $2', $preparedBlock);
    $preparedBlock = trim(preg_replace('/\s+/', ' ', $preparedBlock));
    $tokens = explode(' ', $preparedBlock);

    $prefixLocalityMarkers = $baseMajorLocalityTypes;
    $allPrefixMarkers = array_merge($prefixLocalityMarkers, $subLocalityTypes, $streetTypes, $housePartTypes);

    $prefix = '';
    $firstMarkerIndex = -1;
    foreach ($tokens as $i => $token) {
        if (in_array(mb_strtolower(rtrim($token, '.'), 'UTF-8'), $addressPartStarters)) {
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
        return [buildAddress($prefix, $tokens)];
    }

    $finalAddresses = [];
    $currentAddressParts = [];
    
    $localityContextParts = [];
    $localityContextIsValid = false;
    $hasSeenLocality = false;
    $hasSeenStreet = false;
    $hasSeenHousePart = false;
    $addressPartCompleted = false;
    $partJustFinished = false;
    $previousCleanToken = '';

    if (!empty($prefix)) {
        $prefixTokens = explode(' ', str_replace(',', ' , ', $prefix));
        $lastCommaPos = array_search(',', array_reverse($prefixTokens, true));
        
        $potentialContext = ($lastCommaPos !== false) ? array_slice($prefixTokens, $lastCommaPos + 1) : $prefixTokens;
        $potentialContext = array_filter($potentialContext, 'trim');

        if (!empty($potentialContext)) {
            foreach ($potentialContext as $pToken) {
                if (in_array(mb_strtolower(rtrim($pToken, '.'), 'UTF-8'), array_merge($baseMajorLocalityTypes, $districtTypes, $subLocalityTypes))) {
                    $localityContextParts = $potentialContext;
                    $localityContextIsValid = true;
                    $hasSeenLocality = true;
                    break;
                }
            }
        }
    }

    foreach ($tokens as $token) {
        $cleanTokenWithColon = mb_strtolower($token, 'UTF-8');
        $cleanToken = mb_strtolower(rtrim($token, '.'), 'UTF-8');

        if (in_array($cleanTokenWithColon, $groupSeparators)) {
            if (!empty(array_filter($currentAddressParts, 'trim'))) {
                $builtAddress = buildAddress($prefix, $currentAddressParts);
                $finalAddresses[] = $builtAddress;
            }
            $currentAddressParts = [];
            $localityContextParts = [];
            $localityContextIsValid = false;
            $hasSeenLocality = $hasSeenStreet = $hasSeenHousePart = $addressPartCompleted = $partJustFinished = false;
            continue;
        }
        
        $isDistrict = in_array($cleanToken, $districtTypes);
        $isMajorDistrict = $isDistrict && !$hasSeenStreet;
        $isSubDistrict = $isDistrict && $hasSeenStreet;

        $isBaseMajorLocality = in_array($cleanToken, $baseMajorLocalityTypes);
        $isMajorLocality = $isBaseMajorLocality || $isMajorDistrict;
        $isSubLocality = in_array($cleanToken, $subLocalityTypes) || $isSubDistrict;
        $isLocality = $isMajorLocality || $isSubLocality;

        $isStreet = in_array($cleanToken, $streetTypes);
        $isHousePart = in_array($cleanToken, $housePartTypes);
        $isMarker = $isLocality || $isStreet || $isHousePart;

        $startNewAddress = false;
        $splitReason = 0;

        if (!empty($currentAddressParts)) {
            if (in_array($cleanToken, $prefixLocalityMarkers) && $hasSeenLocality) {
                $startNewAddress = true;
                $splitReason = 1;
            }
            
            if (!$startNewAddress && $partJustFinished && $token !== ',' && !$isMarker) {
                if (!in_array($previousCleanToken, $allPrefixMarkers)) {
                    $startNewAddress = true;
                    $splitReason = 4;
                }
            }

            if (!$startNewAddress && $addressPartCompleted && ($isStreet || $isLocality || (!$isMarker && $token !== ','))) {
                 $startNewAddress = true;
                 $splitReason = 2;
            }
            if (!$startNewAddress && $isStreet && $hasSeenHousePart) {
                $startNewAddress = true;
                $splitReason = 3;
            }
        }
        
        if ($startNewAddress) {
            $builtAddress = buildAddress($prefix, $currentAddressParts);
            $finalAddresses[] = $builtAddress;
            
            if ($splitReason === 1 || ($splitReason === 2 && $isLocality)) {
                $currentAddressParts = [];
                $localityContextParts = [];
                $localityContextIsValid = false;
                $hasSeenLocality = false;
            } else {
                $currentAddressParts = $localityContextIsValid ? $localityContextParts : [];
                $hasSeenLocality = $localityContextIsValid;
            }
            
            $hasSeenStreet = false;
            $hasSeenHousePart = false;
            $addressPartCompleted = false;
            $partJustFinished = false; 
        }
        
        $currentAddressParts[] = $token;

        if (($isLocality || $isStreet) && !$localityContextIsValid) {
            $markerIndex = count($currentAddressParts) - 1;
            $nameStartIndex = $markerIndex;

            while ($nameStartIndex > 0 && $currentAddressParts[$nameStartIndex - 1] !== ',') {
                $nameStartIndex--;
            }

            $potentialContext = array_slice($currentAddressParts, 0, $nameStartIndex);
            $potentialContext = array_filter($potentialContext, fn($t) => trim($t) !== '');
            
            if (!empty($potentialContext)) {
                $isNowValid = false;
                foreach ($potentialContext as $part) {
                    if (in_array(mb_strtolower(rtrim($part, '.'), 'UTF-8'), array_merge($baseMajorLocalityTypes, $districtTypes, $subLocalityTypes))) {
                        $isNowValid = true;
                        break;
                    }
                }
                if ($isNowValid) {
                    $localityContextParts = $potentialContext;
                    $localityContextIsValid = true;
                }
            }
        }

        if ($isMarker) {
            $partJustFinished = true;
        } elseif ($token !== ',') {
            $partJustFinished = false;
        }
        
        if ($isLocality) $hasSeenLocality = true;
        if ($isStreet) {
            $hasSeenStreet = true;
            $hasSeenHousePart = false;
            $addressPartCompleted = false;
        }
        if ($isHousePart) {
            $hasSeenHousePart = true;
            $addressPartCompleted = false;
        }
        
        if ($token === ',' && $hasSeenHousePart) {
            $addressPartCompleted = true;
        }
        
        $previousCleanToken = $cleanToken;
    }

    if (!empty($currentAddressParts)) {
        $finalAddresses[] = buildAddress($prefix, $currentAddressParts);
    }
    
    return array_values(array_unique(array_filter($finalAddresses))); 
}

function buildAddress(string $prefix, array $parts): string {
    $address = trim($prefix . ' ' . implode(' ', $parts));
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
