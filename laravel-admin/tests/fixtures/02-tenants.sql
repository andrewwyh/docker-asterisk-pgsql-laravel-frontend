-- Synthetic data for tenant isolation tests only.
INSERT INTO ps_auths (id, auth_type, username, password) VALUES
('auth-abc', 'userpass', '100_abc', 'fixture-private-auth-password'),
('auth-xyz', 'userpass', '100_xyz', 'fixture-other-auth-password'),
('auth-shared', 'userpass', 'shared-identity', 'fixture-shared-password'),
('auth-outbound', 'userpass', 'outbound-abc', 'fixture-outbound-password'),
('auth-orphan', 'userpass', 'unassigned-identity', 'fixture-orphan-password');
INSERT INTO ps_aors (id, max_contacts) VALUES
('aor-abc', 1), ('aor-xyz', 1), ('aor-shared', 1), ('aor-extra', 2), ('aor-orphan', 1);
INSERT INTO ps_endpoints (id, context, auth, outbound_auth, aors) VALUES
('100_abc', 'abc', ' auth-abc , auth-shared ', 'auth-outbound', ' aor-abc , aor-shared, aor-extra '),
('100_xyz', 'xyz', 'auth-xyz,auth-shared', NULL, 'aor-xyz,aor-shared'),
('legacy-account', 'default', NULL, NULL, NULL);

CREATE ROLE fixture_reader LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
GRANT CONNECT ON DATABASE realtime_fixture TO fixture_reader;
GRANT USAGE ON SCHEMA public TO fixture_reader;
GRANT SELECT (id, context, transport, auth, outbound_auth, aors, "allow", callerid) ON ps_endpoints TO fixture_reader;
GRANT SELECT (id, auth_type, username, realm) ON ps_auths TO fixture_reader;
GRANT SELECT (id, max_contacts, default_expiration, maximum_expiration, qualify_frequency) ON ps_aors TO fixture_reader;
ALTER ROLE fixture_reader SET default_transaction_read_only = on;
