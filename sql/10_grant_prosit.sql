-- ============================================================================
-- RUOLI DEDICATI ALL'API PROSIT
-- ============================================================================
-- prosit_ro  sola lettura, SOLO sugli oggetti esposti (al posto di LizmapSQL)
-- prosit_rw  lettura + scrittura SOLO dove l'API scrive
--
-- Esecuzione (da superuser, sulla nuova VM), con password generate da install.sh:
--   psql -h localhost -U postgres -d postgres \
--        -v ro_pass='...' -v rw_pass='...' -f sql/10_grant_prosit.sql
-- Si puo' rilanciare: crea cio' che manca, riallinea password e permessi.
-- Un oggetto che non esiste viene segnalato (NOTICE) e saltato, senza errore.
--
-- Dopo un cambio di struttura (nuova vista, nuova risorsa in config/resources.php)
-- aggiungere qui la riga corrispondente e rilanciare.
-- ============================================================================
\set ON_ERROR_STOP on
\pset tuples_only on

SELECT format('CREATE ROLE prosit_ro LOGIN PASSWORD %L', :'ro_pass')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'prosit_ro') \gexec
SELECT format('CREATE ROLE prosit_rw LOGIN PASSWORD %L', :'rw_pass')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'prosit_rw') \gexec
SELECT format('ALTER ROLE prosit_ro WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD %L CONNECTION LIMIT 30', :'ro_pass') \gexec
SELECT format('ALTER ROLE prosit_rw WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD %L CONNECTION LIMIT 30', :'rw_pass') \gexec
ALTER ROLE prosit_ro SET default_transaction_read_only = on;
ALTER ROLE prosit_ro SET statement_timeout = '30s';
ALTER ROLE prosit_rw SET statement_timeout = '30s';


-- ============================================================================
\connect segnalazioni_23
\ir _grant_helper.sql
GRANT CONNECT ON DATABASE segnalazioni_23 TO prosit_ro, prosit_rw;
-- lettura (schede, registro TVCC, report, mail)
SELECT pg_temp.g(r, 'SELECT', t) FROM unnest(ARRAY['prosit_ro','prosit_rw']) r, unnest(ARRAY[
  'segn.segn_id', 'segn.reporting_ticket_deleted', 'audit.frontend_communication', 'segn.ident_coord',
  'segn.rekla_doc', 'segn.issue_docs_attachment', 'administration.assignee_list',
  'buildings.list_tickets_full_all_v', 'buildings.reporting_ticket', 'buildings.attached_docs_ticket',
  'buildings.tickets', 'buildings.list_tickets_v', 'buildings.list_tickets_status_v',
  'audit.list_tvcc_reported', 'audit.daily_log_tvcc', 'audit.errors_rekla',
  'administration.login_rekla_prosit', 'audit.todo_queue'
]) t;
-- scrittura
SELECT pg_temp.g('prosit_rw', 'SELECT, INSERT', 'segn.insert_ticket_tvcc_rekla_v');  -- SELECT serve per INSERT ... RETURNING
SELECT pg_temp.g('prosit_rw', 'INSERT', 'segn.segn_id');          -- usata dal trigger della vista TVCC
SELECT pg_temp.g('prosit_rw', 'INSERT', 'audit.daily_log_tvcc');
SELECT pg_temp.g('prosit_rw', 'SELECT, INSERT', 'audit.mail_queue');

-- ============================================================================
\connect llpp
\ir _grant_helper.sql
GRANT CONNECT ON DATABASE llpp TO prosit_ro, prosit_rw;
SELECT pg_temp.g(r, 'SELECT', t) FROM unnest(ARRAY['prosit_ro','prosit_rw']) r, unnest(ARRAY[
  'prog.manomissioni_sheet_v', 'prog.manomissioni_attachs'
]) t;

-- ============================================================================
\connect vp
\ir _grant_helper.sql
GRANT CONNECT ON DATABASE vp TO prosit_ro, prosit_rw;
SELECT pg_temp.g(r, 'SELECT', t) FROM unnest(ARRAY['prosit_ro','prosit_rw']) r, unnest(ARRAY[
  'interventi.join_int_user', 'interventi.obj_mantainer_int', 'gestione.pian_int', 'gestione.pian_int_street'
]) t;
SELECT pg_temp.g('prosit_rw', 'INSERT, UPDATE, DELETE', 'gestione.pian_int');
SELECT pg_temp.g('prosit_rw', 'INSERT, UPDATE, DELETE', 'gestione.pian_int_street');

-- ============================================================================
\connect ip_2020_2022
\ir _grant_helper.sql
GRANT CONNECT ON DATABASE ip_2020_2022 TO prosit_ro, prosit_rw;
SELECT pg_temp.g(r, 'SELECT', t) FROM unnest(ARRAY['prosit_ro','prosit_rw']) r, unnest(ARRAY[
  'punti_luce.sheet_supporti_v', 'manutenzioni.timeline_updates_v'
]) t;

\echo 'Permessi Prosit applicati.'
