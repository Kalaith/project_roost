-- Retire the old WH Tracker catalog row now that Project Roost is the
-- authoritative project registry and deployment dashboard.
UPDATE `projects`
SET `hidden` = 1,
    `show_on_homepage` = 0,
    `updated_at` = NOW()
WHERE LOWER(REPLACE(REPLACE(TRIM(`title`), ' ', '_'), '-', '_')) = 'wh_tracker'
   OR LOWER(TRIM(BOTH '/' FROM REPLACE(`path`, CHAR(92), '/'))) LIKE '%/wh_tracker'
   OR LOWER(TRIM(BOTH '/' FROM REPLACE(`path`, CHAR(92), '/'))) LIKE '%/wh_tracker/%';
