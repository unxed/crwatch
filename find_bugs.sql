

SELECT *, LENGTH(`address`) AS `ln` FROM `procurement_locations` ORDER BY `ln` LIMIT 0, 50

SELECT *, LENGTH(`address`) AS `ln` FROM `procurement_locations` ORDER BY `ln` DESC LIMIT 0, 50


SELECT
    COUNT(CASE WHEN LENGTH(address) < 32 THEN 1 END) AS short_address_count,
    COUNT(CASE WHEN LENGTH(address) > 200 THEN 1 END) AS long_address_count,
    COUNT(CASE WHEN LENGTH(address) < 32 OR LENGTH(address) > 200 THEN 1 END) AS total_sum
FROM
    procurement_locations;


-- 01418b1667ea90f1a2694502bb1b2fdd7cab3ee5 - 295, 2024, 2319 (15, 200)
-- 42f23fb80148669a89c2bb6db6bdb2a9df390b51 - 14, 3721, 3735 (15, 200)
-- e534a348bf174803ec4c0f3a997ca16019d327cc - 283, 278, 561 (37, 200)
-- ffdef0f089989cbd355e7f6b77febf0b57cdce0d - 66, 406, 472 (37, 200)
-- bee448d2ed5f24efdc4352b7a8f07b831121ee66 - 51, 576, 627 (37, 200)
-- bee448d2ed5f24efdc4352b7a8f07b831121ee66 - 51, 1341, 1392 (37, 170)
-- 6c60a789dce85b58d98322f7733f4b2f6281e9e7 - 55, 1110, 1165 (37, 170)
-- cbc4cb712d92a49fc029f205904dd1d0f79d6ee3 - 43, 1094, 1137 (37, 170)
-- cbc4cb712d92a49fc029f205904dd1d0f79d6ee3 - 0, 255, 255 (32, 200)
