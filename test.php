<?php
// test_splitter.php (финальная версия с надежной Машиной Состояний v5.0)

ini_set('display_errors', 1);
error_reporting(E_ALL);

function splitAddresses(string $addressBlock): array
{
    if (empty(trim($addressBlock))) {
        return [];
    }

    echo "--- НАЧАЛО ОБРАБОТКИ БЛОКА ---\n";
    $addressBlock = trim($addressBlock);
    // ИЗМЕНЕНИЕ v5.0: Агрессивная нормализация данных на входе для устранения опечаток
    $addressBlock = preg_replace(['/\s+/', '/([,.])\1+/'], [' ', '$1'], $addressBlock);
    echo "Исходный текст: " . str_replace("\n", " ", $addressBlock) . "\n";

    // Приоритет 1: Разделение по надежным разделителям (\n и ;)
    $parts = preg_split('/[;\r\n]+/', $addressBlock);
    if (count($parts) > 1) {
        echo "Обнаружены надежные разделители (';' или '\\n'). Обрабатываем каждую часть отдельно...\n";
        $allAddresses = [];
        foreach ($parts as $part) {
            $allAddresses = array_merge($allAddresses, splitAddresses(trim($part)));
        }
        return array_values(array_unique($allAddresses));
    }

    // Приоритет 2: Разделение по "Российская Федерация", если их несколько
    $delimiter = 'Российская Федерация';
    if (substr_count(mb_strtolower($addressBlock), mb_strtolower($delimiter)) > 1) {
        echo "Обнаружено несколько вхождений '$delimiter'. Обрабатываем каждую часть отдельно...\n";
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

    // --- Логика "Машины состояний" v5.0 для склеенных строк ---

    $preparedBlock = str_replace(',', ' , ', $addressBlock);
    $preparedBlock = preg_replace('~(\S\.)([^\s.])~u', '$1 $2', $preparedBlock);
    $preparedBlock = trim(preg_replace('/\s+/', ' ', $preparedBlock));
    $tokens = explode(' ', $preparedBlock);

    $localityTypes = ['г.', 'с.', 'п.', 'р.п.', 'пгт.', 'мкр.', 'мкрн.', 'село', 'город', 'деревня', 'станица', 'поселок'];
    $streetTypes = ['ул.', 'улица', 'пр.', 'пр-т', 'проспект', 'пер.', 'переулок', 'наб.', 'набережная', 'б-р', 'бульвар', 'площадь', 'пл.', 'ш.', 'шоссе', 'пр-д', 'проезд', 'линия', 'кан.', 'тер.'];
    $housePartTypes = ['д.', 'дом', 'корп.', 'к.', 'стр.', 'строение', 'литера', 'лит.'];
    $allMarkers = array_merge($localityTypes, $streetTypes, $housePartTypes);

    $prefix = '';
    $firstMarkerIndex = -1;
    foreach ($tokens as $i => $token) {
        if (in_array($token, $allMarkers)) {
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
            echo "Определен базовый префикс/контекст: '" . $prefix . "'\n";
        }
    } else {
        return [$addressBlock];
    }
    
    $finalAddresses = [];
    $currentAddressParts = [];
    // Состояние машины
    $hasSeenLocality = false;
    $hasSeenStreet = false;
    $hasSeenHousePart = false;
    // ИЗМЕНЕНИЕ v5.0: Ключевой флаг "завершенности"
    $addressPartCompleted = false;

    foreach ($tokens as $token) {
        $isLocality = in_array($token, $localityTypes);
        $isStreet = in_array($token, $streetTypes);
        $isHousePart = in_array($token, $housePartTypes);
        $isMarker = in_array($token, $allMarkers);

        $startNewAddress = false;
        if (!empty($currentAddressParts)) {
            // Правило 1 (для Астрахани): Новый город после улицы или другого города
            if ($isLocality && ($hasSeenStreet || $hasSeenLocality)) {
                $startNewAddress = true;
            }
            // Правило 2 (для Петербурга): Обычное слово после завершенной части адреса
            if ($addressPartCompleted && !$isMarker && $token !== ',') {
                $startNewAddress = true;
            }
        }

        if ($startNewAddress) {
            $finalAddresses[] = buildAddress($prefix, $currentAddressParts);
            // Сброс состояния для нового адреса
            $currentAddressParts = [];
            $hasSeenLocality = false;
            $hasSeenStreet = false;
            $hasSeenHousePart = false;
            $addressPartCompleted = false;
        }

        $currentAddressParts[] = $token;

        // Обновляем состояние машины на основе ТЕКУЩЕГО токена
        if ($isLocality) $hasSeenLocality = true;
        if ($isStreet) $hasSeenStreet = true;
        if ($isHousePart) $hasSeenHousePart = true;
        
        // ИЗМЕНЕНИЕ v5.0: Взводим флаг завершенности, если видим запятую после маркера дома
        if ($token === ',' && $hasSeenHousePart) {
            $addressPartCompleted = true;
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
    echo "  -> СОБРАН АДРЕС: " . $address . "\n";
    return $address;
}

// =============================================================================
// ТЕСТОВЫЕ ДАННЫЕ (остаются без изменений)
// =============================================================================
$testCases = [
    "Случай Андрея (Грибоедова)" => "Российская Федерация, Нижегородская обл, Дзержинск г, ул.Чапаева, д.58\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Суворова, д.2\nРоссийская Федерация, Нижегородская обл, Дзержинск г, б-р Мира, д.40\nРоссийская Федерация, Нижегородская обл, Дзержинск г, б-р Мира, д.31\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Грибоедова, д.41\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Грибоедова, д.33\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Маяковского, д.15\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Маяковского, д.13\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Маяковского, д.8\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Маяковского, д.20\nРоссийская Федерация, Нижегородская обл, Дзержинск г, пр-т Ленина, д.2а\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул..Чапаева, д.33\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул..Чапаева, д.46а\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Гайдара, д.27/13\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Грибоедова, д.7\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Маяковского, д.22\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Революции, д.18\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Грибоедова, д.27\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Попова, д.14\nРоссийская Федерация, Нижегородская обл, Дзержинск г, б-р Мира, д.17\nРоссийская Федерация, Нижегородская обл, Дзержинск г, пр-т Ленинского Комсомола, д.32\nРоссийская Федерация, Нижегородская обл, Дзержинск г, пр-т Циолковского, д.70\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Галкина, д..2/38\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Октябрьская, д.24\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Октябрьская, д.28\nРоссийская Федерация, Нижегородская обл, Дзержинск г, пр-т Ленина, д..61\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул..Гагарина, д.10 /5\nРоссийская Федерация, Нижегородская обл, Дзержинск г, пр-т Циолковского, д.26\nРоссийская Федерация, Нижегородская обл, Дзержинск г, ул.Грибоедова, д.30\nРоссийская Федерация, Нижегородская обл, Дзержинск г, пр-т Циолковского, д.45\nРоссийская Федерация, Нижегородская обл, Дзержинск г, пр-т Циолковского, д.45б\nРоссийская Федерация, Нижегородская обл, Дзержинск г, пр-т Ленина, д.105\nРоссийская Федерация, Нижегородская обл, Дзержинск г, пр-т Чкалова, д.22/40",
    "Мой случай (Петербург)" => "Российская Федерация, Санкт-Петербург, 5-я Советская ул., д. 16 литера А, 8-я Советская ул., д. 15/24 литера А, Варшавская ул., д. 116 литера А, Варшавская ул., д. 14 литера А, Варшавская ул., д. 34 литера А, Конная ул., д. 10 литера Б, Новочеркасский пр., д. 40 литера А, Ставропольская ул., д. 1 литера А, Таллинская ул., д. 22 литера А, Фурштатская ул., д. 26 литера А, Фурштатская ул., д. 43 литера А, Фурштатская ул., д. 54 литера А, Чайковского ул., д. 39 литера Б,",
    "Кейс Алёны (Астрахань)" => "Российская Федерация, Астраханская обл, г. Знаменск, ул. Волгоградская, 30 г. Знаменск, ул. Янгеля, 19 г. Камызяк, ул. Тулайкова, 9 г. Нариманов, ул. Астраханская, 7 г. Харабали, ул. Пирогова, 9 г. Харабали, ул. Советская, 110 п. Володарский, ул. Мичурина, 8 п. Стеклозавода, ул. Гоголя, 6 п. Стеклозавода, ул. Гоголя, 8 п. Стеклозавода, ул. Карла Маркса, 1 п. Стеклозавода, ул. Ленина, 18 п. Стеклозавода, ул. Ленина, 19 п. Стеклозавода, ул. Пушкина, 1 п. Стеклозавода, ул. Пушкина, 3 п. Тинаки -2ые, ул. Санаторная, 4 п. Трусово, ул. Железнодорожная, 6 п. Трусово, ул. Школьная, 1, литер а п. Трусово, ул. Школьная, 10, литер А п. Трусово, ул. Школьная, 11 п. Трусово, ул. Школьная, 2, литер А п. Трусово, ул. Школьная, 3, литер А п. Трусово, ул. Школьная, 4, литер А п. Трусово, ул. Школьная, 5, литер А п. Трусово, ул. Школьная, 7, литер А п. Трусово, ул. Школьная, 8, литер А п. Трусово, ул. Школьная, 9, литер А"
];

// =============================================================================
// ЗАПУСК ТЕСТОВ
// =============================================================================
foreach ($testCases as $name => $data) {
    echo "=================================================================\n";
    echo "ЗАПУСК ТЕСТА: $name\n";
    echo "=================================================================\n";
    
    $result = splitAddresses($data);
    
    echo "\n--- РЕЗУЛЬТАТ РАЗБИВКИ (" . count($result) . " адресов) ---\n";
    foreach ($result as $address) {
        echo "- " . $address . "\n";
    }
    echo "\n\n";
}