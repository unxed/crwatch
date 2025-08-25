<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * Класс для компактного логирования процесса для анализа ИИ.
 */
class AiLogger
{
    private $fp;

    public function __construct(string $aiFile)
    {
        $this->fp = fopen($aiFile, 'w');
    }

    public function log(string $aiMsg): void
    {
        fwrite($this->fp, $aiMsg . "\n");
    }

    public function section(string $title): void
    {
        $separator = str_repeat("=", 80);
        $this->log("\n" . $separator);
        $this->log("SECTION: " . $title);
        $this->log($separator);
    }

    public function __destruct()
    {
        if ($this->fp) {
            fclose($this->fp);
        }
    }
}


function splitAddresses(string $addressBlock, AiLogger $logger): array
{
    if (empty(trim($addressBlock))) {
        return [];
    }

    $logger->log("I:\"" . $addressBlock . "\"");

    $addressBlock = trim($addressBlock);
    // Нормализация составного маркера "р. п."
    // v22.0: Убрана преждевременная замена \s+ отсюда. Оставлена только нормализация р.п.
    $addressBlock = preg_replace('/р\.\s*п\./i', 'рп.', $addressBlock);

    // Первичное разделение по символам новой строки или точке с запятой
    $parts = preg_split('/[;\r\n]+/', $addressBlock);
    if (count($parts) > 1) {
        $logger->log("SPLIT_PRIMARY: " . count($parts));
        $allAddresses = [];
        foreach ($parts as $part) {
            $allAddresses = array_merge($allAddresses, splitAddresses(trim($part), $logger));
        }
        return array_values(array_unique($allAddresses));
    }

    // Разделение по "Российская Федерация", если встречается более одного раза
    $delimiter = 'Российская Федерация';
    if (substr_count(mb_strtolower($addressBlock), mb_strtolower($delimiter)) > 1) {
        $logger->log("SPLIT_RF: " . substr_count(mb_strtolower($addressBlock), mb_strtolower($delimiter)));
        $parts = preg_split("/(" . preg_quote($delimiter, '/') . ")/i", $addressBlock, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $addresses = [];
        $currentAddress = '';
        foreach ($parts as $part) {
            if (mb_strtolower(trim($part)) === mb_strtolower($delimiter)) {
                if (!empty(trim($currentAddress))) {
                    $addresses = array_merge($addresses, splitAddresses(trim($currentAddress), $logger));
                }
                $currentAddress = $part;
            } else {
                $currentAddress .= $part;
            }
        }
        if (!empty(trim($currentAddress))) {
            $addresses = array_merge($addresses, splitAddresses(trim($currentAddress), $logger));
        }
        return array_values(array_unique($addresses));
    }

    // --- Машина состояний (FSM) для детального разделения ---
    $logger->log("FSM_S");
    
    // v22.0: Нормализация пробелов и запятых перенесена сюда, чтобы не ломать первичную разбивку
    $addressBlock = preg_replace(['/\s+/', '/([,.])\1+/'], [' ', '$1'], $addressBlock);
    // Подготовка строки для токенизации: добавляем пробелы вокруг запятых и после точек без пробелов
    $preparedBlock = str_replace(',', ' , ', $addressBlock);
    $preparedBlock = preg_replace('~(\S\.)([^\s.])~u', '$1 $2', $preparedBlock);
    $preparedBlock = trim(preg_replace('/\s+/', ' ', $preparedBlock));
    $tokens = explode(' ', $preparedBlock);
    $logger->log("T:[" . implode('|', $tokens) . "]");

    // Определение типов маркеров
    $baseMajorLocalityTypes = ['г', 'с', 'п', 'рп', 'пгт', 'село', 'город', 'деревня', 'станица', 'поселок', 'пос'];
    $districtTypes = ['р-н', 'район'];
    $prefixLocalityMarkers = $baseMajorLocalityTypes; // Маркеры НП, которые предшествуют названию
    
    $subLocalityTypes = ['мкр', 'мкрн'];
    $streetTypes = ['ул', 'улица', 'пр', 'пр-т', 'проспект', 'пер', 'переулок', 'наб', 'набережная', 'б-р', 'бульвар', 'площадь', 'пл', 'ш', 'шоссе', 'пр-д', 'проезд', 'линия', 'кан', 'тер', 'дорога'];
    $housePartTypes = ['д', 'дом', 'корп', 'к', 'стр', 'строение', 'литера', 'лит'];
    // Все известные маркеры для определения общего контекста
    $allMarkers = array_merge($baseMajorLocalityTypes, $districtTypes, $subLocalityTypes, $streetTypes, $housePartTypes);

    // v24.0: Создаем ПОЛНЫЙ список всех префиксных маркеров, включая маркеры дома
    // Это нужно для правила NameWait, чтобы не разделять адрес, если мы видим название/номер после префиксного маркера.
    $allPrefixMarkers = array_merge($prefixLocalityMarkers, $subLocalityTypes, $streetTypes, $housePartTypes);

    $prefix = '';
    $firstMarkerIndex = -1;
    // Поиск первого маркера во всем блоке для определения "префикса" адреса
    foreach ($tokens as $i => $token) {
        if (in_array(mb_strtolower(rtrim($token, '.'), 'UTF-8'), $allMarkers)) {
            $firstMarkerIndex = $i;
            break;
        }
    }

    // Отделение начального префикса (регион, область, край)
    if ($firstMarkerIndex != -1) {
        $startIndex = $firstMarkerIndex;
        // Ищем ближайшую запятую влево от первого маркера, чтобы отделить префикс
        while ($startIndex > 0 && $tokens[$startIndex - 1] !== ',') {
            $startIndex--;
        }
        if ($startIndex > 0) {
            $prefixTokens = array_slice($tokens, 0, $startIndex);
            $prefix = implode(' ', $prefixTokens);
            $tokens = array_slice($tokens, $startIndex);
            $logger->log("P:\"" . $prefix . "\"");
        }
    } else {
        // Если маркеров нет, весь блок считается одним адресом
        $logger->log("NO_MARKERS");
        return [buildAddress($prefix, $tokens)];
    }

    $finalAddresses = [];
    $currentAddressParts = []; // Части текущего формируемого адреса
    
    $localityContextParts = [];    // Контекст населенного пункта (город, село и т.д.)
    $localityContextIsValid = false; // Флаг валидности контекста населенного пункта
    $hasSeenLocality = false;      // Был ли уже маркер населенного пункта в текущем адресе
    $hasSeenStreet = false;        // Был ли уже маркер улицы
    $hasSeenHousePart = false;     // Был ли уже маркер дома/корпуса
    $addressPartCompleted = false; // Указывает, что текущая часть адреса (например, дом с литерой) завершена запятой
    $partJustFinished = false;     // Флаг, указывающий, что только что закончилась логическая часть адреса (НП + название, или Улица + название)
    $previousCleanToken = '';      // Предыдущий обработанный токен (приведенный к нижнему регистру)

    // Инициализация контекста населенного пункта из префикса
    if (!empty($prefix)) {
        $prefixTokens = explode(' ', str_replace(',', ' , ', $prefix));
        $lastCommaPos = array_search(',', array_reverse($prefixTokens, true));
        
        // Берем часть после последней запятой или весь префикс, если запятых нет
        $potentialContext = ($lastCommaPos !== false) ? array_slice($prefixTokens, $lastCommaPos + 1) : $prefixTokens;
        $potentialContext = array_filter($potentialContext, 'trim'); // Убираем пустые элементы

        if (!empty($potentialContext)) {
            foreach ($potentialContext as $pToken) {
                // Если в этой части есть маркер НП (включая районы), считаем ее валидным контекстом
                if (in_array(mb_strtolower(rtrim($pToken, '.'), 'UTF-8'), array_merge($baseMajorLocalityTypes, $districtTypes, $subLocalityTypes))) {
                    $localityContextParts = $potentialContext;
                    $localityContextIsValid = true;
                    $hasSeenLocality = true;
                    $logger->log("CTX_INIT_FROM_PREFIX: [" . implode('|', $localityContextParts) . "]");
                    break;
                }
            }
        }
    }

    // Основной цикл машины состояний
    foreach ($tokens as $token) {
        // v21.0: Полностью игнорируем маркеры групп (ГО:, МР:)
        // Это было ключевое исправление для bug 10, так как эти маркеры не являются частью адреса.
        if (mb_substr($token, -1) === ':') {
            $logger->log("'$token' | Group marker, skipping.");
            continue;
        }

        $cleanToken = mb_strtolower(rtrim($token, '.'), 'UTF-8');
        
        // v20.0: Динамическое определение типа маркера района
        $isDistrict = in_array($cleanToken, $districtTypes);
        $isMajorDistrict = $isDistrict && !$hasSeenStreet; // Район - основной, если еще не было улицы
        $isSubDistrict = $isDistrict && $hasSeenStreet;   // Район - вложенный, если улица уже была (например, "Адмиралтейский р-н" в составе города)

        $isBaseMajorLocality = in_array($cleanToken, $baseMajorLocalityTypes);
        $isMajorLocality = $isBaseMajorLocality || $isMajorDistrict; // Объединяем основные типы НП и основные районы
        $isSubLocality = in_array($cleanToken, $subLocalityTypes) || $isSubDistrict; // Объединяем подтипы НП и вложенные районы
        $isLocality = $isMajorLocality || $isSubLocality; // Общий флаг населенного пункта

        $isStreet = in_array($cleanToken, $streetTypes);
        $isHousePart = in_array($cleanToken, $housePartTypes);
        $isMarker = $isLocality || $isStreet || $isHousePart;

        // Логирование текущего состояния для отладки
        $logLine = sprintf("'%s' | s:%d%d%d%d p:%d | c:%d[%s]",
            $token,
            (int)$hasSeenLocality, (int)$hasSeenStreet, (int)$hasSeenHousePart, (int)$addressPartCompleted,
            (int)$partJustFinished,
            (int)$localityContextIsValid, implode('|', $localityContextParts)
        );

        $startNewAddress = false;
        $splitReason = 0; // Код причины разделения для лога

        if (!empty($currentAddressParts)) {
            // S1: Разделение при обнаружении нового префиксного маркера НП (г., с., п.)
            // Реагирует только на префиксные маркеры населенных пунктов, что не ломает постфиксные "р-н"
            if (in_array($cleanToken, $prefixLocalityMarkers) && $hasSeenLocality) {
                $startNewAddress = true;
                $splitReason = 1;
            }
            
            // S4: Разделение после завершения одной логической части адреса (например, "Название р-н," или "Название ул., д.1А,")
            // и начала новой, немаркерной части.
            // Улучшенное правило S4: не разделяет, если предыдущий токен был префиксным маркером (НП, под-НП, улицы или дома)
            // Это исправляет проблему "висящих" маркеров улиц и НП (bug "Случая Андрея")
            if (!$startNewAddress && $partJustFinished && $token !== ',' && !$isMarker) {
                // v24.0: Проверяем, ожидаем ли мы название/номер для ЛЮБОГО префиксного маркера (НП, под-НП, улицы или дома)
                if (in_array($previousCleanToken, $allPrefixMarkers)) {
                    // Ложная тревога! Не разделяем. Просто дадим этому токену (названию/номеру) добавиться.
                    $logLine .= " | d:NameWait";
                } else {
                    // Это настоящая точка разделения (например, "Название р-н, Следующее Название")
                    $startNewAddress = true;
                    $splitReason = 4;
                }
            }

            // S2: Разделение, если адресная часть (дом/корпус/литера) завершена запятой,
            // и далее следует маркер улицы или произвольный токен (возможно, начало нового адреса)
            // v22.0: Эта логика была проблемной, но теперь с правильным $addressPartCompleted должна работать
            if (!$startNewAddress && $addressPartCompleted && ($isStreet || (!$isMarker && $token !== ','))) {
                 $startNewAddress = true;
                 $splitReason = 2;
            }
            // S3: Разделение, если после дома/корпуса сразу идет маркер улицы (пропущенная запятая)
            if (!$startNewAddress && $isStreet && $hasSeenHousePart) {
                $startNewAddress = true;
                $splitReason = 3;
            }
        }
        
        // Если принято решение о разделении
        if ($startNewAddress) {
            $logLine .= " | d:S" . $splitReason;
            // Формируем адрес из накопленных частей, включая префикс
            $builtAddress = buildAddress($prefix, $currentAddressParts);
            $logLine .= " | a:=> \"" . $builtAddress . "\"";
            $finalAddresses[] = $builtAddress;
            
            // Сброс или перенос контекста для нового адреса
            if ($splitReason === 1) { // Если разделение по новому префиксному НП (полный сброс)
                $currentAddressParts = [];
                $localityContextParts = [];
                $localityContextIsValid = false;
                $logLine .= " | CR"; // Context Reset
            } else { // Для S2, S3, S4: сохраняем контекст населенного пункта
                $currentAddressParts = $localityContextIsValid ? $localityContextParts : [];
                $logLine .= " | C>"; // Context Maintained
            }
            
            // Сброс флагов состояния для нового адреса
            $hasSeenLocality = $localityContextIsValid;
            $hasSeenStreet = false;
            $hasSeenHousePart = false;
            $addressPartCompleted = false;
            $partJustFinished = false; 
        } else {
            $logLine .= " | d:A"; // Append: просто добавляем токен к текущему адресу
        }
        
        $logger->log($logLine);

        // Добавление текущего токена к частям адреса (пропущенные маркеры групп сюда не попадают)
        $currentAddressParts[] = $token;

        // "Умная" фиксация контекста, если он еще не был установлен (для случаев типа "Санкт-Петербург, 13-я линия В.О.")
        if ($isStreet && !$hasSeenStreet && !$localityContextIsValid) {
            $streetMarkerIndex = count($currentAddressParts) - 1;
            $streetNameStartIndex = $streetMarkerIndex;

            while ($streetNameStartIndex > 0 && $currentAddressParts[$streetNameStartIndex - 1] !== ',') {
                $streetNameStartIndex--;
            }

            $potentialContext = array_slice($currentAddressParts, 0, $streetNameStartIndex);
            
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

        // Обновление флагов состояния
        // v24.0: Упрощенная и более надежная логика обновления флагов
        if ($isMarker) {
            $partJustFinished = true; // Маркер (НП, улицы, дома) означает, что логическая "единица" закончилась
        } elseif ($token !== ',') {
            $partJustFinished = false; // Обычное слово или число - не завершает логическую "единицу"
        }
        
        if ($isLocality) $hasSeenLocality = true;
        if ($isStreet) {
            $hasSeenStreet = true;
            $hasSeenHousePart = false; // Новая улица сбрасывает флаг дома, так как дом относится к предыдущей улице
            $addressPartCompleted = false; // и флаг завершенности для новой улицы
        }
        if ($isHousePart) {
            $hasSeenHousePart = true;
            $addressPartCompleted = false; // Начало новой части дома сбрасывает флаг завершенности (ждем запятую)
        }
        
        // v22.0: Исправленная логика флага $addressPartCompleted: он должен устанавливаться только при
        // обнаружении запятой СРАЗУ после части дома (д.1,). Сбрасывается при начале нового адреса,
        // новой улицы или новой части дома.
        if ($token === ',' && $hasSeenHousePart) {
            $addressPartCompleted = true;
        }
        
        $previousCleanToken = $cleanToken; // Сохраняем текущий чистый токен для следующей итерации
    }

    // Добавляем оставшиеся части как последний адрес
    if (!empty($currentAddressParts)) {
        $finalAddresses[] = buildAddress($prefix, $currentAddressParts);
        $logger->log("FINAL_BUILD: \"" . end($finalAddresses) . "\"");
    }
    
    $logger->log("FSM_E");
    // Удаляем возможные пустые адреса (например, если блок был только из РФ) и дубликаты
    return array_values(array_unique(array_filter($finalAddresses))); 
}

/**
 * Вспомогательная функция для сборки адреса из частей.
 * v21.0: Больше не принимает $groupContext, так как групповые маркеры игнорируются.
 */
function buildAddress(string $prefix, array $parts): string {
    $address = trim($prefix . ' ' . implode(' ', $parts));
    // Нормализация пробелов и запятых в готовом адресе
    $address = preg_replace('/\s*,\s*/', ', ', $address);
    $address = preg_replace('/ ,/', ',', $address); // Исправляем " , " на ","
    $address = preg_replace(['/\s*,\s*$/', '/\s+/'], ['', ' '], ' ' . $address); // Удаляем висящие запятые в конце
    return trim($address);
}

// =============================================================================
// ЗАПУСК ТЕСТОВ
// =============================================================================

// Убедитесь, что ваш файл test.data.php находится в той же директории
require_once 'test.data.php'; 

$aiLogger = new AiLogger('ai_log.txt');
$humanLogFp = fopen('human_log.txt', 'w');

foreach ($testCases as $name => $data) {
    $sectionHeader = "================= ЗАПУСК ТЕСТА: $name =================\n";
    
    echo "\n" . $sectionHeader;
    $aiLogger->section($name);
    fwrite($humanLogFp, $sectionHeader);
    
    fwrite($humanLogFp, "ИСХОДНЫЕ ДАННЫЕ:\n" . $data . "\n\n");

    $result = splitAddresses($data, $aiLogger);

    $resultHeader = "--- РЕЗУЛЬТАТ РАЗБИВКИ (" . count($result) . " адресов) ---\n";
    echo $resultHeader;
    fwrite($humanLogFp, $resultHeader);

    foreach ($result as $address) {
        $line = "- " . $address . "\n";
        echo $line;
        fwrite($humanLogFp, $line);
    }
    fwrite($humanLogFp, "\n");
}

fclose($humanLogFp);
echo "\nЛогирование завершено. Результаты в файлах human_log.txt и ai_log.txt\n";