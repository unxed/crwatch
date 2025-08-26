

SELECT *, LENGTH(`address`) AS `ln` FROM `procurement_locations` ORDER BY `ln` LIMIT 0, 50

SELECT *, LENGTH(`address`) AS `ln` FROM `procurement_locations` ORDER BY `ln` DESC LIMIT 0, 50


SELECT COUNT(*) FROM `procurement_locations` WHERE LENGTH(`address`) < 37 OR LENGTH(`address`) > 200;

SELECT COUNT(*) FROM `procurement_locations` WHERE LENGTH(`address`) < 37;
SELECT COUNT(*) FROM `procurement_locations` WHERE LENGTH(`address`) > 200;


SELECT
    COUNT(CASE WHEN LENGTH(address) < 37 THEN 1 END) AS short_address_count,
    COUNT(CASE WHEN LENGTH(address) > 200 THEN 1 END) AS long_address_count,
    COUNT(CASE WHEN LENGTH(address) < 37 OR LENGTH(address) > 200 THEN 1 END) AS total_sum
FROM
    procurement_locations;


-- 01418b1667ea90f1a2694502bb1b2fdd7cab3ee5 - 295, 2024, 2319
-- 42f23fb80148669a89c2bb6db6bdb2a9df390b51 - 14, 3721, 3735
