<?php

require 'vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;

// =============================================================================
// НАСТРОЙКИ ПОИСКА
// =============================================================================
$searchParams = [
    'contractStage' => [0, 1, 2],
    'capitalDateOfContractConclusionFrom' => '01.01.2014',
    'capitalDateOfContractConclusionTo'   => '31.12.2025',
    'priceFrom' => '',
    'priceTo' => '',
    'searchString' => '',
    'morphology' => 'on',
    'recordsPerPage' => '_50',
    'sortDirection' => 'false',
    'sortBy' => 'UPDATE_DATE',
];

// =============================================================================
// КОНСТАНТЫ И ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ
// =============================================================================
const BASE_URL = 'https://zakupki.gov.ru/epz/capitalrepairs/search/results.html';
const OUTPUT_FILE = 'contracts.json';
$totalDaysInRange = 0;
$processedDays = 0;

// =============================================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// =============================================================================

function fetchUrl(string $url): string|false
{
    echo "Загрузка: " . substr($url, 0, 120) . "...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $html = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Ошибка cURL: ' . curl_error($ch) . "\n";
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    return $html;
}

function parseContractsFromPage(string $htmlContent): array
{
    $crawler = new Crawler($htmlContent);
    $entries = $crawler->filter('.search-registry-entry-block');
    $pageData = [];

    $entries->each(function (Crawler $entryNode) use (&$pageData) {
        $bodyInfo = [];
        $entryNode->filter('.registry-entry__body-block')->each(function (Crawler $block) use (&$bodyInfo) {
            $titleNode = $block->filter('.registry-entry__body-title');
            $valueNode = $block->filter('.registry-entry__body-value, .registry-entry__body-href');
            if ($titleNode->count() && $valueNode->count()) {
                $bodyInfo[trim($titleNode->text())] = trim($valueNode->text());
            }
        });

        $dates = [];
        $entryNode->filter('.data-block__title')->each(function (Crawler $titleNode) use (&$dates) {
            $valueNode = $titleNode->nextAll()->filter('.data-block__value')->first();
            if ($valueNode->count()) {
                $dates[trim($titleNode->text())] = trim($valueNode->text());
            }
        });

        $registryNumberNode = $entryNode->filter('.registry-entry__header-mid__number a');
        
        $pageData[] = [
            'registry_number' => $registryNumberNode->count() ? trim($registryNumberNode->text()) : null,
            'url' => $registryNumberNode->count() ? 'https://zakupki.gov.ru' . $registryNumberNode->attr('href') : null,
            'status' => $entryNode->filter('.registry-entry__header-mid__title')->count() ? trim($entryNode->filter('.registry-entry__header-mid__title')->text()) : null,
            'price' => $entryNode->filter('.price-block__value')->count() ? trim($entryNode->filter('.price-block__value')->text()) : null,
            'contract_number' => $bodyInfo['Номер договора'] ?? null,
            'customer' => $bodyInfo['Заказчик'] ?? null,
            'procurement_details' => $bodyInfo['Реквизиты закупки'] ?? null,
            'region' => $bodyInfo['Субъект РФ'] ?? null,
            'auction_subject' => $bodyInfo['Предмет электронного аукциона'] ?? null,
            'dates' => $dates
        ];
    });

    return $pageData;
}

function isLimitExceeded(string $htmlContent): bool
{
    $crawler = new Crawler($htmlContent);
    $totalNode = $crawler->filter('.search-results__total');
    return $totalNode->count() > 0 && str_contains(trim($totalNode->text()), 'более');
}

function getTotalPages(string $htmlContent): int
{
    $crawler = new Crawler($htmlContent);
    $lastPageNode = $crawler->filter('.paginator li:nth-last-child(2) a.page__link');

    if ($lastPageNode->count() > 0 && is_numeric(trim($lastPageNode->text()))) {
        return (int) $lastPageNode->text();
    }
    
    $activePageNode = $crawler->filter('.paginator .page__link_active');
    if ($activePageNode->count() > 0) {
        return (int) $activePageNode->text();
    }

    return $crawler->filter('.search-registry-entry-block')->count() > 0 ? 1 : 0;
}

