<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

function splitAddresses(string $addressBlock, int $recursionDepth = 0): array
{
    $logPrefix = str_repeat("  ", $recursionDepth);
    if ($recursionDepth == 0) {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "НАЧАЛО РАЗБОРА НОВОГО БЛОКА АДРЕСОВ\n";
        echo str_repeat("=", 80) . "\n";
    }
    
    if (empty(trim($addressBlock))) {
        return [];
    }

    // =========================================================================
    // 1. ПРЕДВАРИТЕЛЬНАЯ ОБРАБОТКА И РЕКУРСИВНЫЕ РАЗДЕЛИТЕЛИ
    // =========================================================================

    $addressBlock = trim($addressBlock);
    $addressBlock = preg_replace(['/\s+/', '/([,.])\1+/'], [' ', '$1'], $addressBlock);

    // Предварительная обработка составных маркеров
    $originalForRp = $addressBlock;
    $addressBlock = preg_replace('/р\.\s*п\./i', 'рп.', $addressBlock);
    if ($originalForRp !== $addressBlock) {
        echo $logPrefix . "[PREP] Составной маркер 'р. п.' заменен на 'рп.'\n";
    }

    // Разделение по жестким разделителям (точка с запятой, новая строка)
    $parts = preg_split('/[;\r\n]+/', $addressBlock);
    if (count($parts) > 1) {
        echo $logPrefix . "[SPLIT] Обнаружены жесткие разделители (';' или '\\n'). Блок разделен на " . count($parts) . " частей. Обрабатываю рекурсивно.\n";
        $allAddresses = [];
        foreach ($parts as $part) {
            $allAddresses = array_merge($allAddresses, splitAddresses(trim($part), $recursionDepth + 1));
        }
        return array_values(array_unique($allAddresses));
    }

    // Разделение по "Российская Федерация"
    $delimiter = 'Российская Федерация';
    if (substr_count(mb_strtolower($addressBlock), mb_strtolower($delimiter)) > 1) {
        echo $logPrefix . "[SPLIT] Обнаружено несколько вхождений '" . $delimiter . "'. Разделяю по этому ключу.\n";
        $parts = preg_split("/(" . preg_quote($delimiter, '/') . ")/i", $addressBlock, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $addresses = [];
        $currentAddress = '';
        foreach ($parts as $part) {
            if (mb_strtolower(trim($part)) === mb_strtolower($delimiter)) {
                if (!empty(trim($currentAddress))) {
                    $addresses = array_merge($addresses, splitAddresses(trim($currentAddress), $recursionDepth + 1));
                }
                $currentAddress = $part;
            } else {
                $currentAddress .= $part;
            }
        }
        if (!empty(trim($currentAddress))) {
            $addresses = array_merge($addresses, splitAddresses(trim($currentAddress), $recursionDepth + 1));
        }
        return array_values(array_unique($addresses));
    }

    // =========================================================================
    // 2. МАШИНА СОСТОЯНИЙ
    // =========================================================================
    echo $logPrefix . "--- ЗАПУСК МАШИНЫ СОСТОЯНИЙ ---\n";
    echo $logPrefix . "[INPUT] Входящая строка: \"$addressBlock\"\n";

    // --- Подготовка токенов ---
    $preparedBlock = str_replace(',', ' , ', $addressBlock);
    $preparedBlock = preg_replace('~(\S\.)([^\s.])~u', '$1 $2', $preparedBlock);
    $preparedBlock = trim(preg_replace('/\s+/', ' ', $preparedBlock));
    $tokens = explode(' ', $preparedBlock);
    echo $logPrefix . "[TOKENIZE] Строка для токенизации: \"$preparedBlock\"\n";
    echo $logPrefix . "[TOKENIZE] Получено токенов: " . count($tokens) . " -> [" . implode(' | ', $tokens) . "]\n";

    // --- Словари маркеров ---
    $majorLocalityTypes = ['г', 'с', 'п', 'рп', 'пгт', 'село', 'город', 'деревня', 'станица', 'поселок'];
    $subLocalityTypes = ['мкр', 'мкрн'];
    $localityTypes = array_merge($majorLocalityTypes, $subLocalityTypes);
    $streetTypes = ['ул', 'улица', 'пр', 'пр-т', 'проспект', 'пер', 'переулок', 'наб', 'набережная', 'б-р', 'бульвар', 'площадь', 'пл', 'ш', 'шоссе', 'пр-д', 'проезд', 'линия', 'кан', 'тер'];
    $housePartTypes = ['д', 'дом', 'корп', 'к', 'стр', 'строение', 'литера', 'лит'];
    $allMarkers = array_merge($localityTypes, $streetTypes, $housePartTypes);

    // --- Определение префикса (часть адреса до первого значащего маркера) ---
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
            echo $logPrefix . "[PREFIX] Обнаружен префикс: \"$prefix\"\n";
            echo $logPrefix . "[PREFIX] Оставшиеся токены для разбора: [" . implode(' | ', $tokens) . "]\n";
        }
    } else {
        echo $logPrefix . "[INFO] Маркеры не найдены. Считаем всю строку одним адресом.\n";
        return [buildAddress('', $tokens)];
    }

    // --- Инициализация состояний ---
    $finalAddresses = [];
    $currentAddressParts = [];
    
    $localityContextParts = [];
    $localityContextIsValid = false;
    $hasSeenLocality = false;
    $hasSeenStreet = false;
    $hasSeenHousePart = false;
    $addressPartCompleted = false;

    echo $logPrefix . "--- НАЧАЛО ЦИКЛА ОБРАБОТКИ ТОКЕНОВ ---\n";

    foreach ($tokens as $token) {
        echo $logPrefix . "-----------------------------------------------------\n";
        echo $logPrefix . "[TOKEN] >> Обработка токена: '$token'\n";

        $cleanToken = rtrim($token, '.');
        
        $isLocality = in_array($cleanToken, $localityTypes);
        $isMajorLocality = in_array($cleanToken, $majorLocalityTypes);
        $isStreet = in_array($cleanToken, $streetTypes);
        $isHousePart = in_array($cleanToken, $housePartTypes);
        $isMarker = $isLocality || $isStreet || $isHousePart;

        $markerInfo = [];
        if ($isMajorLocality) $markerInfo[] = 'ОСН. НАС. ПУНКТ';
        elseif ($isLocality) $markerInfo[] = 'ВЛОЖ. НАС. ПУНКТ';
        if ($isStreet) $markerInfo[] = 'УЛИЦА';
        if ($isHousePart) $markerInfo[] = 'ДОМ/КОРПУС';
        if ($token === ',') $markerInfo[] = 'ЗАПЯТАЯ';
        if (empty($markerInfo)) $markerInfo[] = 'Обычное слово';
        echo $logPrefix . "[INFO]  Тип токена: " . implode(', ', $markerInfo) . "\n";

        // --- Логирование состояния ДО принятия решения ---
        echo $logPrefix . "[STATE] ДО: " .
             "hasLocality=" . ($hasSeenLocality ? 'T' : 'F') . ", " .
             "hasStreet=" . ($hasSeenStreet ? 'T' : 'F') . ", " .
             "hasHouse=" . ($hasSeenHousePart ? 'T' : 'F') . ", " .
             "partCompleted=" . ($addressPartCompleted ? 'T' : 'F') . ", " .
             "contextValid=" . ($localityContextIsValid ? 'T' : 'F') . "\n";
        echo $logPrefix . "[STATE] ДО: Контекст: [" . implode(' ', $localityContextParts) . "]\n";
        echo $logPrefix . "[STATE] ДО: Текущий адрес: [" . implode(' ', $currentAddressParts) . "]\n";

        // --- Логика принятия решения о разделении ---
        $startNewAddress = false;
        $splitByLocality = false;
        if (!empty($currentAddressParts)) {
            if ($isMajorLocality && ($hasSeenStreet || $hasSeenLocality)) {
                $startNewAddress = true;
                $splitByLocality = true;
                echo $logPrefix . "[DECISION] ПРАВИЛО 1: Новый ОСНОВНОЙ нас. пункт ('$token') после улицы или другого нас. пункта. РЕШЕНИЕ: РАЗДЕЛИТЬ.\n";
            }
            if (!$startNewAddress && $addressPartCompleted && ($isStreet || (!$isMarker && $token !== ','))) {
                 $startNewAddress = true;
                 echo $logPrefix . "[DECISION] ПРАВИЛО 2: Адрес был завершен (..., д.123,), и встретился новый маркер улицы ('$token') или обычное слово. РЕШЕНИЕ: РАЗДЕЛИТЬ.\n";
            }
            if (!$startNewAddress && $isStreet && $hasSeenHousePart) {
                $startNewAddress = true;
                echo $logPrefix . "[DECISION] ПРАВИЛО 3: Встретился маркер улицы ('$token') после того, как уже был номер дома. РЕШЕНИЕ: РАЗДЕЛИТЬ.\n";
            }
        }
        if (!$startNewAddress) {
            echo $logPrefix . "[DECISION] Условия для разделения не выполнены. РЕШЕНИЕ: ПРОДОЛЖИТЬ ТЕКУЩИЙ АДРЕС.\n";
        }

        // --- Выполнение действий на основе решения ---
        if ($startNewAddress) {
            echo $logPrefix . "[ACTION] >> НАЧАЛО НОВОГО АДРЕСА <<\n";
            $finalAddresses[] = buildAddress($prefix, $currentAddressParts);
            
            if ($splitByLocality) {
                echo $logPrefix . "[ACTION] Сброс по населенному пункту: очищаем текущий адрес и контекст.\n";
                $currentAddressParts = [];
                $localityContextParts = [];
                $localityContextIsValid = false;
            } else {
                if ($localityContextIsValid) {
                    echo $logPrefix . "[ACTION] Восстанавливаем валидный контекст: [" . implode(' ', $localityContextParts) . "]\n";
                    $currentAddressParts = $localityContextParts;
                } else {
                    echo $logPrefix . "[ACTION] Контекст невалиден, начинаем с чистого листа.\n";
                    $currentAddressParts = [];
                }
            }

            // Сброс флагов
            $hasSeenLocality = $localityContextIsValid;
            $hasSeenStreet = false;
            $hasSeenHousePart = false;
            $addressPartCompleted = false;
            echo $logPrefix . "[ACTION] Флаги состояний сброшены.\n";
        }

        // --- Обновление состояния ---
        $currentAddressParts[] = $token;
        echo $logPrefix . "[UPDATE] Токен '$token' добавлен в текущий адрес.\n";

        if ($isLocality) $hasSeenLocality = true;
        if ($isStreet) $hasSeenStreet = true;
        if ($isHousePart) $hasSeenHousePart = true;

        // Обновление контекста населенного пункта
        if (!$hasSeenStreet) {
            $localityContextParts[] = $token;
            if ($isLocality) {
                if (!$localityContextIsValid) {
                    echo $logPrefix . "[CONTEXT] Контекст населенного пункта стал ВАЛИДНЫМ из-за токена '$token'.\n";
                }
                $localityContextIsValid = true;
            }
        }

        // Обновление флага "завершенности" части адреса
        if ($token === ',' && $hasSeenHousePart) {
            if (!$addressPartCompleted) {
                echo $logPrefix . "[UPDATE] Флаг 'addressPartCompleted' установлен в TRUE (запятая после номера дома).\n";
            }
            $addressPartCompleted = true;
        } elseif ($hasSeenHousePart) {
            if ($addressPartCompleted) {
                echo $logPrefix . "[UPDATE] Флаг 'addressPartCompleted' сброшен в FALSE (слово после номера дома без запятой).\n";
            }
            $addressPartCompleted = false;
        }
    }

    echo $logPrefix . "--- ЦИКЛ ЗАВЕРШЕН ---\n";

    if (!empty($currentAddressParts)) {
        echo $logPrefix . "[FINAL] Сохраняем последний накопленный адрес.\n";
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
    // Человекочитаемый вывод
    echo "  -> [BUILD] Сформирован адрес: \"" . $address . "\"\n";
    return $address;
}

// =============================================================================
// ТЕСТОВЫЕ ДАННЫЕ (вставьте свои данные сюда)
// =============================================================================
$testCases = [
    // "Случай Андрея (Грибоедова)" => "...",
    // "Мой случай (Петербург)" => "...",
    // "Кейс Алёны" => "...",
    // "Кейс Алёны (новый)" => "...",
];

// =============================================================================
// ЗАПУСК ТЕСТОВ
// =============================================================================
foreach ($testCases as $name => $data) {
    echo "#################################################################\n";
    echo "ЗАПУСК ТЕСТА: $name\n";
    echo "#################################################################\n";

    $result = splitAddresses($data);

    echo "\n--- ИТОГОВЫЙ РЕЗУЛЬТАТ ДЛЯ ТЕСТА '$name' (" . count($result) . " адресов) ---\n";
    foreach ($result as $i => $address) {
        echo ($i + 1) . ". " . $address . "\n";
    }
    echo "\n\n";
}
