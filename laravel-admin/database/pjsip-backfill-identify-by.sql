-- Run once as the PBX database owner.
UPDATE public.ps_endpoints
SET identify_by = 'auth_username'
WHERE identify_by IS DISTINCT FROM 'auth_username';