function displayProgressBar(float $progress): void
{
    $barLength = 50;
    $progress = max(0, min(100, $progress));
    $filledLength = (int)($barLength * $progress / 100);
    $bar = str_repeat('=', $filledLength) . str_repeat('-', $barLength - $filledLength);
    printf("\rОбщий прогресс: [%s] %.2f%%", $bar, $progress);
}

/**
 * Основная рекурсивная функция для сбора данных с дроблением.
 */
function processDateRange(DateTime $startDate, DateTime $endDate, array $baseParams): array
{
    global $totalDaysInRange, $processedDays;

    echo "\n--- Проверка диапазона: " . $startDate->format('d.m.Y') . " - " . $endDate->format('d.m.Y') . " ---\n";

    $params = $baseParams;
    $params['capitalDateOfContractConclusionFrom'] = $startDate->format('d.m.Y');
    $params['capitalDateOfContractConclusionTo'] = $endDate->format('d.m.Y');
    $params['pageNumber'] = 1;

    $url = BASE_URL . '?' . http_build_query($params);
    $testHtml = fetchUrl($url);
    if (!$testHtml) return [];

    if (!isLimitExceeded($testHtml)) {
        $totalPages = getTotalPages($testHtml);
        echo "Диапазон ОК. Найдено страниц: $totalPages. Начинаем скачивание...\n";
        $allContractsInRange = parseContractsFromPage($testHtml);

        for ($page = 2; $page <= $totalPages; $page++) {
            printf("Скачиваем страницу [%d/%d]...\r", $page, $totalPages);
            $params['pageNumber'] = $page;
            $pageUrl = BASE_URL . '?' . http_build_query($params);
            sleep(1);
            $pageHtml = fetchUrl($pageUrl);
            if ($pageHtml) {
                $allContractsInRange = array_merge($allContractsInRange, parseContractsFromPage($pageHtml));
            }
        }
        echo "\nСкачивание для диапазона завершено.\n";
        
        $daysInChunk = $endDate->diff($startDate)->days + 1;
        $processedDays += $daysInChunk;
        if ($totalDaysInRange > 0) {
            $progress = ($processedDays / $totalDaysInRange) * 100;
            displayProgressBar($progress);
        }

        return $allContractsInRange;
    }

    $totalContracts = [];
    
    // Дробление временных интервалов
    if ($startDate->format('Y') !== $endDate->format('Y')) {
        echo "!!! Лимит превышен. Дробление на ГОДЫ...\n";
        $period = new DatePeriod($startDate, new DateInterval('P1Y'), (clone $endDate)->modify('+1 day'));
        foreach ($period as $dt) {
            $yearStartDate = ($dt == $startDate) ? $startDate : (clone $dt)->modify('first day of January');
            $yearEndDate = (clone $yearStartDate)->modify('last day of December');
            if ($yearEndDate > $endDate) $yearEndDate = $endDate;
            $totalContracts = array_merge($totalContracts, processDateRange($yearStartDate, $yearEndDate, $baseParams));
        }
    } elseif ($startDate->format('m') !== $endDate->format('m')) {
        echo "!!! Лимит превышен. Дробление на МЕСЯЦЫ...\n";
        $period = new DatePeriod($startDate, new DateInterval('P1M'), (clone $endDate)->modify('+1 day'));
        foreach ($period as $dt) {
            $monthStartDate = ($dt == $startDate) ? $startDate : (clone $dt)->modify('first day of this month');
            $monthEndDate = (clone $monthStartDate)->modify('last day of this month');
            if ($monthEndDate > $endDate) $monthEndDate = $endDate;
            $totalContracts = array_merge($totalContracts, processDateRange($monthStartDate, $monthEndDate, $baseParams));
        }
    } elseif ($startDate->diff($endDate)->days > 6) {
        echo "!!! Лимит превышен. Дробление на НЕДЕЛИ...\n";
        $period = new DatePeriod($startDate, new DateInterval('P1W'), (clone $endDate)->modify('+1 day'));
        foreach ($period as $dt) {
            $weekStartDate = $dt;
            $weekEndDate = (clone $weekStartDate)->modify('+6 days');
            if ($weekEndDate > $endDate) $weekEndDate = $endDate;
            $totalContracts = array_merge($totalContracts, processDateRange($weekStartDate, $weekEndDate, $baseParams));
        }
    } elseif ($startDate->diff($endDate)->days > 0) {
        echo "!!! Лимит превышен. Дробление на ДНИ...\n";
        $period = new DatePeriod($startDate, new DateInterval('P1D'), (clone $endDate)->modify('+1 day'));
        foreach ($period as $day) {
            $totalContracts = array_merge($totalContracts, processDateRange($day, clone $day, $baseParams));
        }
    } else {
        echo "\n!!! КРИТИЧЕСКАЯ ОШИБКА: Невозможно разбить диапазон дальше. Данные за " . $startDate->format('d.m.Y') . " слишком велики. Пропускаем этот день.\n";
    }
    
    return $totalContracts;
}

