-- Keep the ComfyUI catalog entry aligned with its move out of the apps workspace.
-- REPLACE preserves the drive and parent workspace used by each environment.

UPDATE `projects`
SET `path` = REPLACE(`path`, '\\apps\\comfyui', '\\local\\comfyui'),
    `updated_at` = NOW()
WHERE `title` = 'comfyui'
  AND `path` LIKE '%\\apps\\comfyui';
