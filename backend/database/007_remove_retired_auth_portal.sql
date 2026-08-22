-- Retire the legacy Auth Portal catalog entry.
-- Keep the shared projects row for referential compatibility, but remove its
-- Project Roost profile and hide it so reconciliation cannot resurrect it.

UPDATE `projects` p
INNER JOIN `project_roost_profiles` pr ON pr.`project_id` = p.`id`
SET p.`hidden` = 1,
    p.`show_on_homepage` = 0,
    p.`updated_at` = NOW()
WHERE pr.`slug` = 'auth_portal';

DELETE reviews
FROM `project_roost_review_snapshots` reviews
INNER JOIN `project_roost_profiles` pr ON pr.`project_id` = reviews.`project_id`
WHERE pr.`slug` = 'auth_portal';

DELETE risks
FROM `project_roost_risk_assessments` risks
INNER JOIN `project_roost_profiles` pr ON pr.`project_id` = risks.`project_id`
WHERE pr.`slug` = 'auth_portal';

DELETE tasks
FROM `project_roost_tasks` tasks
INNER JOIN `project_roost_profiles` pr ON pr.`project_id` = tasks.`project_id`
WHERE pr.`slug` = 'auth_portal';

DELETE FROM `project_roost_profiles`
WHERE `slug` = 'auth_portal';
