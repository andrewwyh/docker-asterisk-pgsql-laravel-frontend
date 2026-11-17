CREATE ROLE fixture_writer LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
\set writer_role fixture_writer
\i /opt/pjsip-writer-grants.sql
