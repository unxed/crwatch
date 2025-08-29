<?php
/**
 * Парсер для извлечения отдельных адресов из одного текстового блока.
 *
 * --- ОСНОВНЫЕ ПРИНЦИПЫ И ОГРАНИЧЕНИЯ (приняты на старте) ---
 * 1.  НЕ ИСПОЛЬЗОВАТЬ РЕГУЛЯРНЫЕ ВЫРАЖЕНИЯ: Для парсинга и извлечения данных.
 * 2.  НЕ ИСПОЛЬЗОВАТЬ ТОКЕНИЗАЦИЮ/EXPLODE: Предварительное разбиение всей строки на "токены" (слова)
 *     признано негибким. Парсер должен работать с "чанками" (фрагментами между разделителями).
 * 3.  ПОШАГОВЫЙ АНАЛИЗ: Двигаться по строке, анализируя ее по частям, принимая решения
 *     на основе текущего состояния (контекста) и следующего фрагмента.
 *
 * --- ЭВОЛЮЦИЯ РЕШЕНИЙ И ЛОГИКИ ---
 *
 * Версия 1-3: "Жадный" поиск и работа с "чанками"
 * - Идея: Искать следующий маркер (например, "ул.") во всей оставшейся части строки.
 * - Проблема: "Жадный" поиск находил далекие маркеры и ошибочно считал весь текст до них частью одного компонента.
 * - Решение: Отказаться от глобального поиска. Работать с небольшими фрагментами ("чанками") между разделителями.
 *   Уточнен поиск маркера: он должен быть отдельным словом, а не частью другого (например, 'город' в 'НижеГОРОДская').
 *
 * Версия 4: Неоднозначный маркер "д." (дом или деревня)
 * - Проблема: Как понять, когда "д." - это дом, а когда - деревня?
 * - Решение: Ввести контекстную проверку. Если в адресе уже есть улица, то "д." - это дом. Иначе - деревня.
 *   (Позже эта логика была значительно улучшена).
 *
 * Версия 5-8: "Висячие" дома и дополнения
 * - Проблема: В перечислениях "ул. Ленина, д. 5; д. 6" терялся контекст улицы. "литера А" начинала новый адрес.
 * - Решение: Если новый компонент - это дом, а предыдущий тоже был домом, сохранять контекст до уровня дома.
 *   Дополнения (без маркера "д.") стали приклеиваться к существующему дому, а не начинать новый адрес.
 *
 * Версия 9-13: Проблема "30 г. Знаменск" и механизм пере-постановки в очередь (re-queuing)
 * - Проблема: В чанке "30 г. Знаменск" часть "30" относится к предыдущему адресу, а "г. Знаменск" начинает новый.
 * - Решение (Ключевой архитектурный сдвиг): Ввести механизм "re-queuing". Если в чанке найден маркер не в начале,
 *   чанк разделяется на две части. Обе части возвращаются в НАЧАЛО очереди для обработки в следующих итерациях.
 *   Это гарантирует, что каждый компонент будет обработан с корректным, только что обновленным контекстом.
 *
 * Версия 14-21: Логика "взгляда вперёд" (Lookahead) и финальная эвристика разделения
 * - Проблема: Как правильно собрать "5-я Советская" + "ул.", но при этом разделить "30" + "г. Знаменск"?
 * - Решение: Ввести LOOKAHEAD. Если текущий чанк не имеет маркера, парсер "заглядывает" в следующий. Если там
 *   логичный маркер-продолжение, чанки объединяются. После долгих итераций была найдена финальная эвристика
 *   для разделения: чанк делится, только если часть *до* маркера сама похожа на компонент дома (содержит цифры).
 *
 * Версия 22-24: Улучшение эвристик для "д." и "г"
 * - Проблема: "д. Щиглицы" после улицы ошибочно считался домом. "25 Г" ошибочно считался домом и новым городом.
 * - Решение: Уточнена эвристика для "д.": теперь он считается домом, только если есть улица И в чанке есть цифры.
 *   Добавлена контекстная проверка для "г": если перед ним стоит цифра, он игнорируется как маркер города.
 *
 * Версия 25-33: Обработка "мусорных" данных
 * - Проблема: Строки без адресов или с префиксами ("Позиция: ...") ломали логику.
 * - Решение: Добавлена очистка префиксов до ':'. Введено финальное правило: возвращать исходную строку, если
 *   не удалось извлечь несколько полноценных адресов с домами. Добавлена обработка нумераторов "1)".
 *
 * Версия 34-39: "Качели" с точкой с запятой
 * - Проблема: Попытки сделать ';' "жестким" или "мягким" разделителем ломали либо один, либо другой тестовый кейс.
 * - Решение: Возврат к простой логике. Точка с запятой - обычный разделитель. Логика "висячих домов" и
 *   предотвращение сохранения дубликатов оказались достаточными для корректной обработки.
 *
 * Версия 40-45: Финальная стабилизация
 * - Проблема: "Качели" с правилами разделения чанков. Исправление "Тульская область" ломало "30 г. Знаменск".
 * - Решение: Найдена единая, стабильная эвристика: чанк разрывается по маркеру высокого уровня, только если
 *   часть *до* маркера похожа на компонент дома (`isHouseComponent`). Это решило все конфликты.
 * - Проблема: Обнаружен бесконечный цикл из-за слишком агрессивной пост-обработки "склеенных" чанков.
 * - Решение: Пост-обработка была упрощена и оставлена только для самого частого случая ("дом внутри улицы"),
 *   чтобы предотвратить зацикливание на редких форматах.
 *
 * Версия 58 (финальная, с "умным сбросом"):
 * - Противоречие: Парсер не мог понять, когда новая улица относится к предыдущему "под-городу" (как в Колпино),
 *   а когда означает выход из "под-города" и возврат к основному (как в Стрельне).
 * - Решение: Внедрена новая механика "основной город/подгород".
 *   1.  Города федерального значения (Санкт-Петербург, Москва) повышены до уровня РЕГИОНА.
 *   2.  Компоненты типа "г. Колпино" или "пос. Стрельна" внутри них считаются "под-городами" (LEVEL_CITY).
 *   3.  Ключевое правило: когда адрес с "под-городом" завершается и начинается новый адрес с улицы,
 *       парсер принудительно сбрасывает контекст "под-города" (LEVEL_CITY), возвращаясь к основному городу-региону.
 *   Это позволяет правильно обрабатывать вложенные структуры адресов, решая конфликтующие кейсы `short 1` и `СПб 2`.
 */

