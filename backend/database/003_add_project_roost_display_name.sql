SET @project_roost_display_name_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_roost_profiles'
    AND COLUMN_NAME = 'display_name'
);

SET @project_roost_display_name_sql := IF(
  @project_roost_display_name_exists = 0,
  'ALTER TABLE `project_roost_profiles` ADD COLUMN `display_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `slug`',
  'SELECT 1'
);

PREPARE project_roost_display_name_stmt FROM @project_roost_display_name_sql;
EXECUTE project_roost_display_name_stmt;
DEALLOCATE PREPARE project_roost_display_name_stmt;

UPDATE `project_roost_profiles` pr
INNER JOIN `projects` p ON p.id = pr.project_id
SET pr.display_name = p.title
WHERE pr.display_name IS NULL OR pr.display_name = '';
