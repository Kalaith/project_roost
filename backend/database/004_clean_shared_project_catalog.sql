UPDATE `project_roost_profiles`
SET `display_name` = CASE `slug`
  WHEN 'adventcon' THEN 'Adventure Story Generator'
  WHEN 'anime_prompt_gen' THEN 'Anime Prompt Generator'
  WHEN 'isitdoneyet' THEN 'Is It Done Yet?'
  WHEN 'kemo_sim' THEN 'Kemo Simulator'
  WHEN 'name_generator' THEN 'Name Generator API'
  WHEN 'writers_studio' THEN 'Writers Studio'
  ELSE `display_name`
END,
`updated_at` = NOW()
WHERE `slug` IN (
  'adventcon',
  'anime_prompt_gen',
  'isitdoneyet',
  'kemo_sim',
  'name_generator',
  'writers_studio'
);

UPDATE `projects` stale
LEFT JOIN `project_roost_profiles` own_profile ON own_profile.`project_id` = stale.`id`
INNER JOIN `project_roost_profiles` profiled_project
  ON profiled_project.`project_id` <> stale.`id`
  AND (
    LOWER(TRIM(BOTH '/' FROM REPLACE(stale.`path`, CHAR(92), '/'))) = profiled_project.`slug`
    OR LOWER(TRIM(BOTH '/' FROM REPLACE(stale.`path`, CHAR(92), '/'))) LIKE CONCAT('%/', profiled_project.`slug`)
    OR LOWER(TRIM(BOTH '/' FROM REPLACE(stale.`path`, CHAR(92), '/'))) LIKE CONCAT('%/', profiled_project.`slug`, '/%')
  )
SET stale.`hidden` = 1,
    stale.`show_on_homepage` = 0,
    stale.`updated_at` = NOW()
WHERE own_profile.`project_id` IS NULL
  AND (stale.`hidden` <> 1 OR stale.`show_on_homepage` <> 0);

UPDATE `projects` stale
SET stale.`hidden` = 1,
    stale.`show_on_homepage` = 0,
    stale.`updated_at` = NOW()
WHERE (
    LOWER(REPLACE(REPLACE(stale.`title`, ' ', '_'), '-', '_')) = 'litrpg_studio'
    OR LOWER(TRIM(BOTH '/' FROM REPLACE(stale.`path`, CHAR(92), '/'))) LIKE '%/litrpg_studio'
    OR LOWER(TRIM(BOTH '/' FROM REPLACE(stale.`path`, CHAR(92), '/'))) LIKE '%/litrpg_studio/%'
  )
  AND EXISTS (
    SELECT 1
    FROM `project_roost_profiles` replacement
    WHERE replacement.`slug` = 'writers_studio'
  )
  AND (stale.`hidden` <> 1 OR stale.`show_on_homepage` <> 0);

UPDATE `projects` replacement
INNER JOIN `project_roost_profiles` replacement_profile ON replacement_profile.`project_id` = replacement.`id`
SET replacement.`hidden` = 0,
    replacement.`show_on_homepage` = 1,
    replacement.`updated_at` = NOW()
WHERE replacement_profile.`slug` = 'writers_studio'
  AND (replacement.`hidden` <> 0 OR replacement.`show_on_homepage` <> 1);
