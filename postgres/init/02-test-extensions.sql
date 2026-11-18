-- Test routes for the currently configured endpoint contexts.
-- Also safe to apply manually to an existing PBX database: existing extension
-- numbers are preserved in their entirety, rather than partially overwritten.
BEGIN;

LOCK TABLE extensions IN SHARE ROW EXCLUSIVE MODE;

WITH contexts(context) AS (
    VALUES ('default'), ('abc')
), steps(exten, priority, app, appdata) AS (
    VALUES
        ('200', 1, 'Answer', ''),
        ('200', 2, 'Echo', ''),
        ('200', 3, 'Hangup', ''),
        ('201', 1, 'Answer', ''),
        ('201', 2, 'Wait', '1'),
        ('201', 3, 'Playback', 'hello-world'),
        ('201', 4, 'Hangup', '')
)
INSERT INTO extensions (context, exten, priority, app, appdata)
SELECT contexts.context, steps.exten, steps.priority, steps.app, steps.appdata
FROM contexts CROSS JOIN steps
WHERE NOT EXISTS (
    SELECT 1 FROM extensions existing
    WHERE existing.context = contexts.context AND existing.exten = steps.exten
)
ON CONFLICT (context, exten, priority) DO NOTHING;

COMMIT;
