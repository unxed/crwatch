<?php

require 'vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;

// =============================================================================
// КОНФИГУРАЦИЯ
// =============================================================================
const INPUT_FILE = 'contracts.json';
const OUTPUT_FILE = 'contracts_enriched.json';

// =============================================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// =============================================================================

function fetchUrl(string $url): string|false
{
    echo "  Загрузка: " . substr($url, 0, 100) . "...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $html = curl_exec($ch);
    if (curl_errno($ch)) {
        echo '  ! Ошибка cURL: ' . curl_error($ch) . "\n";
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    return $html;
}

function displayProgressBar(int $current, int $total): void
{
    if ($total == 0) return;
    $progress = $current / $total;
    $barLength = 50;
    $filledLength = (int)($barLength * $progress);
    $bar = str_repeat('=', $filledLength) . str_repeat('-', $barLength - $filledLength);
    printf("\rПрогресс: [%s] %d/%d (%.2f%%)", $bar, $current, $total, $progress * 100);
}

/**
 * Извлекает номер аукциона из строки.
 */
function extractAuctionNumber(string $details): ?string
{
    if (preg_match('/№\s*(\d+)$/', $details, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Парсит страницу с детальной информацией об аукционе.
 */
function parseAuctionPage(string $htmlContent): array
{
    $crawler = new Crawler($htmlContent);
    $enrichedData = [];

    // Блок с основной информацией
    $mainInfoNode = $crawler->filter('.cardMainInfo');
    if ($mainInfoNode->count() > 0) {
        $enrichedData['procurement_main_info'] = [
            'status' => $mainInfoNode->filter('.cardMainInfo__state')->count() ? trim($mainInfoNode->filter('.cardMainInfo__state')->text()) : null,
            'object' => $mainInfoNode->filter('.cardMainInfo__section .cardMainInfo__content')->eq(0)->text(),
            'initial_price' => $mainInfoNode->filter('.cost')->count() ? trim($mainInfoNode->filter('.cost')->text()) : null,
            'published_date' => $mainInfoNode->filter('.date .cardMainInfo__content')->eq(0)->text(),
            'updated_date' => $mainInfoNode->filter('.date .cardMainInfo__content')->eq(1)->text(),
            'end_date' => $mainInfoNode->filter('.date .cardMainInfo__content')->eq(2)->text(),
        ];
    }
    
    // Парсинг всех информационных блоков "заголовок-значение"
    $crawler->filter('.blockInfo')->each(function (Crawler $block) use (&$enrichedData) {
        $blockTitle = $block->filter('.blockInfo__title')->text();
        $sectionData = [];
        $block->filter('.section')->each(function (Crawler $section) use (&$sectionData) {
            $title = $section->filter('.section__title')->count() ? trim($section->filter('.section__title')->text()) : null;
            $info = $section->filter('.section__info')->count() ? trim($section->filter('.section__info')->text()) : null;
            if ($title) {
                $sectionData[$title] = $info;
            }
        });
        $enrichedData[$blockTitle] = $sectionData;
    });

    return $enrichedData;
}


// --- ОСНОВНАЯ ЛОГИКА ЗАПУСКА ---

echo "Запуск скрипта обогащения данных...\n";

// 1. Читаем исходный файл
if (!file_exists(INPUT_FILE)) {
    die("Ошибка: Входной файл '" . INPUT_FILE . "' не найден. Сначала запустите первый скрипт.\n");
}
$sourceContracts = json_decode(file_get_contents(INPUT_FILE), true);
$totalContracts = count($sourceContracts);
echo "Найдено $totalContracts контрактов в '" . INPUT_FILE . "'.\n";

// 2. Загружаем уже обработанные данные для возобновления
$enrichedContracts = [];
if (file_exists(OUTPUT_FILE)) {
    echo "Найден файл с результатами '" . OUTPUT_FILE . "'. Попытка возобновить работу...\n";
    $enrichedContracts = json_decode(file_get_contents(OUTPUT_FILE), true);
}

// Создаем карту уже обработанных контрактов для быстрой проверки
$processedMap = [];
foreach ($enrichedContracts as $contract) {
    if (isset($contract['registry_number'])) {
        $processedMap[$contract['registry_number']] = true;
    }
}
$processedCount = count($processedMap);
echo "$processedCount контрактов уже было обработано.\n";

// 3. Начинаем основной цикл
$currentIndex = 0;
foreach ($sourceContracts as $contract) {
    $currentIndex++;
    $registryNumber = $contract['registry_number'] ?? null;

    // Пропускаем, если уже обработано
    if (isset($processedMap[$registryNumber])) {
        displayProgressBar($currentIndex, $totalContracts);
        continue;
    }

    echo "\nОбработка контракта $currentIndex/$totalContracts ($registryNumber)...\n";

    // 2.1 Извлекаем номер аукциона
    $procurementDetails = $contract['procurement_details'] ?? null;
    if (!$procurementDetails) {
        echo "  - Пропущено: отсутствует поле 'procurement_details'.\n";
        continue;
    }

    $auctionNumber = extractAuctionNumber($procurementDetails);
    if (!$auctionNumber) {
        echo "  - Пропущено: не удалось извлечь номер аукциона из '$procurementDetails'.\n";
        continue;
    }

    // 2.2 Загружаем страницу аукциона
    $auctionUrl = "https://zakupki.gov.ru/epz/order/notice/ea615/view/common-info.html?regNumber=" . $auctionNumber;
    $auctionHtml = fetchUrl($auctionUrl);
    
    if (!$auctionHtml) {
        echo "  - Пропущено: не удалось загрузить страницу аукциона.\n";
        sleep(5); // Пауза в случае ошибки, чтобы не забанили
        continue;
    }

    // 2.3 Парсим и объединяем данные
    $auctionData = parseAuctionPage($auctionHtml);
    $mergedContract = array_merge($contract, ['procurement_info' => $auctionData]);
    
    // Добавляем результат и сразу сохраняем
    $enrichedContracts[] = $mergedContract;
    file_put_contents(OUTPUT_FILE, json_encode($enrichedContracts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    displayProgressBar($currentIndex, $totalContracts);
    //sleep(2); // Пауза между запросами, чтобы не нагружать сервер
}

echo "\n\n=================================================\n";
echo "Работа завершена.\n";
echo "Всего обработано контрактов: " . count($enrichedContracts) . "\n";
echo "Результаты сохранены в файл: " . OUTPUT_FILE . "\n";
echo "=================================================\n";
