<?php

define("MAX_ITERATIONS", 4000); // Максимально допустимое количество итераций разбора адреса
define("JUNK_CHUNK_LENGTH_THRESHOLD", 70); // Порог длины чанка, после которого он может считаться "мусором"

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

// --- ФУНКЦИИ-ПОМОЩНИКИ, НЕОБХОДИМЫЕ ДЛЯ РАБОТЫ ПАРСЕРА ---

function isSingleWord(string $str): bool
{
    return mb_strpos(trim($str), ' ') === false;
}

function containsDigits(string $str): bool
{
    return strcspn($str, '0123456789') !== strlen($str);
}

function findNumericListMarker(string $chunk): ?int
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
                    return $startPos;
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

function findMarkerInChunk(string $chunk, array $markers, array $ambiguousMarkers, array $currentAddressParts): ?array
{
    // --- НАЧАЛО СВЕРХ-СПЕЦИФИЧНОГО ПАТЧА ---
    // Проверяем, не начинается ли чанк с явного маркера улицы.
    // Это нужно, чтобы перехватить кейсы "ул. Название д. Номер" до того, как основной цикл найдет 'д.'
    foreach ($markers as $marker => $level) {
        if ($level === LEVEL_STREET) {
            // mb_stripos === 0 проверяет, что маркер в самом начале
            if (mb_stripos(trim($chunk), $marker) === 0) {
                 $markerLen = mb_strlen($marker);
                 $afterChar = ($markerLen < mb_strlen($chunk)) ? mb_substr($chunk, $markerLen, 1) : ' ';
                 // Убедимся, что это целое слово
                 if (in_array($afterChar, [' ', '.'])) {
                     return ['marker' => $marker, 'level' => LEVEL_STREET, 'pos' => 0];
                 }
            }
        }
    }
    // --- КОНЕЦ ПАТЧА ---

    // Основной, стандартный цикл поиска
    foreach ($markers as $marker => $level) {
        $pos = mb_stripos($chunk, $marker);
        if ($pos !== false) {
            $markerLen = mb_strlen($marker);
            $before = ($pos > 0) ? mb_substr($chunk, $pos - 1, 1) : ' ';
            $isAfterOk = false;
            if ($pos + $markerLen >= mb_strlen($chunk)) {
                $isAfterOk = true; // Маркер в самом конце строки
            } else {
                $after = mb_substr($chunk, $pos + $markerLen, 1);
                if (in_array($after, [' ', '.', ')', '/']) || is_numeric($after)) {
                    $isAfterOk = true; // После маркера пробел, пунктуация или цифра
                }
            }

            if (in_array($before, [' ', '(']) && $isAfterOk) {
                if (($marker === 'г' || $marker === 'г.') && $pos > 0) {
                    $chunkBeforeMarker = trim(mb_substr($chunk, 0, $pos));
                    if (!empty($chunkBeforeMarker) && is_numeric(mb_substr($chunkBeforeMarker, -1))) {
                        $contentAfterMarker = trim(mb_substr($chunk, $pos + $markerLen));
                        if (empty($contentAfterMarker) || !containsLetters($contentAfterMarker)) {
                            continue;
                        }
                    }
                }

                if (($marker === 'п.' || $marker === 'п')) {
                    $contentAfter = trim(mb_substr($chunk, $pos + $markerLen));
                    if (!empty($contentAfter) && is_numeric(mb_substr($contentAfter, 0, 1))) {
                        continue;
                    }
                }

                $currentLevel = $level;
                if ($marker === 'д.') {
                    $hasLocationContext = isset($currentAddressParts[LEVEL_STREET]) || isset($currentAddressParts[LEVEL_CITY]);
                    $chunkWithoutMarker = trim(str_ireplace('д.', '', $chunk));
                    if ($hasLocationContext && containsDigits($chunkWithoutMarker)) {
                         $currentLevel = LEVEL_HOUSE;
                    } else {
                         $currentLevel = LEVEL_CITY;
                    }
                }
                return ['marker' => $marker, 'level' => $currentLevel, 'pos' => $pos];
            }
        }
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

function isHouseComponent(string $component): bool
{
    $junkHouseKeywords = ['позиция', 'объект', 'многоквартирные'];
     foreach ($junkHouseKeywords as $keyword) {
        if (mb_stripos($component, $keyword) !== false) {
            return false;
        }
    }

    if (containsHouseKeyword($component)) return true;
    
    $hasDigits = containsDigits($component);
    
    if (!$hasDigits && containsLetters($component)) {
        if (mb_strlen(trim($component)) === 1) {
            return true;
        }
        return false;
    }

    return $hasDigits || containsLetters($component);
}

function isClearlyHouseComponent(string $component, array $markers): bool
{
    foreach ($markers as $marker => $level) {
        if ($level === LEVEL_HOUSE) {
            $pos = mb_stripos($component, $marker);
            if ($pos !== false) {
                $before = ($pos > 0) ? mb_substr($component, $pos - 1, 1) : ' ';

                $markerLen = mb_strlen($marker);
                $isAfterOk = false;
                if ($pos + $markerLen >= mb_strlen($component)) {
                    $isAfterOk = true;
                } else {
                    $after = mb_substr($component, $pos + $markerLen, 1);
                    if (in_array($after, [' ', '.', ')', '/']) || is_numeric($after)) {
                        $isAfterOk = true;
                    }
                }

                if (in_array($before, [' ', '(']) && $isAfterOk) {
                    return true;
                }
            }
        }
    }
    return false;
}

function isWordPartOfHouseComponent(string $word): bool
{
    $cleanWord = trim(mb_strtolower($word), '.,:');

    if ($cleanWord === '') return true;

    $houseKeywords = ['дом', 'д', 'литера', 'лит', 'корпус', 'корп', 'к', 'строение', 'стр'];
    if (in_array($cleanWord, $houseKeywords)) {
        return true;
    }

    if (containsDigits($cleanWord)) {
        return true;
    }

    if (mb_strlen(preg_replace('/[^[:alpha:]]/u', '', $cleanWord)) === 1) {
         return true;
    }

    return false;
}

function isPurelyNumeric(string $str): bool {
    $cleanStr = trim($str);
    return is_numeric(str_replace('/', '', $cleanStr));
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

function splitAddresses(string $addressBlock): array
{
    // --- Конфигурация парсера ---
    $markersConfig = [
        'Российская Федерация' => LEVEL_COUNTRY,
        'обл' => LEVEL_REGION, 'область' => LEVEL_REGION, 'край' => LEVEL_REGION, 'Респ' => LEVEL_REGION, 'республика' => LEVEL_REGION, 'АО' => LEVEL_REGION,
        'р-он' => LEVEL_DISTRICT, 'р-н' => LEVEL_DISTRICT, 'район' => LEVEL_DISTRICT, 'ГО:' => LEVEL_DISTRICT, 'МР:' => LEVEL_DISTRICT, 'округ' => LEVEL_DISTRICT,
        'г.' => LEVEL_CITY, 'г' => LEVEL_CITY, 'город' => LEVEL_CITY, 'с.' => LEVEL_CITY, 'село' => LEVEL_CITY, 'п.' => LEVEL_CITY, 'п' => LEVEL_CITY, 'пос.' => LEVEL_CITY, 'поселок' => LEVEL_CITY, 'рп.' => LEVEL_CITY, 'рп' => LEVEL_CITY, 'р. п.' => LEVEL_CITY, 'р.п.' => LEVEL_CITY, 'д.' => LEVEL_CITY,
        'квл' => LEVEL_STREET, 'наб.кан.' => LEVEL_STREET, 'кан.' => LEVEL_STREET, 'ул.' => LEVEL_STREET, 'ул' => LEVEL_STREET, 'улица' => LEVEL_STREET, 'пр-т' => LEVEL_STREET, 'пр.' => LEVEL_STREET, 'просп' => LEVEL_STREET, 'просп.' => LEVEL_STREET, 'пр-кт' => LEVEL_STREET, 'проспект' => LEVEL_STREET, 'бул' => LEVEL_STREET, 'бул.' => LEVEL_STREET, 'б-р' => LEVEL_STREET, 'б-р.' => LEVEL_STREET, 'бульв' => LEVEL_STREET, 'бульв.' => LEVEL_STREET, 'бульвар' => LEVEL_STREET, 'пер.' => LEVEL_STREET, 'переулок' => LEVEL_STREET, 'наб.' => LEVEL_STREET, 'набережная' => LEVEL_STREET, 'ш.' => LEVEL_STREET, 'шоссе' => LEVEL_STREET, 'пр-д' => LEVEL_STREET, 'проезд' => LEVEL_STREET, 'линия' => LEVEL_STREET, 'дорога' => LEVEL_STREET, 'мкр.' => LEVEL_STREET, 'мкр' => LEVEL_STREET, 'мкрн.' => LEVEL_STREET, 'мкрн' => LEVEL_STREET, 'микрорайон' => LEVEL_STREET,
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

        if (!empty($currentAddressParts)) {
            foreach ($currentAddressParts as $part) {
                $part = trim($part);
                $partLen = mb_strlen($part);
                if (mb_stripos($chunk, $part) === 0) {
                    $isWholeWordMatch = (mb_strlen($chunk) === $partLen) || (mb_substr($chunk, $partLen, 1) === ' ');
                    if ($isWholeWordMatch) {
                        $cleanerChunk = trim(mb_substr($chunk, $partLen));
                        if ($cleanerChunk !== '') {
                            $chunk = $cleanerChunk;
                            break;
                        }
                    }
                }
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

        if (($numericMarkerPos = findNumericListMarker($chunk)) !== null) {
            $part1 = trim(mb_substr($chunk, 0, $numericMarkerPos));
            $part2 = trim(mb_substr($chunk, $numericMarkerPos));
            if ($part2 !== '') array_unshift($processingQueue, $part2);
            if ($part1 !== '') array_unshift($processingQueue, $part1);
            continue;
        }

        if (($colonPos = mb_strpos($chunk, ':')) !== false) {
            $chunk = trim(mb_substr($chunk, $colonPos + 1));
            if (empty($chunk)) continue;
        }

        $tempChunk = $chunk;
        $offset = 0;
        $markersInChunk = [];
        $tempMarkersForCounting = $markers;
        unset($tempMarkersForCounting['п.']);
        unset($tempMarkersForCounting['п']);
        unset($tempMarkersForCounting['г']);

        while (true) {
            $markerInfo = findMarkerInChunk($tempChunk, $tempMarkersForCounting, $ambiguousMarkers, $currentAddressParts);
            if ($markerInfo === null) break;

            $markerInfo['real_pos'] = $offset + $markerInfo['pos'];
            $markersInChunk[] = $markerInfo;

            $newOffset = $markerInfo['pos'] + mb_strlen($markerInfo['marker']);
            $offset += $newOffset;
            $tempChunk = mb_substr($tempChunk, $newOffset);

            if (empty($tempChunk)) break;
        }

        if (count($markersInChunk) >= 3) {
            $splitPos = $markersInChunk[1]['real_pos'];
            $part1 = trim(mb_substr($chunk, 0, $splitPos));
            $part2 = trim(mb_substr($chunk, $splitPos));
            if ($part1 !== '' && $part2 !== '') {
                array_unshift($processingQueue, $part2);
                array_unshift($processingQueue, $part1);
                continue;
            }
        }

        $cleanComponent = removeLeadingNoise($chunk);

        $markerInfo = findMarkerInChunk($cleanComponent, $markers, $ambiguousMarkers, $currentAddressParts);
        $pos = $markerInfo['pos'] ?? -1;
        if ($pos > 0) {
            $part1 = trim(mb_substr($cleanComponent, 0, $pos));
            $shouldSplit = false;

            if ($markerInfo['level'] < LEVEL_STREET && isHouseComponent($part1)) {
                $shouldSplit = true;
            }
            else if ($markerInfo['level'] === LEVEL_STREET && isClearlyHouseComponent($part1, $markers)) {
                $shouldSplit = true;
            }
            else if ($markerInfo['level'] === LEVEL_STREET) {
                $part1MarkerInfo = findMarkerInChunk($part1, $markers, $ambiguousMarkers, []);
                if ($part1MarkerInfo !== null && $part1MarkerInfo['level'] <= LEVEL_CITY) {
                     $shouldSplit = true;
                }
            }

            if ($shouldSplit) {
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
                    if (isset($currentAddressParts[LEVEL_CITY]) && $lastLevel >= LEVEL_CITY && !isHouseComponent($cleanComponent)) {
                        $currentAddressParts[LEVEL_CITY] .= ', ' . $cleanComponent;
                        continue;
                    }
                    else if (!empty($processingQueue) && isClearlyHouseComponent($processingQueue[0], $markers) && !containsDigits($cleanComponent)) {
                        $currentLevel = LEVEL_STREET;
                    }
                    else if ($lastLevel === LEVEL_HOUSE && mb_strlen($cleanComponent) <= 2) {
                        $currentLevel = LEVEL_HOUSE;
                    }
                    else if (isHouseComponent($cleanComponent)) {
                        $currentLevel = LEVEL_HOUSE;
                    } else {
                        if (($lastLevel === LEVEL_REGION || $lastLevel === LEVEL_DISTRICT) && !containsDigits($cleanComponent) && !containsHouseKeyword($cleanComponent)) {
                            $currentLevel = LEVEL_CITY;
                        } else {
                            continue;
                        }
                    }
                }
            }
        }

        if ($currentLevel === LEVEL_HOUSE) $foundAtLeastOneHouse = true;

        // --- 4. Принятие решения: новый адрес или часть текущего? ---
        $isNewAddress = false;

        if ($lastLevel !== -1 && $currentLevel < $lastLevel) {
            $isNewAddress = true;
        } else if ($lastLevel !== -1 && $currentLevel === $lastLevel && !in_array($currentLevel, [LEVEL_HOUSE, LEVEL_CITY])) {
             $isNewAddress = true;
        }

        if ($currentLevel === LEVEL_HOUSE && $lastLevel === LEVEL_HOUSE) {
            if (mb_stripos($cleanComponent, 'д.') !== false || mb_stripos($cleanComponent, 'дом') !== false) {
                $isNewAddress = true;
            } else {
                $lastHousePart = $currentAddressParts[LEVEL_HOUSE] ?? '';
                $lastHouseWords = explode(' ', $lastHousePart);
                $lastWordOfPrevHouse = trim(end($lastHouseWords));
                $newHouseChunk = trim($cleanComponent);
                $areSimilar = false;

                if (mb_strlen($lastWordOfPrevHouse) === 1 && mb_strlen($newHouseChunk) === 1 &&
                    preg_match('/^\p{L}$/u', $lastWordOfPrevHouse) && preg_match('/^\p{L}$/u', $newHouseChunk)) {
                    $areSimilar = true;
                }
                else if (isPurelyNumeric($lastWordOfPrevHouse) && isPurelyNumeric($newHouseChunk)) {
                    $areSimilar = true;
                }

                if ($areSimilar) {
                    $isNewAddress = true;
                    if ($lastHouseComponent) {
                         $reconstructedChunk = replaceLastWord($lastHouseComponent, $newHouseChunk);
                         $cleanComponent = $reconstructedChunk;
                    }
                } else {
                    $isNewAddress = false;
                }
            }
        }

        if ($isNewAddress && $currentLevel === LEVEL_CITY && $lastLevel === LEVEL_CITY) {
            $isNewAddress = false;
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

            if ($currentLevel == LEVEL_STREET && isset($currentAddressParts[LEVEL_REGION]) && isset($currentAddressParts[LEVEL_CITY]))
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

        if ($newHousePartFromHanging ?? null) {
            $currentAddressParts[LEVEL_HOUSE] = $newHousePartFromHanging;
            $lastHouseComponent = $newHousePartFromHanging;
        } else if ($currentLevel == LEVEL_HOUSE && isset($currentAddressParts[LEVEL_HOUSE]) && !$isNewAddress) {
            $currentAddressParts[LEVEL_HOUSE] .= ' ' . $cleanComponent;
            $lastHouseComponent = $currentAddressParts[LEVEL_HOUSE];
        } else {
            if ($currentLevel === LEVEL_CITY && isset($currentAddressParts[LEVEL_CITY]) && !$isNewAddress) {
                $existingCityClean = trim(str_replace(['г.', 'г', 'город'], '', $currentAddressParts[LEVEL_CITY]));
                $newCityClean = trim(str_replace(['г.', 'г', 'город'], '', $cleanComponent));

                if (mb_stripos($existingCityClean, $newCityClean) !== false || mb_stripos($newCityClean, $existingCityClean) !== false) {
                    if (mb_strlen($cleanComponent) > mb_strlen($currentAddressParts[LEVEL_CITY])) {
                        $currentAddressParts[LEVEL_CITY] = $cleanComponent;
                    }
                    continue;
                }
                
                $currentAddressParts[LEVEL_CITY] .= ', ' . $cleanComponent;
                $lastLevel = $currentLevel;
                ksort($currentAddressParts);
                continue;
            }

            if (isset($currentAddressParts[$currentLevel])) {
                $existingPart = $currentAddressParts[$currentLevel];
                if (mb_stripos($cleanComponent, $existingPart) === 0) {
                    $cleanComponent = trim(mb_substr($cleanComponent, mb_strlen($existingPart)));
                }
            }

            foreach($currentAddressParts as $lvl => $part) {
                if ($lvl >= $currentLevel) unset($currentAddressParts[$lvl]);
            }

            if ($cleanComponent !== '') {
                $currentAddressParts[$currentLevel] = $cleanComponent;
                if ($currentLevel === LEVEL_HOUSE) {
                    $lastHouseComponent = $cleanComponent;
                }
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

            $streetSplitExceptions = ['квл', 'квартал', 'мкр', 'микрорайон', 'линия'];
            $isException = false;
            foreach ($streetSplitExceptions as $exception) {
                if (mb_stripos($currentAddressParts[LEVEL_STREET], $exception) !== false) {
                    $isException = true;
                    break;
                }
            }

            if (!$isException) {
                $streetComponent = $currentAddressParts[LEVEL_STREET];
                $splitPos = -1;
                for ($i = mb_strlen($streetComponent) - 1; $i > 0; $i--) {
                    $char = mb_substr($streetComponent, $i, 1);
                    $prev_char = mb_substr($streetComponent, $i - 1, 1);
                    if (is_numeric($char) && $prev_char === ' ') {
                        $splitPos = $i;
                        $partBefore = trim(mb_substr($streetComponent, 0, $splitPos));
                        if (mb_strpos($partBefore, ' ') === false) {
                            $splitPos = -1;
                        }
                        break;
                    }
                }
                if ($splitPos !== -1) {
                    $streetPart = trim(mb_substr($streetComponent, 0, $splitPos));
                    $potentialHousePart = trim(mb_substr($streetComponent, $splitPos));
                    if (!empty($streetPart) && !empty($potentialHousePart)) {
                        $currentAddressParts[LEVEL_STREET] = $streetPart;
                        array_unshift($processingQueue, $potentialHousePart);
                    }
                }
            }
        } else if ($currentLevel === LEVEL_HOUSE && isset($currentAddressParts[LEVEL_HOUSE])) {
            $componentText = $currentAddressParts[LEVEL_HOUSE];
            $streetMarkers = [];
            foreach ($markers as $marker => $level) if ($level === LEVEL_STREET) $streetMarkers[] = $marker;

            $bestStreetPos = -1;
            foreach ($streetMarkers as $streetMarker) {
                $pos = mb_stripos($componentText, ' ' . trim($streetMarker));
                if ($pos !== false && ($bestStreetPos === -1 || $pos < $bestStreetPos)) $bestStreetPos = $pos;
            }

            if ($bestStreetPos !== -1) {
                $words = explode(' ', $componentText);
                $splitIndex = -1;
                for ($i = count($words) - 1; $i >= 0; $i--) {
                    if (!isWordPartOfHouseComponent($words[$i])) {
                        $splitIndex = $i;
                    } else if ($splitIndex !== -1) {
                        break;
                    }
                }

                if ($splitIndex !== -1) {
                    $houseWords = array_slice($words, 0, $splitIndex);
                    $streetWords = array_slice($words, $splitIndex);
                    $housePart = trim(implode(' ', $houseWords));
                    $streetPart = trim(implode(' ', $streetWords));
                    $contextStreet = $currentAddressParts[LEVEL_STREET] ?? null;

                    $cleanContextStreet = str_replace(['.',' '], '', mb_strtolower($contextStreet));
                    $cleanStreetPart = str_replace(['.',' '], '', mb_strtolower($streetPart));

                    if ($contextStreet && (mb_strpos($cleanContextStreet, $cleanStreetPart) !== false || mb_strpos($cleanStreetPart, $cleanContextStreet) !== false)) {
                        $currentAddressParts[LEVEL_HOUSE] = $housePart;
                        $lastHouseComponent = $housePart;
                    }
                    else {
                        unset($currentAddressParts[LEVEL_HOUSE]);
                        array_unshift($processingQueue, $streetPart);
                        if (!empty($housePart)) {
                             array_unshift($processingQueue, $housePart);
                        }
                        $lastLevel = -1;
                        continue;
                    }
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

    // Применяем фильтр для "мусорных" ключевых слов, специфичный для миграции
    $filteredResults = [];
    foreach ($results as $address) {
        $junkKeywords = ['объектами культурного наследия', 'технического состояния', 'Место выполнения работ'];
        $isJunk = false;
        foreach ($junkKeywords as $keyword) {
            if (mb_stristr($address, $keyword)) {
                $isJunk = true; break;
            }
        }
        if (!$isJunk) $filteredResults[] = $address;
    }

    $finalResults = array_unique($filteredResults);
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
