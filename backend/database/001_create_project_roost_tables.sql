CREATE TABLE IF NOT EXISTS `project_roost_profiles` (
  `project_id` bigint unsigned NOT NULL,
  `slug` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shape` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci,
  `preview_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `production_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`project_id`),
  UNIQUE KEY `project_roost_profiles_slug_unique` (`slug`),
  KEY `project_roost_profiles_category_index` (`category`),
  KEY `project_roost_profiles_shape_index` (`shape`),
  CONSTRAINT `project_roost_profiles_project_id_fk`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_roost_review_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `reviewed_at` datetime NOT NULL,
  `source` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `frontend_score` decimal(4,1) DEFAULT NULL,
  `backend_score` decimal(4,1) DEFAULT NULL,
  `security_score` decimal(4,1) DEFAULT NULL,
  `overall_score` decimal(4,1) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `priority_fix` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_roost_reviews_source_unique` (`project_id`, `source`, `source_hash`),
  KEY `project_roost_reviews_reviewed_at_index` (`reviewed_at`),
  KEY `project_roost_reviews_overall_index` (`overall_score`),
  CONSTRAINT `project_roost_reviews_project_id_fk`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_roost_risk_assessments` (
  `project_id` bigint unsigned NOT NULL,
  `severity` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `auth_risk` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_risk` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `env_risk` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ownership_risk` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`project_id`),
  KEY `project_roost_risks_severity_index` (`severity`),
  CONSTRAINT `project_roost_risks_project_id_fk`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_roost_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `effort` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `impact` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_roost_tasks_import_unique` (`project_id`, `source`, `source_hash`),
  KEY `project_roost_tasks_status_index` (`status`),
  KEY `project_roost_tasks_priority_index` (`priority`),
  CONSTRAINT `project_roost_tasks_project_id_fk`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_roost_imports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `imported_at` datetime NOT NULL,
  `project_count` int unsigned NOT NULL,
  `review_count` int unsigned NOT NULL,
  `task_count` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_roost_imports_source_hash_unique` (`source`, `source_hash`),
  KEY `project_roost_imports_imported_at_index` (`imported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
