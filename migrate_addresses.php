<?php

require_once 'config.php';

function logMigrate(string $message): void {
    echo $message . "\n";
    file_put_contents(MIGRATE_LOG_FILE, date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}

// =============================================================================
// АЛГОРИТМ РАЗДЕЛЕНИЯ АДРЕСОВ
// =============================================================================

/**
 * Разделяет текстовый блок, содержащий один или несколько адресов, на массив отдельных адресов.
 * Обрабатывает разделители в виде точки с запятой (с сохранением контекста) и запятых.
 *
 * @param string $addressBlock Входной блок текста с адресами.
 * @return array Массив строк, где каждая строка - отдельный адрес.
 */
function splitAddresses(string $addressBlock): array
{
    if (empty(trim($addressBlock))) {
        return [];
    }

    $addressBlock = trim($addressBlock);

    // Предварительная очистка от служебных конструкций типа (п.п. 1; 2)
    $addressBlock = preg_replace('/\s*\(\s*(п\.\s*(п\.\s*)?)\s*.*?\)/ui', '', $addressBlock);
    
    // Нормализация "р. п." в "рп."
    $addressBlock = preg_replace('/р\.\s*п\./i', 'рп.', $addressBlock);

    // Словари маркеров адресных частей
    $regionTypes = ['обл', 'респ', 'край', 'ао'];
    $baseMajorLocalityTypes = ['г', 'с', 'п', 'рп', 'пгт', 'село', 'город', 'деревня', 'станица', 'поселок', 'пос'];
    $districtTypes = ['р-н', 'район'];
    $subLocalityTypes = ['мкр', 'мкрн'];
    $streetTypes = ['ул', 'улица', 'пр', 'пр-т', 'проспект', 'пер', 'переулок', 'наб', 'набережная', 'б-р', 'бульвар', 'площадь', 'пл', 'ш', 'шоссе', 'пр-д', 'проезд', 'линия', 'кан', 'тер', 'дорога'];
    $housePartTypes = ['д', 'дом', 'корп', 'к', 'стр', 'строение', 'литера', 'лит'];

    // 1. Первичное разделение по точке с запятой и переносам строк (с сохранением контекста)
    $parts = preg_split('/[;\r\n]+/', $addressBlock);
    if (count($parts) > 1) {
        $allAddresses = [];
        
        $firstPartAddresses = splitAddresses(trim($parts[0]));
        if (empty($firstPartAddresses)) {
            // Если первая часть пуста или не является адресом, обрабатываем остальные независимо
            for ($i = 1; $i < count($parts); $i++) {
                 $allAddresses = array_merge($allAddresses, splitAddresses(trim($parts[$i])));
            }
            return array_values(array_unique($allAddresses));
        }

        $allAddresses = array_merge($allAddresses, $firstPartAddresses);
        $firstFullAddress = end($firstPartAddresses);

        // Извлечение родительского контекста (например, "Город") из первого полного адреса
        $parentContext = '';
        $streetPattern = implode('|', array_map(function($t) { return preg_quote($t, '/'); }, $streetTypes));
        $regex = '/^(.*?),\s*(?:\S+\s+)?\b(?:' . $streetPattern . ')\b\.?.*/iu';
        
        if (preg_match($regex, $firstFullAddress, $matches)) {
            $parentContext = trim($matches[1], " ,");
        } else {
            // Если в первой части нет улицы, считаем всю первую часть контекстом
            $parentContext = $firstFullAddress;
            array_pop($allAddresses);
        }

        if (!empty($parentContext)) {
            for ($i = 1; $i < count($parts); $i++) {
                $part = trim($parts[$i]);
                if (empty($part)) continue;
                
                // Проверка, является ли часть уже полным адресом
                $isAlreadyFull = false;
                $partTokens = explode(' ', $part);
                $majorLocalityMarkers = array_merge($regionTypes, $baseMajorLocalityTypes, $districtTypes);
                foreach ($partTokens as $token) {
                    if (in_array(mb_strtolower(rtrim($token, '.,'), 'UTF-8'), $majorLocalityMarkers)) {
                        $isAlreadyFull = true;
                        break;
                    }
                }

                // Применение контекста к неполным частям
                $addressToProcess = $isAlreadyFull ? $part : $parentContext . ', ' . $part;
                $allAddresses = array_merge($allAddresses, splitAddresses($addressToProcess));
            }
        } else {
             // Если контекст не удалось извлечь, обрабатываем части независимо
             for ($i = 1; $i < count($parts); $i++) {
                 $allAddresses = array_merge($allAddresses, splitAddresses(trim($parts[$i])));
            }
        }

        return array_values(array_unique($allAddresses));
    }

    // 2. Обработка множественных вхождений "Российская Федерация"
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
    
    // 3. Эвристика для отличения длинного текста от списка адресов
    $allMarkers = array_merge($regionTypes, $baseMajorLocalityTypes, $districtTypes, $subLocalityTypes, $streetTypes, $housePartTypes);
    $minTokensForHeuristic = 20;
    $heuristicMarkers = array_diff($allMarkers, ['с', 'к', 'п']);
    $heuristicTokens = explode(' ', preg_replace(['/\s+/', '/([,.])\1+/'], [' ', '$1'], $addressBlock));
    $tokenCount = count($heuristicTokens);

    if ($tokenCount > $minTokensForHeuristic) {
        $maxMarkerRatioForText = 0.05;
        $minMarkerRatioToOverride = 0.10;

        $markerCount = 0;
        foreach ($heuristicTokens as $token) {
            if (in_array(mb_strtolower(rtrim($token, '.,:'), 'UTF-8'), $heuristicMarkers)) {
                $markerCount++;
            }
        }
        $markerRatio = ($tokenCount > 0) ? $markerCount / $tokenCount : 0;

        if ($markerRatio < $minMarkerRatioToOverride) {
            $longWordLengthThreshold = 12;
            $longWordCountThreshold = 3;
            $longWordCount = 0;
            foreach ($heuristicTokens as $token) {
                if (mb_strlen(trim($token, '.,:()«»"\''), 'UTF-8') >= $longWordLengthThreshold) {
                    $longWordCount++;
                }
            }

            if ($longWordCount > $longWordCountThreshold || $markerRatio < $maxMarkerRatioForText) {
                return [$addressBlock]; // Считаем это обычным текстом
            }
        }
    }

    // 4. Основная логика разделения по запятым с использованием конечного автомата (FSM)
    $addressBlock = preg_replace(['/\s+/', '/([,.])\1+/'], [' ', '$1'], $addressBlock);
    $preparedBlock = str_replace(',', ' , ', $addressBlock);
    $preparedBlock = preg_replace('~(\S\.)([^\s.])~u', '$1 $2', $preparedBlock);
    $preparedBlock = trim(preg_replace('/\s+/', ' ', $preparedBlock));
    $tokens = explode(' ', $preparedBlock);

    $addressPartStarters = array_merge($baseMajorLocalityTypes, $districtTypes, $subLocalityTypes, $streetTypes, $housePartTypes);
    $prefixLocalityMarkers = $baseMajorLocalityTypes;

    // Извлечение префикса (часть до первого адресного маркера)
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
        // Если маркеров не найдено, возвращаем как есть
        return [buildAddress($prefix, $tokens)];
    }

    $finalAddresses = [];
    $currentAddressParts = [];
    
    // Переменные состояния FSM
    $localityContextParts = [];
    $localityContextIsValid = false;
    $hasSeenLocality = false;
    $hasSeenStreet = false;
    $hasSeenHousePart = false;
    $addressPartCompleted = false;
    $partJustFinished = false;
    $previousCleanToken = '';

    // Инициализация контекста из префикса, если возможно
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
        $cleanToken = mb_strtolower(rtrim($token, '.'), 'UTF-8');
        
        // Определяем тип токена
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
        $looksLikeHouseContinuation = (bool)preg_match('/^(\d+|[а-я])$/ui', $cleanToken);

        // Логика принятия решения о начале нового адреса
        $startNewAddress = false;
        if (!empty($currentAddressParts)) {
            if (in_array($cleanToken, $prefixLocalityMarkers) && $hasSeenLocality) {
                $startNewAddress = true;
            }
            if (!$startNewAddress && $partJustFinished && $token !== ',' && !$isMarker) {
                if (!in_array($previousCleanToken, array_merge($prefixLocalityMarkers, $subLocalityTypes, $streetTypes, $housePartTypes))) {
                    $startNewAddress = true;
                }
            }
            if (!$startNewAddress && $addressPartCompleted && ($isStreet || $isLocality || (!$isMarker && $token !== ',' && !$looksLikeHouseContinuation))) {
                 $startNewAddress = true;
            }
            if (!$startNewAddress && $isStreet && $hasSeenHousePart) {
                $startNewAddress = true;
            }
        }
        
        if ($startNewAddress) {
            $finalAddresses[] = buildAddress($prefix, $currentAddressParts);
            
            if ($isLocality) { // Полный сброс контекста
                $currentAddressParts = [];
                $localityContextParts = [];
                $localityContextIsValid = false;
                $hasSeenLocality = false;
            } else { // Сброс до контекста населенного пункта
                $currentAddressParts = $localityContextIsValid ? $localityContextParts : [];
                $hasSeenLocality = $localityContextIsValid;
            }
            
            $hasSeenStreet = false;
            $hasSeenHousePart = false;
            $addressPartCompleted = false;
            $partJustFinished = false; 
        }

        $currentAddressParts[] = $token;

        // Обновление контекста населенного пункта
        if (($isLocality || $isStreet) && !$localityContextIsValid) {
            $markerIndex = count($currentAddressParts) - 1;
            $nameStartIndex = $markerIndex;
            while ($nameStartIndex > 0 && $currentAddressParts[$nameStartIndex - 1] !== ',') {
                $nameStartIndex--;
            }
            $potentialContext = array_slice($currentAddressParts, 0, $nameStartIndex);
            $potentialContext = array_filter($potentialContext, fn($t) => trim($t) !== '');
            
            if (!empty($potentialContext)) {
                foreach ($potentialContext as $part) {
                    if (in_array(mb_strtolower(rtrim($part, '.'), 'UTF-8'), array_merge($baseMajorLocalityTypes, $districtTypes, $subLocalityTypes))) {
                        $localityContextParts = $potentialContext;
                        $localityContextIsValid = true;
                        break;
                    }
                }
            }
        }

        // Обновление флагов состояния
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

/**
 * Собирает и очищает финальную строку адреса из префикса и массива частей.
 *
 * @param string $prefix Общий префикс для адреса (например, регион).
 * @param array $parts Массив токенов, составляющих основную часть адреса.
 * @return string Очищенная и собранная строка адреса.
 */
function buildAddress(string $prefix, array $parts): string
{
    $address = trim($prefix . ' ' . implode(' ', $parts));
    // Нормализация пробелов и запятых
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
