-- Run as the PBX database owner, with existing login roles:
-- psql -v reader_role=YOUR_READER -v dialplan_role=YOUR_DIALPLAN_WRITER
-- No schema or data changes; PJSIP writer permissions remain unchanged.
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'dialplan_role') \gexec
GRANT USAGE ON SCHEMA public TO :"dialplan_role";
GRANT SELECT, DELETE ON extensions TO :"dialplan_role";
GRANT INSERT (context, exten, priority, app, appdata) ON extensions TO :"dialplan_role";
GRANT USAGE ON SEQUENCE extensions_id_seq TO :"dialplan_role";
GRANT SELECT (id, context) ON ps_endpoints TO :"dialplan_role";
GRANT SELECT (id, context, exten, priority, app) ON extensions TO :"reader_role";