define("MAX_ITERATIONS", 4000); // Максимально допустимое количество итераций разбора адреса
define("JUNK_CHUNK_LENGTH_THRESHOLD", 70); // Порог длины чанка, после которого он может считаться "мусором"

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

require_once 'test.data.php';

// --- ИМЕНОВАННЫЕ КОНСТАНТЫ ДЛЯ УРОВНЕЙ ИЕРАРХИИ ---
// Используем константы для читаемости кода. Легко понять, какой уровень адреса имеется в виду.
const LEVEL_COUNTRY = 0;  // Страна
const LEVEL_REGION = 1;   // Область, республика, край
const LEVEL_DISTRICT = 2; // Район
const LEVEL_CITY = 3;     // Город, село, деревня, поселок
const LEVEL_STREET = 4;   // Улица, проспект, переулок
const LEVEL_HOUSE = 5;    // Дом, корпус, литера

// --- Глобальные переменные для логгирования ---
$logAiHandle = null;
$logManHandle = null;

/**
 * Пишет сообщение в лог для отладки (для "искусственного интеллекта").
 * @param string $message Сообщение для записи.
 */
function log_ai(string $message): void
{
    global $logAiHandle;
    fwrite($logAiHandle, $message . "\n");
}

// --- ФУНКЦИИ-ПОМОЩНИКИ БЕЗ РЕГУЛЯРОК ---

/**
 * Проверяет, состоит ли строка из одного слова (без пробелов внутри).
 * @param string $str Проверяемая строка.
 * @return bool True, если в строке нет пробелов.
 */
function isSingleWord(string $str): bool
{
    return mb_strpos(trim($str), ' ') === false;
}

/**
 * Проверяет, есть ли в строке хотя бы одна цифра.
 * @param string $str Проверяемая строка.
 * @return bool True, если найдена хотя бы одна цифра.
 */
function containsDigits(string $str): bool
{
    // strcspn ищет первый символ, НЕ входящий в набор '0123456789'.
    // Если она возвращает длину строки, значит, цифр не было найдено.
    return strcspn($str, '0123456789') !== strlen($str);
}

/**
 * Ищет в середине строки маркеры нумерованного списка, такие как " 1) ", " 2) ".
 * @param string $chunk Фрагмент адреса для проверки.
 * @return int|null Позиция начала маркера или null, если не найден.
 */
function findNumericListMarker(string $chunk): ?int
{
    $len = mb_strlen($chunk);
    // Начинаем с 1, так как маркер в самом начале обработает removeLeadingNoise.
    for ($i = 1; $i < $len; $i++) {
        if (mb_substr($chunk, $i, 1) === ')') {
            if (is_numeric(mb_substr($chunk, $i - 1, 1))) {
                // Нашли "цифра)". Теперь найдем начало этой цифровой последовательности.
                $startPos = $i - 1;
                while ($startPos > 0 && is_numeric(mb_substr($chunk, $startPos - 1, 1))) {
                    $startPos--;
                }
                // Убедимся, что перед нумератором стоит пробел.
                if ($startPos > 0 && mb_substr($chunk, $startPos - 1, 1) === ' ') {
                    return $startPos;
                }
            }
        }
    }
    return null;
}


