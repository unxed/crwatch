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

    $addressBlock = preg_replace('/\s*\(\s*(п\.\s*(п\.\s*)?)\s*.*?\)/ui', '', $addressBlock);
    
    $addressBlock = preg_replace('/р\.\s*п\./i', 'рп.', $addressBlock);

    $regionTypes = ['обл', 'респ', 'край', 'ао'];
    $baseMajorLocalityTypes = ['г', 'с', 'п', 'рп', 'пгт', 'село', 'город', 'деревня', 'станица', 'поселок', 'пос'];
    $districtTypes = ['р-н', 'район'];
    $subLocalityTypes = ['мкр', 'мкрн'];
    $streetTypes = ['ул', 'улица', 'пр', 'пр-т', 'проспект', 'пер', 'переулок', 'наб', 'набережная', 'б-р', 'бульвар', 'площадь', 'пл', 'ш', 'шоссе', 'пр-д', 'проезд', 'линия', 'кан', 'тер', 'дорога'];
    $housePartTypes = ['д', 'дом', 'корп', 'к', 'стр', 'строение', 'литера', 'лит'];

    $parts = preg_split('/[;\r\n]+/', $addressBlock);
    if (count($parts) > 1) {
        $logger->log("SPLIT_PRIMARY_CONTEXTUAL: " . count($parts));
        $allAddresses = [];
        
        $firstPartAddresses = splitAddresses(trim($parts[0]), $logger);
        if (empty($firstPartAddresses)) {
            for ($i = 1; $i < count($parts); $i++) {
                 $allAddresses = array_merge($allAddresses, splitAddresses(trim($parts[$i]), $logger));
            }
            return array_values(array_unique($allAddresses));
        }

        $allAddresses = array_merge($allAddresses, $firstPartAddresses);
        $firstFullAddress = end($firstPartAddresses);

        $parentContext = '';
        $streetPattern = implode('|', array_map(function($t) { return preg_quote($t, '/'); }, $streetTypes));
        $regex = '/^(.*?),\s*(?:\S+\s+)?\b(?:' . $streetPattern . ')\b\.?.*/iu';
        
        $streetFoundInFirst = preg_match($regex, $firstFullAddress, $matches);

        if ($streetFoundInFirst) {
            $parentContext = trim($matches[1], " ,");
            $logger->log("CONTEXT_EXTRACTED (from full addr): \"" . $parentContext . "\"");
        } else {
            $parentContext = $firstFullAddress;
            array_pop($allAddresses);
            $logger->log("CONTEXT_EXTRACTED (as context-only): \"" . $parentContext . "\"");
        }

        if (!empty($parentContext)) {
            for ($i = 1; $i < count($parts); $i++) {
                $part = trim($parts[$i]);
                if (empty($part)) continue;
                
                $isAlreadyFull = false;
                $partTokens = explode(' ', $part);
                $majorLocalityMarkers = array_merge($regionTypes, $baseMajorLocalityTypes, $districtTypes);
                foreach ($partTokens as $token) {
                    // v36.0 (Bug 20): FIX - Очищаем токен и от запятых, а не только от точек
                    if (in_array(mb_strtolower(rtrim($token, '.,'), 'UTF-8'), $majorLocalityMarkers)) {
                        $isAlreadyFull = true;
                        $logger->log("Part \"$part\" is considered a full address (found marker: \"$token\").");
                        break;
                    }
                }

                $addressToProcess = $isAlreadyFull ? $part : $parentContext . ', ' . $part;
                $logger->log("CONTEXT_APPLY: Processing \"" . $addressToProcess . "\"");
                $allAddresses = array_merge($allAddresses, splitAddresses($addressToProcess, $logger));
            }
        } else {
             for ($i = 1; $i < count($parts); $i++) {
                 $allAddresses = array_merge($allAddresses, splitAddresses(trim($parts[$i]), $logger));
            }
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
        
        $logger->log(sprintf("HEURISTIC_CHECK: Tokens=%d, Markers=%d, Ratio=%.3f (Threshold %.2f)", $tokenCount, $markerCount, $markerRatio, $maxMarkerRatioForText));

        if ($markerRatio < $maxMarkerRatioForText) {
            $logger->log("HEURISTIC_PASS: Ratio is below threshold. Returning as single text block.");
            return [$addressBlock];
        } else {
            $logger->log("HEURISTIC_FAIL: Ratio is high enough. Proceeding to FSM.");
        }
    } else {
        $logger->log(sprintf("HEURISTIC_SKIP: Token count (%d) is not above threshold (%d). Proceeding to FSM.", $tokenCount, $minTokensForHeuristic));
    }

    $logger->log("FSM_S");
    
    $addressBlock = preg_replace(['/\s+/', '/([,.])\1+/'], [' ', '$1'], $addressBlock);
    $preparedBlock = str_replace(',', ' , ', $addressBlock);
    $preparedBlock = preg_replace('~(\S\.)([^\s.])~u', '$1 $2', $preparedBlock);
    $preparedBlock = trim(preg_replace('/\s+/', ' ', $preparedBlock));
    $tokens = explode(' ', $preparedBlock);
    $logger->log("T:[" . implode('|', $tokens) . "]");

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
            $logger->log("P:\"" . $prefix . "\"");
        }
    } else {
        $logger->log("NO_MARKERS");
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
                    $logger->log("CTX_INIT_FROM_PREFIX: [" . implode('|', $localityContextParts) . "]");
                    break;
                }
            }
        }
    }

    foreach ($tokens as $token) {
        $cleanTokenWithColon = mb_strtolower($token, 'UTF-8');
        $cleanToken = mb_strtolower(rtrim($token, '.'), 'UTF-8');

        if (in_array($cleanTokenWithColon, $groupSeparators)) {
            $logger->log("'$token' | Group separator found.");
            if (!empty(array_filter($currentAddressParts, 'trim'))) {
                $builtAddress = buildAddress($prefix, $currentAddressParts);
                $finalAddresses[] = $builtAddress;
                $logger->log("FINALIZED_BY_SEPARATOR: \"" . $builtAddress . "\"");
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

        $looksLikeHouseContinuation = (bool)preg_match('/^(\d+|[а-я])$/ui', $cleanToken);

        $logLine = sprintf("'%s' | s:%d%d%d%d p:%d | c:%d[%s] hc:%d",
            $token,
            (int)$hasSeenLocality, (int)$hasSeenStreet, (int)$hasSeenHousePart, (int)$addressPartCompleted,
            (int)$partJustFinished,
            (int)$localityContextIsValid, implode('|', $localityContextParts),
            (int)$looksLikeHouseContinuation
        );

        $startNewAddress = false;
        $splitReason = 0;

        if (!empty($currentAddressParts)) {
            if (in_array($cleanToken, $prefixLocalityMarkers) && $hasSeenLocality) {
                $startNewAddress = true;
                $splitReason = 1;
            }
            
            if (!$startNewAddress && $partJustFinished && $token !== ',' && !$isMarker) {
                if (in_array($previousCleanToken, $allPrefixMarkers)) {
                    $logLine .= " | d:NameWait";
                } else {
                    $startNewAddress = true;
                    $splitReason = 4;
                }
            }

            if (!$startNewAddress && $addressPartCompleted && ($isStreet || $isLocality || (!$isMarker && $token !== ',' && !$looksLikeHouseContinuation))) {
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
            
            if ($splitReason === 1 || ($splitReason === 2 && $isLocality)) {
                $currentAddressParts = [];
                $localityContextParts = [];
                $localityContextIsValid = false;
                $hasSeenLocality = false;
                $logLine .= " | CR_LOC";
            } else {
                $currentAddressParts = $localityContextIsValid ? $localityContextParts : [];
                $hasSeenLocality = $localityContextIsValid;
                $logLine .= " | C>";
            }
            
            $hasSeenStreet = false;
            $hasSeenHousePart = false;
            $addressPartCompleted = false;
            $partJustFinished = false; 
        } else {
            $logLine .= " | d:A";
        }
        
        $logger->log($logLine);

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
                    $logger->log("CTX_SET: [" . implode('|', $localityContextParts) . "] on token '" . $token . "'");
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
        $logger->log("FINAL_BUILD: \"" . end($finalAddresses) . "\"");
    }
    
    $logger->log("FSM_E");
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