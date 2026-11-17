-- Run as the PBX database owner with psql -v writer_role=YOUR_ROLE.
-- No schema or data changes. The login role must already exist.
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'writer_role') \gexec
GRANT USAGE ON SCHEMA public TO :"writer_role";
GRANT SELECT, DELETE ON ps_endpoints, ps_auths, ps_aors TO :"writer_role";
GRANT INSERT (id, context, auth, aors, transport, "allow", disallow, callerid, direct_media, rtp_symmetric, force_rport, rewrite_contact) ON ps_endpoints TO :"writer_role";
GRANT UPDATE (auth, outbound_auth, aors, transport, "allow", disallow, callerid, direct_media, rtp_symmetric, force_rport, rewrite_contact) ON ps_endpoints TO :"writer_role";
GRANT INSERT (id, auth_type, username, password, realm) ON ps_auths TO :"writer_role";
GRANT UPDATE (username, realm, password, md5_cred, password_digest) ON ps_auths TO :"writer_role";
GRANT INSERT (id, max_contacts, default_expiration, minimum_expiration, maximum_expiration, qualify_frequency, qualify_timeout, remove_existing) ON ps_aors TO :"writer_role";
GRANT UPDATE (max_contacts, default_expiration, minimum_expiration, maximum_expiration, qualify_frequency, qualify_timeout, remove_existing) ON ps_aors TO :"writer_role";
GRANT SELECT (id, endpoint, expiration_time) ON ps_contacts TO :"writer_role";
GRANT SELECT (id, endpoint) ON ps_endpoint_id_ips TO :"writer_role";
GRANT SELECT (id, endpoint, outbound_auth) ON ps_registrations TO :"writer_role";
GRANT SELECT (id, context, appdata) ON extensions TO :"writer_role";
