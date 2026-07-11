-- Player-submitted bug reports for Rust games.
-- These arrive from public, unauthenticated game pages, so rows here are UNTRUSTED
-- input held in a moderation queue. An admin reviews each report and, on approval,
-- a real task is created in `project_roost_tasks` (the Fix Queue). Nothing here is
-- ever rendered as trusted HTML.
CREATE TABLE IF NOT EXISTS `project_roost_bug_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned DEFAULT NULL,
  `project_slug` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `game_version` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(400) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `promoted_task_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_roost_bug_reports_status_index` (`status`),
  KEY `project_roost_bug_reports_project_index` (`project_id`),
  KEY `project_roost_bug_reports_ip_created_index` (`ip_hash`, `created_at`),
  CONSTRAINT `project_roost_bug_reports_project_id_fk`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