// --- ОСНОВНАЯ ЛОГИКА ЗАПУСКА ---

$startTime = microtime(true);
$allContracts = [];

$finalParams = $searchParams;
if (isset($finalParams['contractStage']) && is_array($finalParams['contractStage'])) {
    foreach ($finalParams['contractStage'] as $stage) {
        $finalParams["contractStage_$stage"] = 'on';
    }
    $finalParams['contractStage'] = implode(',', $finalParams['contractStage']);
}

if (!empty($searchParams['capitalDateOfContractConclusionFrom']) && !empty($searchParams['capitalDateOfContractConclusionTo'])) {
    $startDate = new DateTime($searchParams['capitalDateOfContractConclusionFrom']);
    $endDate = new DateTime($searchParams['capitalDateOfContractConclusionTo']);
    $totalDaysInRange = $endDate->diff($startDate)->days + 1;
    
    echo "Запускаем парсинг для диапазона дат с автоматическим дроблением...\n";
    displayProgressBar(0);
    $allContracts = processDateRange($startDate, $endDate, $finalParams);
} else {
    echo "\n--- Обработка запроса без диапазона дат (дробление невозможно) ---\n";
    $finalParams['pageNumber'] = 1;
    $testUrl = BASE_URL . '?' . http_build_query($finalParams);
    $testHtml = fetchUrl($testUrl);
    if ($testHtml) {
        if (isLimitExceeded($testHtml)) {
            echo "!!! Результатов слишком много ('более N'). Пожалуйста, укажите диапазон дат в настройках для сбора всех данных.\n";
        } else {
            $totalPages = getTotalPages($testHtml);
            echo "Найдено страниц: $totalPages. Начинаем скачивание...\n";
            $allContracts = parseContractsFromPage($testHtml);
            for ($page = 2; $page <= $totalPages; $page++) {
                printf("Скачиваем страницу [%d/%d]...\r", $page, $totalPages);
                $finalParams['pageNumber'] = $page;
                $pageUrl = BASE_URL . '?' . http_build_query($finalParams);
                sleep(1);
                $pageHtml = fetchUrl($pageUrl);
                if($pageHtml) {
                    $allContracts = array_merge($allContracts, parseContractsFromPage($pageHtml));
                }
            }
        }
    }
}

$endTime = microtime(true);
$executionTime = round($endTime - $startTime, 2);
$totalContracts = count($allContracts);

echo "\n\n=================================================\n";
echo "Парсинг завершен за $executionTime секунд.\n";
echo "Всего найдено уникальных контрактов: $totalContracts\n";

if ($totalContracts > 0) {
    file_put_contents(OUTPUT_FILE, json_encode($allContracts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Результаты сохранены в файл: " . OUTPUT_FILE . "\n";
} else {
    echo "Результаты не сохранены, так как не было найдено ни одного контракта.\n";
}
echo "=================================================\n";
