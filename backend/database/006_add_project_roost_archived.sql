SET @project_roost_archived_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_roost_profiles'
    AND COLUMN_NAME = 'archived'
);

SET @project_roost_archived_sql := IF(
  @project_roost_archived_exists = 0,
  'ALTER TABLE `project_roost_profiles` ADD COLUMN `archived` tinyint(1) NOT NULL DEFAULT 0 AFTER `production_url`',
  'SELECT 1'
);

PREPARE project_roost_archived_stmt FROM @project_roost_archived_sql;
EXECUTE project_roost_archived_stmt;
DEALLOCATE PREPARE project_roost_archived_stmt;
