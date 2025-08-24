<?php

// =============================================================================
// КОНФИГУРАЦИЯ
// =============================================================================
const INPUT_FILE = 'contracts_enriched.json';
const OUTPUT_FILE = 'database_dump.sql';

// =============================================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// =============================================================================

function normalizePrice(?string $priceStr): ?float
{
    if (empty($priceStr)) return null;
    $cleaned = preg_replace('/[^\d,.]/', '', $priceStr);
    $cleaned = str_replace(',', '.', $cleaned);
    return is_numeric($cleaned) ? (float)$cleaned : null;
}

function normalizeDate(?string $dateStr): ?string
{
    if (empty($dateStr)) return null;
    $dateStr = trim($dateStr);
    if (!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $dateStr)) return null;
    try {
        return DateTime::createFromFormat('d.m.Y', $dateStr)->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
}

function normalizeDateTime(?string $dateTimeStr): ?string
{
    if (empty($dateTimeStr)) return null;
    $dateTimeStr = trim(preg_replace('/\s\(.*\)/', '', $dateTimeStr));
    if (!preg_match('/^\d{2}\.\d{2}\.\d{4}\s\d{2}:\d{2}$/', $dateTimeStr)) return null;
    try {
        return DateTime::createFromFormat('d.m.Y H:i', $dateTimeStr)->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function escapeSql(?string $value): string
{
    if ($value === null) return 'NULL';
    return "'" . addslashes($value) . "'";
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

// --- ОСНОВНАЯ ЛОГИКА ЗАПУСКА ---

echo "Запуск скрипта для создания полного MySQL дампа...\n";

if (!file_exists(INPUT_FILE)) die("Ошибка: Входной файл '" . INPUT_FILE . "' не найден.\n");
$sourceData = json_decode(file_get_contents(INPUT_FILE), true);
$totalContracts = count($sourceData);
if ($totalContracts === 0) die("Входной файл пуст.\n");
echo "Найдено $totalContracts записей для обработки.\n";

$dumpHandle = fopen(OUTPUT_FILE, 'w');
if (!$dumpHandle) die("Ошибка: Не удалось открыть файл '" . OUTPUT_FILE . "' для записи.\n");

fwrite($dumpHandle, "-- MySQL Dump\n-- Сгенерировано: " . date('Y-m-d H:i:s') . "\n\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSET time_zone = \"+00:00\";\n\n/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n/*!40101 SET NAMES utf8mb4 */;\n\nSTART TRANSACTION;\n\n");
fwrite($dumpHandle, "--\n-- Структура таблиц\n--\n\n");
fwrite($dumpHandle, "
DROP TABLE IF EXISTS `contracts`;
DROP TABLE IF EXISTS `procurements`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `regions`;

CREATE TABLE `customers` ( `id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(1000) NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `name` (`name`(255)) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `regions` ( `id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `name` (`name`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `procurements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reg_number` varchar(50) NOT NULL COMMENT 'Номер аукциона',
  `customer_id` int(11) DEFAULT NULL,
  `region_id` int(11) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL COMMENT 'Этап закупки',
  `procurement_object` text DEFAULT NULL COMMENT 'Объект закупки (из карточки закупки)',
  `procurement_name` text DEFAULT NULL COMMENT 'Наименование закупки',
  `procurement_subject` text DEFAULT NULL COMMENT 'Предмет электронного аукциона',
  `work_types` text DEFAULT NULL COMMENT 'Виды работ по ЖК',
  `initial_price` decimal(18,2) DEFAULT NULL,
  `application_security_amount` decimal(18,2) DEFAULT NULL COMMENT 'Обеспечение заявки',
  `contract_security_amount` decimal(18,2) DEFAULT NULL COMMENT 'Обеспечение исполнения',
  `auction_step_info` text DEFAULT NULL COMMENT 'Шаг аукциона',
  `published_at` date DEFAULT NULL,
  `updated_at` date DEFAULT NULL,
  `application_end_datetime` datetime DEFAULT NULL COMMENT 'Окончание подачи заявок',
  `review_end_date` date DEFAULT NULL COMMENT 'Окончание рассмотрения заявок',
  `auction_date` date DEFAULT NULL,
  `etp_name` varchar(255) DEFAULT NULL COMMENT 'Наименование ЭТП',
  `etp_url` varchar(255) DEFAULT NULL COMMENT 'Сайт ЭТП',
  `customer_address` varchar(500) DEFAULT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `work_location` text DEFAULT NULL COMMENT 'Место выполнения работ',
  `work_timeline` text DEFAULT NULL,
  `payment_conditions` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reg_number` (`reg_number`),
  KEY `customer_id` (`customer_id`),
  KEY `region_id` (`region_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `procurement_id` int(11) DEFAULT NULL,
  `registry_number` varchar(50) NOT NULL,
  `contract_number_text` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `price` decimal(18,2) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `conclusion_date` date DEFAULT NULL,
  `execution_end_date` date DEFAULT NULL,
  `placed_at` date DEFAULT NULL,
  `updated_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `registry_number` (`registry_number`),
  KEY `procurement_id` (`procurement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
\n\n");

echo "Начинаем обработку и генерацию INSERT-запросов...\n";
$customersCache = []; $regionsCache = []; $procurementsCache = []; $contractsCache = [];
$customerIdCounter = 1; $regionIdCounter = 1; $procurementIdCounter = 1; $uniqueContractsCount = 0;
displayProgressBar(0, $totalContracts);

foreach ($sourceData as $index => $contract) {
    preg_match('/(\d+)$/', $contract['registry_number'] ?? '', $matches);
    $contractRegNumber = $matches[1] ?? ($contract['registry_number'] ?? null);
    if (empty($contractRegNumber) || isset($contractsCache[$contractRegNumber])) {
        displayProgressBar($index + 1, $totalContracts);
        continue;
    }
    $contractsCache[$contractRegNumber] = true;
    $uniqueContractsCount++;

    $customerName = $contract['customer'] ?? null;
    if ($customerName && !isset($customersCache[$customerName])) {
        $customersCache[$customerName] = $customerIdCounter++;
        fwrite($dumpHandle, "INSERT INTO `customers` (`id`, `name`) VALUES (" . $customersCache[$customerName] . ", " . escapeSql($customerName) . ");\n");
    }
    $customerId = $customerName ? $customersCache[$customerName] : 'NULL';

    $regionName = $contract['region'] ?? null;
    if ($regionName && !isset($regionsCache[$regionName])) {
        $regionsCache[$regionName] = $regionIdCounter++;
        fwrite($dumpHandle, "INSERT INTO `regions` (`id`, `name`) VALUES (" . $regionsCache[$regionName] . ", " . escapeSql($regionName) . ");\n");
    }
    $regionId = $regionName ? $regionsCache[$regionName] : 'NULL';

    $procurementId = 'NULL';
    preg_match('/№\s*(\d+)/', $contract['procurement_details'] ?? '', $matches);
    $procRegNumber = $matches[1] ?? null;

    if ($procRegNumber && !isset($procurementsCache[$procRegNumber])) {
        $procurementsCache[$procRegNumber] = $procurementIdCounter++;
        $procurementId = $procurementsCache[$procRegNumber];
        
        $p = $contract['procurement_info'] ?? [];
        $p_main = $p['procurement_main_info'] ?? [];
        $p_general = $p['Общая информация о закупке'] ?? [];
        $p_subject = $p['Предмет электронного аукциона'] ?? [];
        $p_customer = $p['Информация о заказчике'] ?? [];
        $p_procedure = $p['Информация о процедуре электронного аукциона'] ?? [];
        $p_conditions = $p['Условия договора'] ?? [];
        
        $sql = "INSERT INTO `procurements` (`id`, `reg_number`, `customer_id`, `region_id`, `status`, `procurement_object`, `procurement_name`, `procurement_subject`, `work_types`, `initial_price`, `application_security_amount`, `contract_security_amount`, `auction_step_info`, `published_at`, `updated_at`, `application_end_datetime`, `review_end_date`, `auction_date`, `etp_name`, `etp_url`, `customer_address`, `customer_email`, `customer_phone`, `contact_person`, `work_location`, `work_timeline`, `payment_conditions`) VALUES (";
        $sql .= $procurementId . ", ";
        $sql .= escapeSql($procRegNumber) . ", ";
        $sql .= $customerId . ", ";
        $sql .= $regionId . ", ";
        $sql .= escapeSql($p_main['status'] ?? null) . ", ";
        $sql .= escapeSql($p_main['object'] ?? null) . ", ";
        $sql .= escapeSql($p_general['Наименование закупки'] ?? null) . ", ";
        $sql .= escapeSql($contract['auction_subject'] ?? null) . ", ";
        $sql .= escapeSql($p_subject['Виды работ в соответствии с ч.1 ст. 166 Жилищного кодекса'] ?? null) . ", ";
        $sql .= (normalizePrice($p_main['initial_price'] ?? null) ?? 'NULL') . ", ";
        $sql .= (normalizePrice($p_conditions['Размер обеспечения заявки на участие в электронном аукционе'] ?? null) ?? 'NULL') . ", ";
        $sql .= (normalizePrice($p_conditions['Размер обеспечения исполнения обязательств по договору'] ?? null) ?? 'NULL') . ", ";
        $sql .= escapeSql($p_conditions['Шаг аукциона'] ?? null) . ", ";
        $sql .= escapeSql(normalizeDate($p_main['published_date'] ?? null)) . ", ";
        $sql .= escapeSql(normalizeDate($p_main['updated_date'] ?? null)) . ", ";
        $sql .= escapeSql(normalizeDateTime($p_procedure['Дата и время окончания срока подачи заявок на участие в электронном аукционе'] ?? null)) . ", ";
        $sql .= escapeSql(normalizeDate($p_procedure['Дата окончания срока рассмотрения заявок на участие в электронном аукционе'] ?? null)) . ", ";
        $sql .= escapeSql(normalizeDate($p_procedure['Дата проведения электронного аукциона'] ?? null)) . ", ";
        $sql .= escapeSql($p_general['Наименование электронной площадки в сети «Интернет»'] ?? null) . ", ";
        $sql .= escapeSql($p_general['Сайт оператора электронной площадки в сети «Интернет»'] ?? null) . ", ";
        $sql .= escapeSql($p_customer['Адрес'] ?? null) . ", ";
        $sql .= escapeSql($p_customer['Адрес электронной почты'] ?? null) . ", ";
        $sql .= escapeSql($p_customer['Номер телефона'] ?? null) . ", ";
        $sql .= escapeSql($p_customer['Контактное лицо'] ?? null) . ", ";
        $sql .= escapeSql($p_conditions['Место выполнения работ и (или) оказания услуг'] ?? null) . ", ";
        $sql .= escapeSql($p_conditions['Сроки выполнения работ и (или) оказания услуг'] ?? null) . ", ";
        $sql .= escapeSql($p_conditions['Условия оплаты выполненных работ и (или) оказанных услуг'] ?? null);
        $sql .= ");\n";
        fwrite($dumpHandle, $sql);
    } elseif ($procRegNumber) {
        $procurementId = $procurementsCache[$procRegNumber];
    }
    
    $sql = "INSERT INTO `contracts` (`id`, `procurement_id`, `registry_number`, `contract_number_text`, `status`, `price`, `url`, `conclusion_date`, `execution_end_date`, `placed_at`, `updated_at`) VALUES (";
    $sql .= 'NULL' . ", ";
    $sql .= $procurementId . ", ";
    $sql .= escapeSql($contractRegNumber) . ", ";
    $sql .= escapeSql($contract['contract_number'] ?? null) . ", ";
    $sql .= escapeSql($contract['status'] ?? null) . ", ";
    $sql .= (normalizePrice($contract['price'] ?? null) ?? 'NULL') . ", ";
    $sql .= escapeSql($contract['url'] ?? null) . ", ";
    $sql .= escapeSql(normalizeDate($contract['dates']['Заключение договора'] ?? null)) . ", ";
    $sql .= escapeSql(normalizeDate($contract['dates']['Окончание исполнения'] ?? null)) . ", ";
    $sql .= escapeSql(normalizeDate($contract['dates']['Размещено'] ?? null)) . ", ";
    $sql .= escapeSql(normalizeDate($contract['dates']['Обновлено'] ?? null));
    $sql .= ");\n";
    fwrite($dumpHandle, $sql);
    
    displayProgressBar($index + 1, $totalContracts);
}

fwrite($dumpHandle, "\n--\n-- Ограничения внешнего ключа\n--\n");
fwrite($dumpHandle, "ALTER TABLE `contracts` ADD CONSTRAINT `contracts_ibfk_1` FOREIGN KEY (`procurement_id`) REFERENCES `procurements` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;\n");
fwrite($dumpHandle, "ALTER TABLE `procurements` ADD CONSTRAINT `procurements_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;\n");
fwrite($dumpHandle, "ALTER TABLE `procurements` ADD CONSTRAINT `procurements_ibfk_2` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;\n");

fwrite($dumpHandle, "\nCOMMIT;\n\n");
fwrite($dumpHandle, "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
fwrite($dumpHandle, "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
fwrite($dumpHandle, "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");

fclose($dumpHandle);

echo "\n\n=================================================\n";
echo "Создание дампа успешно завершено!\n";
echo "Обработано записей: $totalContracts. Уникальных контрактов: $uniqueContractsCount\n";
echo "Файл сохранен: " . OUTPUT_FILE . "\n";
echo "=================================================\n";