/**
 * Заменяет последнее слово в строке на новое.
 * Используется для создания новых "висячих" литер (например, из "д. 10 литера А" сделать "д. 10 литера Б").
 * @param string $haystack Исходная строка.
 * @param string $newWord Новое слово для замены.
 * @return string Строка с замененным последним словом.
 */
function replaceLastWord(string $haystack, string $newWord): string
{
    $lastSpacePos = mb_strrpos($haystack, ' ');
    if ($lastSpacePos === false) {
        return $newWord; // Если пробелов нет, вся строка - одно слово.
    }
    $base = mb_substr($haystack, 0, $lastSpacePos);
    return $base . ' ' . $newWord;
}

/**
 * Удаляет из начала строки "мусорные" префиксы-нумераторы, такие как "1. " или "2) ".
 * @param string $chunk Фрагмент для очистки.
 * @return string Очищенный фрагмент.
 */
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
            // "Безопасное" удаление. Если удалили всё (например, чанк был "44."), отменяем.
            if ($originalChunk !== '' && $result === '') {
                return $originalChunk;
            }
            return $result;
        }
    }
    return $chunk;
}

/**
 * Проверяет наличие букв в строке (нужно для эвристики "д.").
 * @param string $str Строка для проверки.
 * @return bool True, если есть буквы.
 */
function containsLetters(string $str): bool
{
    return preg_match('/\p{L}/u', $str) > 0;
}

/**
 * Ищет первый валидный маркер в чанке.
 * @param string $chunk Фрагмент адреса.
 * @param array $markers Список маркеров.
 * @param array $ambiguousMarkers Не используется (устарело).
 * @param array $currentAddressParts Текущий собираемый адрес для контекста.
 * @return array|null Информация о маркере ['marker', 'level', 'pos'] или null.
 */
function findMarkerInChunk(string $chunk, array $markers, array $ambiguousMarkers, array $currentAddressParts): ?array
{
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

            // Маркер должен быть отдельным словом
            if (in_array($before, [' ', '(']) && $isAfterOk) {

                // Контекстная проверка для маркера "г" (город или литера Г)
                if ($marker === 'г' || $marker === 'г.') {
                    if ($pos > 0) {
                        $chunkBeforeMarker = trim(mb_substr($chunk, 0, $pos));
                        if (!empty($chunkBeforeMarker) && is_numeric(mb_substr($chunkBeforeMarker, -1))) {
                            // Условие: перед маркером стоит цифра.
                            // Теперь проверяем, что идет ПОСЛЕ, чтобы отличить "25 Г" от "30 г. Знаменск".
                            $contentAfterMarker = trim(mb_substr($chunk, $pos + $markerLen));

                            // Игнорируем маркер (считаем его литерой), только если после него НЕТ слова.
                            // Пустая строка или одиночная буква - это не слово.
                            if (empty($contentAfterMarker) || !containsLetters($contentAfterMarker)) {
                                log_ai("Marker '{$marker}' is preceded by a digit and NOT followed by a word. Assuming it's a building letter. Ignoring.");
                                continue; // Это литера, а не город, пропускаем
                            }
                        }
                    }
                }

                // Контекстная проверка для "п." (поселок или пункт)
                if ($marker === 'п.' || $marker === 'п') {
                    $contentAfter = trim(mb_substr($chunk, $pos + $markerLen));
                    if (!empty($contentAfter) && is_numeric(mb_substr($contentAfter, 0, 1))) {
                        log_ai("Contextual 'п.': Marker is followed by a digit. Assuming 'пункт', not 'поселок'. Ignoring.");
                        continue;
                    }
                }

                $currentLevel = $level;
                // Контекстная проверка для "д." (дом или деревня)
                if ($marker === 'д.') {
                    // Считаем домом, если есть улица ИЛИ город, И в остатке есть цифры
                    $hasLocationContext = isset($currentAddressParts[LEVEL_STREET]) || isset($currentAddressParts[LEVEL_CITY]);
                    $chunkWithoutMarker = trim(str_ireplace('д.', '', $chunk));
                    if ($hasLocationContext && containsDigits($chunkWithoutMarker)) {
                         $currentLevel = LEVEL_HOUSE;
                         log_ai("Contextual 'д.': Recognized as HOUSE because location context exists and chunk has digits.");
                    } else {
                         $currentLevel = LEVEL_CITY;
                         log_ai("Contextual 'д.': Recognized as CITY because no street/city context or no digits in chunk.");
                    }
                }
                return ['marker' => $marker, 'level' => $currentLevel, 'pos' => $pos];
            }
        }
    }
    return null;
}

/**
 * Проверяет, содержит ли строка ключевые слова для дополнений к дому (корпус, литера и т.д.).
 * @param string $str Строка для проверки.
 * @return bool True, если найдено ключевое слово.
 */
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

