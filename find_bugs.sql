
SELECT *, LENGTH(`address`) AS `ln` FROM `procurement_locations` ORDER BY `ln` DESC LIMIT 0, 50

SELECT *, LENGTH(`address`) AS `ln` FROM `procurement_locations` ORDER BY `ln` LIMIT 0, 50



SELECT COUNT(*) FROM `procurement_locations` WHERE LENGTH(`address`) < 15 OR LENGTH(`address`) > 200;

SELECT COUNT(*) FROM `procurement_locations` WHERE LENGTH(`address`) < 15;
SELECT COUNT(*) FROM `procurement_locations` WHERE LENGTH(`address`) > 200;


SELECT
    COUNT(CASE WHEN LENGTH(address) < 15 THEN 1 END) AS short_address_count,
    COUNT(CASE WHEN LENGTH(address) > 200 THEN 1 END) AS long_address_count,
    COUNT(CASE WHEN LENGTH(address) < 15 OR LENGTH(address) > 200 THEN 1 END) AS total_sum
FROM
    procurement_locations;

