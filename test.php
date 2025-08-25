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
    $addressBlock = preg_replace(['/\s+/', '/([,.])\1+/'], [' ', '$1'], $addressBlock);
    $addressBlock = preg_replace('/р\.\s*п\./i', 'рп.', $addressBlock);

    $parts = preg_split('/[;\r\n]+/', $addressBlock);
    if (count($parts) > 1) {
        $logger->log("SPLIT_PRIMARY: " . count($parts));
        $allAddresses = [];
        foreach ($parts as $i => $part) {
            $allAddresses = array_merge($allAddresses, splitAddresses(trim($part), $logger));
        }
        return array_values(array_unique($allAddresses));
    }

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

    // --- Машина состояний ---
    $logger->log("FSM_S");
    
    $preparedBlock = str_replace(',', ' , ', $addressBlock);
    $preparedBlock = preg_replace('~(\S\.)([^\s.])~u', '$1 $2', $preparedBlock);
    $preparedBlock = trim(preg_replace('/\s+/', ' ', $preparedBlock));
    $tokens = explode(' ', $preparedBlock);
    $logger->log("T:[" . implode('|', $tokens) . "]");

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
            $logger->log("P:\"" . $prefix . "\"");
        }
    } else {
        $logger->log("NO_MARKERS");
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
        
        // Берем часть после последней запятой или весь префикс, если запятых нет
        $potentialContext = ($lastCommaPos !== false) ? array_slice($prefixTokens, $lastCommaPos + 1) : $prefixTokens;
        $potentialContext = array_filter($potentialContext, 'trim'); // Убираем пустые элементы

        if (!empty($potentialContext)) {
            foreach ($potentialContext as $pToken) {
                // Если в этой части есть маркер НП, считаем ее валидным контекстом
                if (in_array(mb_strtolower(rtrim($pToken, '.'), 'UTF-8'), $localityTypes)) {
                    $localityContextParts = $potentialContext;
                    $localityContextIsValid = true;
                    $hasSeenLocality = true;
                    $logger->log("CTX_INIT_FROM_PREFIX: [" . implode('|', $localityContextParts) . "]");
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

        $logLine = sprintf("'%s' | s:%d%d%d%d | c:%d[%s]",
            $token,
            (int)$hasSeenLocality, (int)$hasSeenStreet, (int)$hasSeenHousePart, (int)$addressPartCompleted,
            (int)$localityContextIsValid, implode('|', $localityContextParts)
        );

        $startNewAddress = false;
        $splitReason = 0;

        if (!empty($currentAddressParts)) {
            if ($isMajorLocality && ($hasSeenStreet || $hasSeenLocality)) {
                $startNewAddress = true;
                $splitReason = 1;
            }
            if (!$startNewAddress && $addressPartCompleted && ($isStreet || (!$isMarker && $token !== ','))) {
                 $startNewAddress = true;
                 $splitReason = 2;
            }
            if (!$startNewAddress && $isStreet && $hasSeenHousePart) {
                $startNewAddress = true;
                $splitReason = 3;
            }
        }
        
        if ($startNewAddress) {
            $logLine .= " | d:S" . $splitReason;
            $builtAddress = buildAddress($prefix, $currentAddressParts);
            $logLine .= " | a:=> \"" . $builtAddress . "\"";
            $finalAddresses[] = $builtAddress;
            
            if ($splitReason === 1) {
                $currentAddressParts = [];
                $localityContextParts = [];
                $localityContextIsValid = false;
                $logLine .= " | CR";
            } else {
                $currentAddressParts = $localityContextIsValid ? $localityContextParts : [];
                $logLine .= " | C>";
            }

            $hasSeenLocality = $localityContextIsValid;
            $hasSeenStreet = false;
            $hasSeenHousePart = false;
            $addressPartCompleted = false;
        } else {
            $logLine .= " | d:A";
        }
        
        $logger->log($logLine);

        $currentAddressParts[] = $token;

        // "Умная" фиксация контекста, если он еще не был установлен
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
        $logger->log("FINAL_BUILD: \"" . end($finalAddresses) . "\"");
    }
    
    $logger->log("FSM_E");
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
// ЗАПУСК ТЕСТОВ
// =============================================================================

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