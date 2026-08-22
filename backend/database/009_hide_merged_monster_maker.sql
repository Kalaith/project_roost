-- Monster Maker was merged into dnd_sheet. Keep the historical row for audit
-- purposes, but remove it from the public project catalog.
UPDATE `projects`
SET `hidden` = 1,
    `show_on_homepage` = 0,
    `updated_at` = NOW()
WHERE LOWER(REPLACE(REPLACE(TRIM(`title`), ' ', '_'), '-', '_')) = 'monster_maker'
   OR LOWER(TRIM(BOTH '/' FROM REPLACE(`path`, CHAR(92), '/'))) LIKE '%/monster_maker'
   OR LOWER(TRIM(BOTH '/' FROM REPLACE(`path`, CHAR(92), '/'))) LIKE '%/monster_maker/%';