/**
 * Эвристика для определения, является ли чанк без маркера компонентом дома.
 * @param string $component Фрагмент для проверки.
 * @return bool True, если похож на компонент дома.
 */
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
    
    // Если цифр нет, но есть буквы - это дом, только если это одиночная буква (литера)
    if (!$hasDigits && containsLetters($component)) {
        if (mb_strlen(trim($component)) === 1) {
            return true;
        }
        return false;
    }

    // Если есть цифры или буквы (например, одиночная литера) - это может быть дом.
    return $hasDigits || containsLetters($component);
}

/**
 * Более строгая проверка, является ли чанк компонентом дома.
 * Используется в lookahead, чтобы избежать ложных срабатываний.
 * @param string $component Фрагмент для проверки.
 * @param array $markers Список маркеров.
 * @return bool True, если в чанке есть явный маркер дома ('д.', 'корп.' и т.д.).
 */
function isClearlyHouseComponent(string $component, array $markers): bool
{
    foreach ($markers as $marker => $level) {
        if ($level === LEVEL_HOUSE) {
            // Используем mb_stripos для простой проверки наличия
            if (mb_stripos($component, $marker) !== false) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Проверяет, является ли входная строка "мусором" (описательным текстом).
 * @param array $chunks Массив чанков для анализа.
 * @param array $markers Список маркеров.
 * @param array $ambiguousMarkers Устарело.
 * @return bool True, если строка похожа на "мусор".
 */
function isJunkInput(array $chunks, array $markers, array $ambiguousMarkers): bool
{
    foreach ($chunks as $chunk) {
        if (mb_strlen($chunk) > JUNK_CHUNK_LENGTH_THRESHOLD) {
            // Используем надежную проверку, чтобы не найти маркер внутри слова
            if (findMarkerInChunk($chunk, $markers, $ambiguousMarkers, []) === null) {
                log_ai("Junk detected: Chunk '{$chunk}' is long and has no valid address markers.");
                return true;
            }
        }
    }
    return false;
}

/**
 * Основная функция для разделения блока адресов на отдельные адреса.
 */
function splitAddresses(string $addressBlock): array
{
    // --- Конфигурация парсера ---
    // Все известные нам маркеры и их уровни иерархии.
    // Сортируем по длине, чтобы сначала искались длинные маркеры ("р. п.") а не короткие ("п.").
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

    // Города федерального значения, которые могут быть без маркера "г.".
    // Повышены до уровня РЕГИОНА, чтобы могли содержать "под-города".
    $markerlessKeywords = ['Санкт-Петербург' => LEVEL_REGION, 'Москва' => LEVEL_REGION, 'Севастополь' => LEVEL_REGION];
    
    // --- Переменные состояния парсера ---
    $results = [];             // Массив с готовыми, разобранными адресами.
    $currentAddressParts = []; // Ассоциативный массив [уровень => компонент] для текущего собираемого адреса.
    $lastLevel = -1;           // Уровень иерархии последнего обработанного компонента.
    $foundAtLeastOneHouse = false; // Флаг, был ли найден хотя бы один дом во всем блоке.
    $lastHouseComponent = null;  // Хранит текст последнего компонента дома для "висячих" литер.
    $inParenthesesMode = false;  // Флаг, находимся ли мы внутри (...) для сбора всего содержимого.
    $iterations = 0;             // Счетчик итераций для предотвращения бесконечных циклов.
    $infiniteLoopDetected = false; // Флаг, который установится в true при обнаружении цикла.

    // --- 1. Начальное формирование очереди обработки ---
    // Разбиваем весь входной блок на "чанки" по разделителям.
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

    // --- Предварительная проверка на "мусор" ---
    if (isJunkInput($processingQueue, $markers, $ambiguousMarkers)) {
        $GLOBALS['parse_reason'] = "Обнаружены неадресные данные";
        return [$addressBlock];
    }

    // --- 2. Основной цикл обработки очереди ---
    while (!empty($processingQueue)) {
        $iterations++;
        if ($iterations > MAX_ITERATIONS) {
            log_ai("!!! ОШИБКА: Обнаружен бесконечный цикл после " . MAX_ITERATIONS . " итераций. Прерывание.");
            $infiniteLoopDetected = true;
            break;
        }

        $chunk = array_shift($processingQueue);

        // --- "Умная" очистка чанка от контекста ---
        if (!empty($currentAddressParts)) {
            foreach ($currentAddressParts as $part) {
                $part = trim($part);
                $partLen = mb_strlen($part);

                // Условие 1: чанк начинается с известной части
                if (mb_stripos($chunk, $part) === 0) {
                    // Условие 2: это полное совпадение ИЛИ после совпадения идет пробел
                    $isWholeWordMatch = (mb_strlen($chunk) === $partLen) || (mb_substr($chunk, $partLen, 1) === ' ');

                    if ($isWholeWordMatch) {
                        $cleanerChunk = trim(mb_substr($chunk, $partLen));
                        if ($cleanerChunk !== '') {
                            log_ai("Smart context cleanup: Removed whole-word prefix '{$part}' from chunk '{$chunk}'. New chunk: '{$cleanerChunk}'");
                            $chunk = $cleanerChunk;
                            break;
                        }
                    }
                }
            }
        }

        // --- Обработка специальных состояний ---
        // Если мы внутри скобок, просто приклеиваем чанк к последнему компоненту.
        if ($inParenthesesMode) {
            $lastKey = !empty($currentAddressParts) ? array_key_last($currentAddressParts) : null;
            if ($lastKey !== null) {
                $currentAddressParts[$lastKey] .= '; ' . $chunk;
                log_ai("In parentheses mode: Appending '{$chunk}' to level {$lastKey}. New value: '{$currentAddressParts[$lastKey]}'");
            }
            if (mb_strpos($chunk, ')') !== false) {
                log_ai("Closing parenthesis found. Exiting parentheses mode.");
                $inParenthesesMode = false;
                if($lastKey === LEVEL_HOUSE) $lastHouseComponent = $currentAddressParts[$lastKey];
            }
            continue;
        }

        // --- Предварительная обработка и разделение чанка ---
        // Ищем маркеры-разделители, которые могут быть в середине чанка.
        if (($numericMarkerPos = findNumericListMarker($chunk)) !== null) {
            $part1 = trim(mb_substr($chunk, 0, $numericMarkerPos));
            $part2 = trim(mb_substr($chunk, $numericMarkerPos));
            log_ai("Chunk split by numeric list marker: '{$part1}' and '{$part2}'. Re-queuing.");
            if ($part2 !== '') array_unshift($processingQueue, $part2);
            if ($part1 !== '') array_unshift($processingQueue, $part1);
            continue;
        }

        if (($colonPos = mb_strpos($chunk, ':')) !== false) {
            $chunk = trim(mb_substr($chunk, $colonPos + 1));
            if (empty($chunk)) continue;
        }

        log_ai("--- PROCESSING CHUNK: '{$chunk}' ---");

        // --- Декомпозиция только для СВЕРХсложных чанков (порог >= 3) ---
        $tempChunk = $chunk;
        $offset = 0;
        $markersInChunk = [];

        // Создаем временный список маркеров БЕЗ коротких неоднозначных для более надежного подсчета
        $tempMarkersForCounting = $markers;
        unset($tempMarkersForCounting['п.']);
        unset($tempMarkersForCounting['п']);
        unset($tempMarkersForCounting['г']);

        // Считаем, сколько маркеров в этом чанке
        while (true) {
            $markerInfo = findMarkerInChunk($tempChunk, $tempMarkersForCounting, $ambiguousMarkers, $currentAddressParts);
            if ($markerInfo === null) {
                break; // Маркеров больше нет
            }

            // Сохраняем информацию о маркере и его реальной позиции в исходном чанке
            $markerInfo['real_pos'] = $offset + $markerInfo['pos'];
            $markersInChunk[] = $markerInfo;

            // Сдвигаем позицию для поиска в оставшейся части строки
            $newOffset = $markerInfo['pos'] + mb_strlen($markerInfo['marker']);
            $offset += $newOffset;
            $tempChunk = mb_substr($tempChunk, $newOffset);

            if (empty($tempChunk)) {
                break;
            }
        }

        // ПРИМЕНЯЕМ ПРАВИЛО: если маркеров 3 или больше, это точно "склеенный" адрес. Делим его.
        if (count($markersInChunk) >= 3) {
            // Нам нужно разрезать чанк после первого компонента.
            // Граница разреза - это позиция начала ВТОРОГО маркера.
            $splitPos = $markersInChunk[1]['real_pos'];

            $part1 = trim(mb_substr($chunk, 0, $splitPos));
            $part2 = trim(mb_substr($chunk, $splitPos));

            if ($part1 !== '' && $part2 !== '') {
                log_ai("Decomposition by threshold (>=3): Chunk has too many markers. Splitting into '{$part1}' and '{$part2}'. Re-queuing.");
                // Возвращаем обе части в начало очереди
                array_unshift($processingQueue, $part2);
                array_unshift($processingQueue, $part1);
                continue; // Переходим к следующей итерации, чтобы обработать part1
            }
        }

        $cleanComponent = removeLeadingNoise($chunk);

        // Ключевая логика разделения "склеенных" чанков.
        $markerInfo = findMarkerInChunk($cleanComponent, $markers, $ambiguousMarkers, $currentAddressParts);
        $pos = $markerInfo['pos'] ?? -1;
        if ($pos > 0) {
            $part1 = trim(mb_substr($cleanComponent, 0, $pos));
            // Разрываем, только если часть до маркера похожа на конец адреса (т.е. на компонент дома).
            if ($markerInfo['level'] < LEVEL_STREET && isHouseComponent($part1)) {
                $part2 = trim(mb_substr($cleanComponent, $pos));
                log_ai("Chunk split by high-level marker ('{$markerInfo['marker']}'): '{$part1}' and '{$part2}'. Re-queuing.");
                array_unshift($processingQueue, $part2);
                array_unshift($processingQueue, $part1);
                continue;
            } else {
                 log_ai("Split blocked: marker level >= STREET, or part before marker is not a house component.");
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
                                log_ai("LOOKAHEAD OVERRIDE: Combining single word '{$cleanComponent}' with next marker despite level drop.");
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
                    // Исправлен порядок проверок для правильной обработки территорий
                    if (isset($currentAddressParts[LEVEL_CITY]) && $lastLevel >= LEVEL_CITY && !isHouseComponent($cleanComponent)) {
                        $currentAddressParts[LEVEL_CITY] .= ', ' . $cleanComponent;
                        log_ai("Appending unmarked sub-locality '{$cleanComponent}' to existing city component.");
                        continue;
                    }
                    else if (!empty($processingQueue) && isClearlyHouseComponent($processingQueue[0], $markers) && !containsDigits($cleanComponent)) {
                        log_ai("Lookahead for hanging street: Chunk '{$cleanComponent}' is followed by clear house component '{$processingQueue[0]}'. Assuming current is a street.");
                        $currentLevel = LEVEL_STREET;
                    }
                    else if ($lastLevel === LEVEL_HOUSE && mb_strlen($cleanComponent) <= 2) {
                        $currentLevel = LEVEL_HOUSE;
                    }
                    else if (isHouseComponent($cleanComponent)) {
                        $currentLevel = LEVEL_HOUSE;
                    } else {
                        // МАКСИМАЛЬНО СПЕЦИФИЧНАЯ ЭВРИСТИКА:
                        // Если предыдущий компонент был регионом (или районом),
                        // а текущий чанк без маркера похож на название города (нет цифр, не похож на дом),
                        // то это, скорее всего, и есть город.
                        if (($lastLevel === LEVEL_REGION || $lastLevel === LEVEL_DISTRICT) && !containsDigits($cleanComponent) && !containsHouseKeyword($cleanComponent)) {
                            $currentLevel = LEVEL_CITY;
                            log_ai("Heuristic guess: Unmarked component '{$cleanComponent}' after a region/district is assumed to be a city.");
                        } else {
                            log_ai("No marker/digits/keywords. Skipping as noise: '{$cleanComponent}'");
                            continue;
                        }
                    }
                }
            }
        }

        if ($currentLevel === LEVEL_HOUSE) $foundAtLeastOneHouse = true;

        // --- 4. Принятие решения: новый адрес или часть текущего? ---

        // Шаг 1: Принимаем решение по основному правилу.
        $isNewAddress = ($lastLevel !== -1 && $currentLevel <= $lastLevel);

        // Шаг 2: Создаем ИСКЛЮЧЕНИЕ для вложенных городов.
        // Если основное правило решило, что это новый адрес, но это просто один город
        // после другого (например, Саяногорск -> Черемушки), то отменяем это решение.
        if ($isNewAddress && $currentLevel === LEVEL_CITY && $lastLevel === LEVEL_CITY) {
            $isNewAddress = false;
            log_ai("OVERRIDE: City-follows-city transition detected. Treating as part of the SAME address, not a new one.");
        }

        $newHousePartFromHanging = null; 

        if ($isNewAddress && $currentLevel == LEVEL_HOUSE && $lastLevel == LEVEL_HOUSE) {
            $isSupplementToHouse = true;
            $markerInfo = findMarkerInChunk($cleanComponent, $markers, $ambiguousMarkers, $currentAddressParts);
            if ($markerInfo && in_array($markerInfo['marker'], ['д.', 'дом'])) {
                $isSupplementToHouse = false;
            }
            if ($isSupplementToHouse) {
                if (containsHouseKeyword($cleanComponent)) {
                    $isNewAddress = false; // Это дополнение (корпус), а не новый адрес.
                } else {
                    // Это "висячая" литера, создаем для нее новый компонент дома.
                    if ($lastHouseComponent) {
                         $newHousePartFromHanging = replaceLastWord($lastHouseComponent, $cleanComponent);
                    }
                }
            }
        }

        // --- 5. Сборка адреса ---
        if ($isNewAddress) {
            // Финализируем предыдущий адрес, если он не был только что сохранен.
            $builtAddress = '';
            if (!empty($currentAddressParts) && (isset($currentAddressParts[LEVEL_STREET]) || isset($currentAddressParts[LEVEL_HOUSE]))) {
                 ksort($currentAddressParts);
                 $builtAddress = implode(', ', $currentAddressParts);
            }
            if ($builtAddress && (empty($results) || end($results) !== $builtAddress)) {
                 $results[] = $builtAddress;
                 log_ai(">>>> ADDRESS BUILT: " . end($results));
            }

            // "Умный сброс" контекста для городов федерального значения.
            $newParts = [];
            $cutOffLevel = $currentLevel;

            if ($currentLevel == LEVEL_STREET
                && isset($currentAddressParts[LEVEL_REGION])
                && isset($currentAddressParts[LEVEL_CITY]))
            {
                $regionComponent = $currentAddressParts[LEVEL_REGION];
                // Проверяем, является ли регион городом федерального значения
                if (array_key_exists($regionComponent, $markerlessKeywords)) {
                    $cutOffLevel = LEVEL_DISTRICT; // ...то сбрасываем "под-город", оставляя только основной город-регион.
                    log_ai("New street under a federal city region. Resetting sub-city context.");
                }
            } else if ($currentLevel == LEVEL_HOUSE && $lastLevel == LEVEL_HOUSE) {
                 $cutOffLevel = LEVEL_HOUSE; // Для висячего дома сохраняем все, что < LEVEL_HOUSE
            }

            foreach ($currentAddressParts as $lvl => $part) {
                if ($lvl < $cutOffLevel) {
                    $newParts[$lvl] = $part;
                }
            }
            $currentAddressParts = $newParts;
        }

        // Добавляем текущий компонент в собираемый адрес.
        if ($newHousePartFromHanging !== null) {
            $currentAddressParts[LEVEL_HOUSE] = $newHousePartFromHanging;
            $lastHouseComponent = $newHousePartFromHanging;
        } else if ($currentLevel == LEVEL_HOUSE && isset($currentAddressParts[LEVEL_HOUSE]) && !$isNewAddress) {
            $currentAddressParts[LEVEL_HOUSE] .= ', ' . $cleanComponent;
            $lastHouseComponent = $currentAddressParts[LEVEL_HOUSE];
        } else {

            // Специфичная эвристика для вложенных населенных пунктов.
            // Если у нас уже есть город (например, Саяногорск), и приходит новый компонент
            // того же уровня (например, Черемушки рп), мы не заменяем, а добавляем его как дочерний.
            if ($currentLevel === LEVEL_CITY && isset($currentAddressParts[LEVEL_CITY]) && !$isNewAddress) {
                $currentAddressParts[LEVEL_CITY] .= ', ' . $cleanComponent;
                log_ai("Appending sub-city '{$cleanComponent}' to existing city '{$currentAddressParts[LEVEL_CITY]}'.");
                $lastLevel = $currentLevel; // Важно обновить состояние
                ksort($currentAddressParts);
                log_ai("Current address parts: " . implode(' | ', $currentAddressParts));
                continue; // Пропускаем остальную логику добавления/сброса для этого чанка
            }

            // Если компонент того же уровня уже существует (например, район -> новый район)
            if (isset($currentAddressParts[$currentLevel])) {
                $existingPart = $currentAddressParts[$currentLevel];
                // И если новый компонент начинается с текста старого, очищаем его
                if (mb_stripos($cleanComponent, $existingPart) === 0) {
                    $cleanComponent = trim(mb_substr($cleanComponent, mb_strlen($existingPart)));
                    log_ai("Self-cleanup: Component '{$currentAddressParts[$currentLevel]}' is a prefix of '{$chunk}'. Stripping it.");
                }
            }

            foreach($currentAddressParts as $lvl => $part) {
                if ($lvl >= $currentLevel) unset($currentAddressParts[$lvl]);
            }

            // Если после очистки что-то осталось, добавляем.
            if ($cleanComponent !== '') {
                $currentAddressParts[$currentLevel] = $cleanComponent;
                if ($currentLevel === LEVEL_HOUSE) {
                    $lastHouseComponent = $cleanComponent;
                }
            }
        }

        $lastLevel = $currentLevel;
        ksort($currentAddressParts);
        log_ai("Current address parts: " . implode(' | ', $currentAddressParts));

        // --- 6. Пост-обработка и управление состояниями ---
        // Если улица и дом "склеились" в одном чанке, разрезаем их.
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

            $streetComponent = $currentAddressParts[LEVEL_STREET];
            $lastSpacePos = mb_strrpos($streetComponent, ' ');

            if ($lastSpacePos !== false) {
                $potentialHousePart = trim(mb_substr($streetComponent, $lastSpacePos + 1));

                // Проверяем, что последняя часть начинается с цифры
                if (is_numeric(mb_substr($potentialHousePart, 0, 1))) {
                    $streetPart = trim(mb_substr($streetComponent, 0, $lastSpacePos));

                    // Список исключений: маркеры улиц, которые ИСПОЛЬЗУЮТ номер как часть названия.
                    // Для них отрывать номер нельзя.
                    $numericStreetMarkers = ['квл', 'мкр', 'мкрн', 'микрорайон', 'линия'];

                    // Отрываем номер дома, только если оставшаяся часть улицы не является исключением.
                    if (!empty($streetPart) && !in_array(mb_strtolower($streetPart), $numericStreetMarkers)) {
                        log_ai("Post-split heuristic: Found unmarked house component '{$potentialHousePart}' at the end of street '{$streetComponent}'. Splitting.");
                        $currentAddressParts[LEVEL_STREET] = $streetPart;
                        array_unshift($processingQueue, $potentialHousePart);
                    } else {
                        log_ai("Post-split heuristic blocked: Street part '{$streetPart}' is in the numeric markers exception list. Not splitting.");
                    }
                }
            }

        } else if ($currentLevel === LEVEL_HOUSE) {
            $streetMarkersForSplit = [' ул.', ' ул ', ' пр-т', ' пр ', ' б-р', ' пер '];
             foreach ($streetMarkersForSplit as $streetMarker) {
                $streetPos = mb_stripos($currentAddressParts[LEVEL_HOUSE], $streetMarker);
                if ($streetPos !== false) {
                    $housePart = trim(mb_substr($currentAddressParts[LEVEL_HOUSE], 0, $streetPos));
                    $streetPart = trim(mb_substr($currentAddressParts[LEVEL_HOUSE], $streetPos));
                    log_ai("Post-split: Found street marker '{$streetMarker}' in house component. Splitting.");
                    $currentAddressParts[LEVEL_HOUSE] = $housePart;
                    array_unshift($processingQueue, $streetPart);
                    log_ai("New house part: '{$housePart}'. Re-queuing street part: '{$streetPart}'.");
                    $lastLevel = LEVEL_HOUSE;
                    break;
                }
            }
        }

        // Входим в режим "внутри скобок".
        if (mb_strpos($cleanComponent, '(') !== false && mb_strpos($cleanComponent, ')') === false) {
            log_ai("Opening parenthesis found in '{$cleanComponent}'. Entering parentheses mode.");
            $inParenthesesMode = true;
        }
    }

    // --- 7. Финализация ---
    // Сохраняем последний адрес, оставшийся в сборке.
    if (!empty($currentAddressParts) && (isset($currentAddressParts[LEVEL_STREET]) || isset($currentAddressParts[LEVEL_HOUSE]))) {
        ksort($currentAddressParts);
        $results[] = implode(', ', $currentAddressParts);
        log_ai(">>>> FINAL ADDRESS BUILT: " . end($results));
    }

    // Отфильтровываем "мусорные" строки, которые не являются адресами.
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

    // Удаляем дубликаты, которые могли возникнуть из-за сложных пере-сборок.
    $finalResults = array_unique($finalResults);
    $finalResults = array_values($finalResults);

    // --- 8. Финальная проверка и возврат результата ---

    // Сначала проверяем на ошибку парсинга (бесконечный цикл)
    if ($infiniteLoopDetected) {
        $GLOBALS['parse_reason'] = "Обнаружен бесконечный цикл";
        log_ai("Infinite loop detected");
        return [$addressBlock];
    }

    // Затем проверяем, стоит ли вообще делить блок
    if (count($finalResults) <= 1 || !$foundAtLeastOneHouse) {
        if (!$foundAtLeastOneHouse) {
            $reason = "Не найдено ни одного полного адреса";
            log_ai("No full addresses found");
        } else {
            $reason = "Найден только один полный адрес";
            log_ai("Only one full address found");
        }
        $GLOBALS['parse_reason'] = $reason;
        return [$addressBlock];
    }

    return $finalResults;
}

// --- Основной цикл выполнения ---
$logAiHandle = fopen('test.ai.log', 'w');
$logManHandle = fopen('test.man.log', 'w');
if (!$logAiHandle || !$logManHandle) die("Could not open log files.");

mb_internal_encoding('UTF-8');

foreach ($testCases as $caseName => $addressBlock) {
    fwrite($logManHandle, "==================================================\n");
    fwrite($logManHandle, "CASE: {$caseName}\n");
    fwrite($logManHandle, "--------------------------------------------------\n");
    fwrite($logManHandle, "INPUT:\n{$addressBlock}\n");
    fwrite($logManHandle, "--------------------------------------------------\n");
    fwrite($logManHandle, "OUTPUT:\n");
    fwrite($logAiHandle, "==================================================\n");
    fwrite($logAiHandle, "CASE: {$caseName}\n");
    fwrite($logAiHandle, "==================================================\n");

    $GLOBALS['parse_reason'] = null; // Сбрасываем причину перед каждым запуском
    $result = splitAddresses($addressBlock);

    // Проверяем, вернула ли функция исходную строку
    if (count($result) === 1 && $result[0] === $addressBlock) {
        // Если да, то пишем причину, которую установила функция
        fwrite($logManHandle, $GLOBALS['parse_reason'] . "\n");
    } else {
        // Если все хорошо, выводим результат как обычно
        foreach ($result as $index => $address) {
            fwrite($logManHandle, "  " . ($index + 1) . ". {$address}\n");
        }
    }

    fwrite($logManHandle, "\n");
}

fclose($logAiHandle);
fclose($logManHandle);

echo "Processing complete. Check test.ai.log and test.man.log for results.\n";
