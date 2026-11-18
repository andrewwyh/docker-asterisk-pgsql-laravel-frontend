CREATE ROLE fixture_dialplan_writer LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
\set reader_role fixture_reader
\set dialplan_role fixture_dialplan_writer
\i /opt/dialplan-grants.sql
