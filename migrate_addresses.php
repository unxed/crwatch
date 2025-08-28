<?php

define("MAX_ITERATIONS", 4000); // Максимально допустимое количество итераций разбора адреса

require_once 'config.php';

function logMigrate(string $message): void {
    echo $message . "\n";
    file_put_contents(MIGRATE_LOG_FILE, date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}

// =============================================================================
// АЛГОРИТМ РАЗДЕЛЕНИЯ АДРЕСОВ
// =============================================================================

const LEVEL_COUNTRY = 0;
const LEVEL_REGION = 1;
const LEVEL_DISTRICT = 2;
const LEVEL_CITY = 3;
const LEVEL_STREET = 4;
const LEVEL_HOUSE = 5;

// Определяем константу для порога "мусорного" чанка, если она еще не определена.
if (!defined('JUNK_CHUNK_LENGTH_THRESHOLD')) {
    define("JUNK_CHUNK_LENGTH_THRESHOLD", 70);
}

// --- ФУНКЦИИ-ПОМОЩНИКИ, НЕОБХОДИМЫЕ ДЛЯ РАБОТЫ ПАРСЕРА ---

function isSingleWord(string $str): bool
{
    return mb_strpos(trim($str), ' ') === false;
}

function containsDigits(string $str): bool
{
    return strcspn($str, '0123456789') !== strlen($str);
}

function findNumericListMarker(string $chunk): ?array
{
    $len = mb_strlen($chunk);
    for ($i = 1; $i < $len; $i++) {
        if (mb_substr($chunk, $i, 1) === ')') {
            if (is_numeric(mb_substr($chunk, $i - 1, 1))) {
                $startPos = $i - 1;
                while ($startPos > 0 && is_numeric(mb_substr($chunk, $startPos - 1, 1))) {
                    $startPos--;
                }
                if ($startPos > 0 && mb_substr($chunk, $startPos - 1, 1) === ' ') {
                    return ['pos' => $startPos, 'len' => $i - $startPos + 1];
                }
            }
        }
    }
    return null;
}

function replaceLastWord(string $haystack, string $newWord): string
{
    $lastSpacePos = mb_strrpos($haystack, ' ');
    if ($lastSpacePos === false) {
        return $newWord;
    }
    $base = mb_substr($haystack, 0, $lastSpacePos);
    return $base . ' ' . $newWord;
}

function removeLeadingNoise(string $chunk): string
{
    $originalChunk = $chunk;
    $cursor = 0;
    $len = mb_strlen($chunk);
    while ($cursor < $len && mb_substr($chunk, $cursor, 1) === ' ') $cursor++;
    $digitStart = $cursor;
    while ($cursor < $len && is_numeric(mb_substr($chunk, $cursor, 1))) $cursor++;
    if ($cursor > $digitStart) {
        if ($cursor < $len && in_array(mb_substr($chunk, $cursor, 1), ['.', ')'])) {
            $cursor++;
            while ($cursor < $len && mb_substr($chunk, $cursor, 1) === ' ') $cursor++;
            $result = mb_substr($chunk, $cursor);
            if ($originalChunk !== '' && $result === '') {
                return $originalChunk;
            }
            return $result;
        }
    }
    return $chunk;
}

function containsLetters(string $str): bool
{
    return preg_match('/\p{L}/u', $str) > 0;
}

function findMarkerInChunk(string $chunk, array $markers, array $ambiguousMarkers, array $currentAddressParts, int $offset = 0): ?array
{
    $bestMatch = null;

    foreach ($markers as $marker => $level) {
        $pos = mb_stripos($chunk, $marker, $offset);
        if ($pos !== false) {
            $markerLen = mb_strlen($marker);
            $before = ($pos > 0) ? mb_substr($chunk, $pos - 1, 1) : ' ';
            $isAfterOk = false;
            if ($pos + $markerLen >= mb_strlen($chunk)) {
                $isAfterOk = true;
            } else {
                $after = mb_substr($chunk, $pos + $markerLen, 1);
                if (in_array($after, [' ', '.', ')', '/']) || is_numeric($after)) {
                    $isAfterOk = true;
                }
            }
            
            if (in_array($before, [' ', '(', "\n"]) && $isAfterOk) {
                if ($bestMatch === null || $pos < $bestMatch['pos']) {
                    $bestMatch = ['marker' => $marker, 'level' => $level, 'pos' => $pos, 'len' => $markerLen];
                }
            }
        }
    }

    if ($bestMatch !== null) {
        $marker = $bestMatch['marker'];
        $pos = $bestMatch['pos'];

        if ($marker === 'г' || $marker === 'г.') {
            if ($pos > 0) {
                $chunkBeforeMarker = trim(mb_substr($chunk, 0, $pos));
                if (!empty($chunkBeforeMarker) && is_numeric(mb_substr($chunkBeforeMarker, -1))) {
                    return findMarkerInChunk($chunk, $markers, $ambiguousMarkers, $currentAddressParts, $pos + 1);
                }
            }
        }

        if ($marker === 'п.' || $marker === 'п') {
            $contentAfter = trim(mb_substr($chunk, $pos + $bestMatch['len']));
            if (!empty($contentAfter) && is_numeric(mb_substr($contentAfter, 0, 1))) {
                return findMarkerInChunk($chunk, $markers, $ambiguousMarkers, $currentAddressParts, $pos + 1);
            }
            $lastLevel = empty($currentAddressParts) ? -1 : array_key_last($currentAddressParts);
            if ($lastLevel === LEVEL_STREET) {
                return findMarkerInChunk($chunk, $markers, $ambiguousMarkers, $currentAddressParts, $pos + 1);
            }
        }

        if ($marker === 'д.') {
            $hasLocationContext = isset($currentAddressParts[LEVEL_STREET]) || isset($currentAddressParts[LEVEL_CITY]);
            if ($hasLocationContext && containsDigits($chunk)) {
                 $bestMatch['level'] = LEVEL_HOUSE;
            } else {
                 $bestMatch['level'] = LEVEL_CITY;
            }
        }
        return $bestMatch;
    }

    return null;
}


function containsHouseKeyword(string $str): bool
{
    $houseKeywords = ['литер', 'корп', 'строение', 'стр', 'к.'];
    foreach ($houseKeywords as $keyword) {
        if (mb_stripos($str, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

function isHouseComponent(string $component, array $markers): bool
{
    foreach ($markers as $marker => $level) {
        if ($level < LEVEL_HOUSE && mb_stripos($component, $marker) !== false) {
            return false;
        }
    }

    if (containsHouseKeyword($component)) return true;
    
    $hasDigits = containsDigits($component);
    
    if (!$hasDigits && containsLetters($component)) {
        return mb_strlen(trim($component)) === 1;
    }
    return $hasDigits || containsLetters($component);
}

function isClearlyHouseComponent(string $component, array $markers): bool
{
    foreach ($markers as $marker => $level) {
        if ($level === LEVEL_HOUSE) {
            if (mb_stripos($component, $marker) !== false) {
                return true;
            }
        }
    }
    return false;
}

function isJunkInput(array $chunks, array $markers, array $ambiguousMarkers): bool
{
    foreach ($chunks as $chunk) {
        if (mb_strlen($chunk) > JUNK_CHUNK_LENGTH_THRESHOLD) {
            if (findMarkerInChunk($chunk, $markers, $ambiguousMarkers, []) === null) {
                return true;
            }
        }
    }
    return false;
}

function processFusedChunk(string $chunk, array $markers, array $ambiguousMarkers): array
{
    $subChunks = [];
    $lastCutPos = 0;

    $firstMarkerInfo = findMarkerInChunk($chunk, $markers, $ambiguousMarkers, [], 0);
    if ($firstMarkerInfo === null) {
        return [$chunk];
    }

    $currentTextBuffer = trim(mb_substr($chunk, 0, $firstMarkerInfo['pos']));
    $lastMarkerInfo = $firstMarkerInfo;

    while (true) {
        $nextMarkerInfo = findMarkerInChunk($chunk, $markers, $ambiguousMarkers, [], $lastMarkerInfo['pos'] + 1);

        if ($nextMarkerInfo) {
            $textBetween = trim(mb_substr($chunk, $lastMarkerInfo['pos'] + $lastMarkerInfo['len'], $nextMarkerInfo['pos'] - ($lastMarkerInfo['pos'] + $lastMarkerInfo['len'])));
            $subChunks[] = trim($currentTextBuffer . ' ' . $lastMarkerInfo['marker'] . ' ' . $textBetween);

            $currentTextBuffer = '';
            $lastMarkerInfo = $nextMarkerInfo;
        } else {
            $remainingText = trim(mb_substr($chunk, $lastMarkerInfo['pos'] + $lastMarkerInfo['len']));
            $subChunks[] = trim($currentTextBuffer . ' ' . $lastMarkerInfo['marker'] . ' ' . $remainingText);
            break;
        }
    }

    return $subChunks;
}

function isFusedChunk(string $chunk, array $markers, array $ambiguousMarkers): bool
{
    if (mb_strlen($chunk) < 25) {
        return false;
    }

    $markerCount = 0;
    $offset = 0;
    while (($markerInfo = findMarkerInChunk($chunk, $markers, $ambiguousMarkers, [], $offset)) !== null) {
        $markerCount++;
        $offset = $markerInfo['pos'] + $markerInfo['len'];
    }

    return $markerCount > 2;
}

/**
 * Разделяет текстовый блок, содержащий один или несколько адресов, на массив отдельных адресов.
 * Функция спроектирована для работы с "грязными" данными без использования регулярных выражений и токенизации.
 * 
 * @param string $addressBlock Входная строка с одним или несколькими адресами.
 * @return array Массив извлеченных адресов. Если не удалось извлечь более одного адреса с указанием дома,
 *               возвращает массив, содержащий исходную строку.
 */
function splitAddresses(string $addressBlock): array
{
    // --- Конфигурация парсера ---
    $markersConfig = [
        'Российская Федерация' => LEVEL_COUNTRY,
        'обл' => LEVEL_REGION, 'область' => LEVEL_REGION, 'край' => LEVEL_REGION, 'Респ' => LEVEL_REGION, 'республика' => LEVEL_REGION, 'АО' => LEVEL_REGION,
        'р-н' => LEVEL_DISTRICT, 'район' => LEVEL_DISTRICT, 'ГО:' => LEVEL_DISTRICT, 'МР:' => LEVEL_DISTRICT, 'округ' => LEVEL_DISTRICT,
        'г.' => LEVEL_CITY, 'г' => LEVEL_CITY, 'город' => LEVEL_CITY, 'с.' => LEVEL_CITY, 'село' => LEVEL_CITY, 'п.' => LEVEL_CITY, 'п' => LEVEL_CITY, 'пос.' => LEVEL_CITY, 'поселок' => LEVEL_CITY, 'рп.' => LEVEL_CITY, 'рп' => LEVEL_CITY, 'р. п.' => LEVEL_CITY, 'р.п.' => LEVEL_CITY, 'д.' => LEVEL_CITY,
        'квл' => LEVEL_STREET, 'наб.кан.' => LEVEL_STREET, 'кан.' => LEVEL_STREET, 'ул.' => LEVEL_STREET, 'ул' => LEVEL_STREET, 'улица' => LEVEL_STREET, 'пр-т' => LEVEL_STREET, 'пр.' => LEVEL_STREET, 'просп' => LEVEL_STREET, 'просп.' => LEVEL_STREET, 'пр-кт' => LEVEL_STREET, 'проспект' => LEVEL_STREET, 'бул' => LEVEL_STREET, 'бул.' => LEVEL_STREET, 'б-р' => LEVEL_STREET, 'б-р.' => LEVEL_STREET, 'бульвар' => LEVEL_STREET, 'пер.' => LEVEL_STREET, 'переулок' => LEVEL_STREET, 'наб.' => LEVEL_STREET, 'набережная' => LEVEL_STREET, 'ш.' => LEVEL_STREET, 'шоссе' => LEVEL_STREET, 'пр-д' => LEVEL_STREET, 'проезд' => LEVEL_STREET, 'линия' => LEVEL_STREET, 'дорога' => LEVEL_STREET, 'мкр.' => LEVEL_STREET, 'мкр' => LEVEL_STREET, 'мкрн.' => LEVEL_STREET, 'мкрн' => LEVEL_STREET, 'микрорайон' => LEVEL_STREET,
        'д.' => LEVEL_HOUSE, 'дом' => LEVEL_HOUSE, 'корп.' => LEVEL_HOUSE, 'корп' => LEVEL_HOUSE, 'к.' => LEVEL_HOUSE, 'корпус' => LEVEL_HOUSE, 'стр.' => LEVEL_HOUSE, 'строение' => LEVEL_HOUSE, 'литера' => LEVEL_HOUSE, 'лит.' => LEVEL_HOUSE,
    ];
    uksort($markersConfig, function ($a, $b) { return mb_strlen($b) - mb_strlen($a); });
    $markers = $markersConfig;
    $ambiguousMarkers = [];

    $markerlessKeywords = ['Санкт-Петербург' => LEVEL_REGION, 'Москва' => LEVEL_REGION, 'Севастополь' => LEVEL_REGION];

    // --- Переменные состояния парсера ---
    $results = [];
    $currentAddressParts = [];
    $lastLevel = -1;
    $foundAtLeastOneHouse = false;
    $lastHouseComponent = null;
    $inParenthesesMode = false;
    $iterations = 0;
    $infiniteLoopDetected = false;

    // --- 1. Начальное формирование очереди обработки ---
    $cursor = 0;
    $length = mb_strlen($addressBlock);
    $delimiters = [',', ';', "\n"];
    $processingQueue = [];

    while ($cursor < $length) {
        $nextPos = $length;
        foreach ($delimiters as $d) {
            $pos = mb_strpos($addressBlock, $d, $cursor);
            if ($pos !== false) $nextPos = min($nextPos, $pos);
        }
        $chunk = trim(mb_substr($addressBlock, $cursor, $nextPos - $cursor));
        if ($chunk !== '') $processingQueue[] = $chunk;
        $cursor = $nextPos + 1; 
    }

    if (isJunkInput($processingQueue, $markers, $ambiguousMarkers)) {
        return [$addressBlock];
    }

    // --- 2. Основной цикл обработки очереди ---
    while (!empty($processingQueue)) {
        $iterations++;
        if ($iterations > MAX_ITERATIONS) {
            $infiniteLoopDetected = true;
            break;
        }

        $chunk = array_shift($processingQueue);

        if (isFusedChunk($chunk, $markers, $ambiguousMarkers)) {
            $subChunks = processFusedChunk($chunk, $markers, $ambiguousMarkers);
            if (!empty($subChunks)) {
                 array_unshift($processingQueue, ...$subChunks);
                 continue;
            }
        }

        if ($inParenthesesMode) {
            $lastKey = !empty($currentAddressParts) ? array_key_last($currentAddressParts) : null;
            if ($lastKey !== null) {
                $currentAddressParts[$lastKey] .= '; ' . $chunk;
            }
            if (mb_strpos($chunk, ')') !== false) {
                $inParenthesesMode = false;
                if($lastKey === LEVEL_HOUSE) $lastHouseComponent = $currentAddressParts[$lastKey];
            }
            continue;
        }

        if (($markerInfo = findNumericListMarker($chunk)) !== null) {
            $part1 = trim(mb_substr($chunk, 0, $markerInfo['pos']));
            $part2 = trim(mb_substr($chunk, $markerInfo['pos'] + $markerInfo['len']));
            if ($part2 !== '') array_unshift($processingQueue, $part2);
            if ($part1 !== '') array_unshift($processingQueue, $part1);
            continue;
        }

        if (($colonPos = mb_strpos($chunk, ':')) !== false) {
            $chunk = trim(mb_substr($chunk, $colonPos + 1));
            if (empty($chunk)) continue;
        }

        $cleanComponent = removeLeadingNoise($chunk);

        $markerInfo = findMarkerInChunk($cleanComponent, $markers, $ambiguousMarkers, $currentAddressParts);
        $pos = $markerInfo['pos'] ?? -1;
        if ($pos > 0) {
            $part1 = trim(mb_substr($cleanComponent, 0, $pos));
            if ($markerInfo['level'] < LEVEL_STREET && isHouseComponent($part1, $markers)) {
                $part2 = trim(mb_substr($cleanComponent, $pos));
                array_unshift($processingQueue, $part2);
                array_unshift($processingQueue, $part1);
                continue;
            }
        }

        // --- 3. Определение уровня и типа компонента ---
        $currentLevel = null;
        foreach ($markerlessKeywords as $keyword => $level) {
            if (mb_strtolower($cleanComponent) == mb_strtolower($keyword)) {
                $currentLevel = $level;
                break;
            }
        }
        if ($currentLevel === null) {
            $markerInfo = findMarkerInChunk($cleanComponent, $markers, $ambiguousMarkers, $currentAddressParts);
            if ($markerInfo) {
                $currentLevel = $markerInfo['level'];
            } else {
                $combined = false;
                if (!empty($processingQueue)) {
                    $nextChunk = $processingQueue[0];
                    $nextChunkClean = trim($nextChunk, ' .');
                    foreach ($markers as $marker => $level) {
                        if (trim($marker, ' .') === $nextChunkClean) {
                            $shouldCombine = false;
                            if ($level >= $lastLevel) {
                                $shouldCombine = true;
                            } else if (isSingleWord($cleanComponent) && !containsDigits($cleanComponent)) {
                                $shouldCombine = true;
                            }
                            if ($shouldCombine) {
                                $cleanComponent = $cleanComponent . ' ' . array_shift($processingQueue);
                                $currentLevel = $level;
                                $combined = true;
                                break;
                            }
                        }
                    }
                }

                if (!$combined) {
                    if (isset($currentAddressParts[LEVEL_CITY]) && $lastLevel >= LEVEL_CITY && !isHouseComponent($cleanComponent, $markers)) {
                        $currentAddressParts[LEVEL_CITY] .= ', ' . $cleanComponent;
                        continue;
                    }
                    else if (!empty($processingQueue) && isClearlyHouseComponent($processingQueue[0], $markers) && !containsDigits($cleanComponent)) {
                        $currentLevel = LEVEL_STREET;
                    }
                    else if ($lastLevel === LEVEL_HOUSE && mb_strlen($cleanComponent) <= 2) {
                        $currentLevel = LEVEL_HOUSE;
                    }
                    else if (isHouseComponent($cleanComponent, $markers)) {
                        $currentLevel = LEVEL_HOUSE;
                    } else {
                        continue;
                    }
                }
            }
        }
        
        if ($currentLevel === LEVEL_HOUSE) $foundAtLeastOneHouse = true;

        // --- 4. Принятие решения: новый адрес или часть текущего? ---
        $isNewAddress = ($lastLevel !== -1 && $currentLevel <= $lastLevel);
        $newHousePartFromHanging = null; 

        if ($isNewAddress && $currentLevel == LEVEL_HOUSE && $lastLevel == LEVEL_HOUSE) {
            $isSupplementToHouse = true;
            $markerInfo = findMarkerInChunk($cleanComponent, $markers, $ambiguousMarkers, $currentAddressParts);
            if ($markerInfo && in_array($markerInfo['marker'], ['д.', 'дом'])) {
                $isSupplementToHouse = false;
            }
            if ($isSupplementToHouse) {
                if (containsHouseKeyword($cleanComponent)) {
                    $isNewAddress = false;
                } else {
                    if ($lastHouseComponent) {
                         $newHousePartFromHanging = replaceLastWord($lastHouseComponent, $cleanComponent);
                    }
                }
            }
        }

        // --- 5. Сборка адреса ---
        if ($isNewAddress) {
            $builtAddress = '';
            if (!empty($currentAddressParts) && (isset($currentAddressParts[LEVEL_STREET]) || isset($currentAddressParts[LEVEL_HOUSE]))) {
                 ksort($currentAddressParts);
                 $builtAddress = implode(', ', $currentAddressParts);
            }
            if ($builtAddress && (empty($results) || end($results) !== $builtAddress)) {
                 $results[] = $builtAddress;
            }

            $newParts = [];
            $cutOffLevel = $currentLevel;

            if ($currentLevel == LEVEL_STREET
                && isset($currentAddressParts[LEVEL_REGION])
                && isset($currentAddressParts[LEVEL_CITY]))
            {
                $regionComponent = $currentAddressParts[LEVEL_REGION];
                if (array_key_exists($regionComponent, $markerlessKeywords)) {
                    $cutOffLevel = LEVEL_DISTRICT;
                }
            } else if ($currentLevel == LEVEL_HOUSE && $lastLevel == LEVEL_HOUSE) {
                 $cutOffLevel = LEVEL_HOUSE;
            }

            foreach ($currentAddressParts as $lvl => $part) {
                if ($lvl < $cutOffLevel) {
                    $newParts[$lvl] = $part;
                }
            }
            $currentAddressParts = $newParts;
        }

        if ($newHousePartFromHanging !== null) {
            $currentAddressParts[LEVEL_HOUSE] = $newHousePartFromHanging;
            $lastHouseComponent = $newHousePartFromHanging;
        } else if ($currentLevel == LEVEL_HOUSE && isset($currentAddressParts[LEVEL_HOUSE]) && !$isNewAddress) {
            $currentAddressParts[LEVEL_HOUSE] .= ', ' . $cleanComponent;
            $lastHouseComponent = $currentAddressParts[LEVEL_HOUSE];
        } else {
            foreach($currentAddressParts as $lvl => $part) {
                if ($lvl >= $currentLevel) unset($currentAddressParts[$lvl]);
            }
            $currentAddressParts[$currentLevel] = $cleanComponent;
            if ($currentLevel === LEVEL_HOUSE) {
                $lastHouseComponent = $cleanComponent;
            }
        }

        $lastLevel = $currentLevel;
        ksort($currentAddressParts);

        // --- 6. Пост-обработка и управление состояниями ---
        if ($currentLevel === LEVEL_STREET) {
            $houseMarkersForSplit = [' д.', ' дом '];
            foreach ($houseMarkersForSplit as $houseMarker) {
                if (($housePos = mb_stripos($currentAddressParts[LEVEL_STREET], $houseMarker)) !== false) {
                    $streetPart = trim(mb_substr($currentAddressParts[LEVEL_STREET], 0, $housePos));
                    $housePart = trim(mb_substr($currentAddressParts[LEVEL_STREET], $housePos));
                    $currentAddressParts[LEVEL_STREET] = $streetPart;
                    array_unshift($processingQueue, $housePart);
                    break;
                }
            }
        } else if ($currentLevel === LEVEL_HOUSE) {
            $streetMarkersForSplit = [' ул.', ' ул ', ' пр-т', ' пр ', ' б-р', ' пер '];
             foreach ($streetMarkersForSplit as $streetMarker) {
                $streetPos = mb_stripos($currentAddressParts[LEVEL_HOUSE], $streetMarker);
                if ($streetPos !== false) {
                    $housePart = trim(mb_substr($currentAddressParts[LEVEL_HOUSE], 0, $streetPos));
                    $streetPart = trim(mb_substr($currentAddressParts[LEVEL_HOUSE], $streetPos));
                    $currentAddressParts[LEVEL_HOUSE] = $housePart;
                    array_unshift($processingQueue, $streetPart);
                    $lastLevel = LEVEL_HOUSE;
                    break;
                }
            }
        }

        if (mb_strpos($cleanComponent, '(') !== false && mb_strpos($cleanComponent, ')') === false) {
            $inParenthesesMode = true;
        }
    }

    // --- 7. Финализация ---
    if (!empty($currentAddressParts) && (isset($currentAddressParts[LEVEL_STREET]) || isset($currentAddressParts[LEVEL_HOUSE]))) {
        ksort($currentAddressParts);
        $results[] = implode(', ', $currentAddressParts);
    }

    $finalResults = [];
    foreach ($results as $address) {
        $junkKeywords = ['объектами культурного наследия', 'технического состояния', 'Место выполнения работ'];
        $isJunk = false;
        foreach ($junkKeywords as $keyword) {
            if (mb_stristr($address, $keyword)) {
                $isJunk = true; break;
            }
        }
        if (!$isJunk) $finalResults[] = $address;
    }

    $finalResults = array_unique($finalResults);
    $finalResults = array_values($finalResults);

    // --- 8. Финальная проверка и возврат результата ---
    if ($infiniteLoopDetected) {
        return [$addressBlock];
    }

    if (count($finalResults) <= 1 || !$foundAtLeastOneHouse) {
        return [$addressBlock];
    }

    return $finalResults;
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
    $stmt = $pdo->query("SELECT id, work_location FROM procurements WHERE work_location IS NOT NULL AND work_location != '' ORDER BY id");
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
                    $address = preg_replace('/Российская Федерация,?\s?/', '', $address);

                    $insertStmt = $pdo->prepare("INSERT INTO procurement_locations (procurement_id, address) VALUES (:proc_id, :addr)");
                    $insertStmt->execute([':proc_id' => $proc['id'], ':addr' => $address]);
                    $totalAddresses++;
                }
            }
            $processed++;
            printf("\rОбработано закупок: %d/%d | Перенесено адресов: %d | last id: %d", $processed, $total, $totalAddresses, $proc['id']);
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