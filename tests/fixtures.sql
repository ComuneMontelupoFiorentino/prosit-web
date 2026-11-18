-- ============================================================================
-- DATI DI PROVA per i test automatici (tests/run.sh). Usa SOLO database prosit_test_*.
-- Riproduce i nomi di database/schemi/tabelle usati dai servizi, con colonne
-- ridotte, per verificare API, permessi e pagine su un PostgreSQL di prova.
-- ============================================================================
\set ON_ERROR_STOP on

DROP DATABASE IF EXISTS prosit_test_segnalazioni_23; CREATE DATABASE prosit_test_segnalazioni_23;
DROP DATABASE IF EXISTS prosit_test_llpp; CREATE DATABASE prosit_test_llpp;
DROP DATABASE IF EXISTS prosit_test_vp; CREATE DATABASE prosit_test_vp;
DROP DATABASE IF EXISTS prosit_test_ip_2020_2022; CREATE DATABASE prosit_test_ip_2020_2022;

\connect prosit_test_segnalazioni_23
CREATE EXTENSION postgis;
CREATE SCHEMA segn; CREATE SCHEMA audit; CREATE SCHEMA administration; CREATE SCHEMA buildings;

CREATE TABLE segn.segn_id (
  seg_id serial PRIMARY KEY, seg_i_rek_alias text, seg_i_rek_n text, isfrom text, stato_segn text,
  seg_t_mat text, seg_o_ogg text, seg_o_desc text, seg_s_ac_d date DEFAULT current_date, seg_i_nc text, seg_i_cog text,
  seg_o_n text, man_es text, office_manager text, seg_l_ind text, seg_l_civ text, ident text, segn_ogg_gen text,
  note_maintainer text, seg_o_img text, seg_s_ac_op text, high_complexity boolean DEFAULT false,
  update_date timestamptz DEFAULT now(), insert_timestamp timestamptz DEFAULT now(),
  latitude numeric, longitude numeric, geom geometry(Point, 3003)
);
INSERT INTO segn.segn_id (seg_i_rek_alias, seg_i_rek_n, isfrom, stato_segn, seg_t_mat, seg_o_ogg, seg_o_desc, seg_i_nc, seg_i_cog,
  man_es, office_manager, seg_l_ind, seg_l_civ, ident, segn_ogg_gen, latitude, longitude, geom) VALUES
 ('RK-001','501','rekla','Assegnata','Verde','Ramo caduto','Ramo <b>pericolante</b>'||chr(10)||'in via Roma','Mario','Rossi',
  'man_verde_es','Ufficio Verde','Via Roma','12','ALB-1','TLC-01',43.73,10.98, ST_SetSRID(ST_MakePoint(1650000,4840000),3003)),
 ('RK-002','502','twproject','Verificata e chiusa','VIDEOSORVEGLIANZA','Camera spenta','Non trasmette',null,null,
  'man_es_tlc','Ufficio supporto informatico','Piazza Centrale',null,null,'TLC-01',null,null,null),
 ('RK-003',null,'prosit','Sospesa','Attività preventive','Controllo','<img src=x onerror=alert(1)>',null,null,
  'man_ip_es','Ufficio Verde',null,null,null,'TLC-02',null,null,null);

CREATE TABLE segn.reporting_ticket_deleted (id serial, id_prosit int, status text, action_date timestamptz, action_description text, action_user text);
INSERT INTO segn.reporting_ticket_deleted (id_prosit,status,action_date,action_description,action_user) VALUES
 (1,'Accettata','2026-10-01','Ricevuta','urp'),(1,'Assegnata','2026-10-02','Assegnata al manutentore','ufficio');
CREATE TABLE audit.frontend_communication (id serial, id_ticket int, type text, status_desc text, subject text, link_twprj text, timing text);
INSERT INTO audit.frontend_communication (id_ticket,type,status_desc,subject,link_twprj,timing) VALUES (1,'TODO','Inviata','Intervento','https://twprj.example/1','2026-10-02 10:00');
CREATE TABLE audit.todo_queue (id serial PRIMARY KEY, id_segn int, insert_by_app varchar DEFAULT 'rekla', subject varchar, body text,
  status varchar, created_at timestamp DEFAULT now(), sent_at timestamp, id_todo_twprj int, todo_status_twprj int, sinc_status timestamp, assignee_id int);
INSERT INTO audit.todo_queue (id_segn, insert_by_app, subject, body, status, sent_at, id_todo_twprj, todo_status_twprj) VALUES
  (1, 'rekla', 'Intervento RK-001', 'testo interno', 'sended', now(), 777, 1),
  (10, 'rekla-bit', 'Perdita - Scuola Media - #ReklaBIT-0010', 'testo interno', 'sended', now(), 888, 1),
  (1, 'rekla-bit', 'Ticket BIT numero 1', 'testo interno', 'pending', null, null, 1);
CREATE TABLE segn.ident_coord (ident text, tema text, oggetto_spec text);
INSERT INTO segn.ident_coord VALUES ('ALB-1','Verde','Albero');
CREATE TABLE segn.rekla_doc (seg_i_rek_n text, path_doc text);
INSERT INTO segn.rekla_doc VALUES ('501','http://10.0.35.1/?post_id=77&dciaction=downloadfile&file=abc123');
CREATE TABLE segn.issue_docs_attachment (id_ticket int, path_doc text);
CREATE TABLE administration.assignee_list (man_es text, alias_name text);
INSERT INTO administration.assignee_list VALUES ('man_verde_es','Cooperativa Orizzonti');
CREATE TABLE administration.login_rekla_prosit (id_aclgrp text, usr_email text);
INSERT INTO administration.login_rekla_prosit VALUES ('man_verde_es','verde@example.it'),('man_verde_es','capo@example.it');
CREATE TABLE audit.mail_queue (id serial PRIMARY KEY, recipient jsonb, carbon_copy jsonb, subject text, body jsonb, status text, application text, id_prosit int, created timestamptz DEFAULT now());
CREATE TABLE audit.errors_rekla (id serial, seg_s_ac_d date, office_manager text, man_es text);

-- vista con trigger INSTEAD OF, come segn.insert_ticket_tvcc_rekla_v
CREATE VIEW segn.insert_ticket_tvcc_rekla_v AS
  SELECT seg_id, seg_s_ac_op, seg_o_ogg, seg_o_desc, segn_ogg_gen FROM segn.segn_id;
CREATE FUNCTION segn.tvcc_ins() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.seg_o_desc IS NULL OR length(trim(NEW.seg_o_desc)) < 3 THEN
    RAISE EXCEPTION 'Descrizione troppo corta';
  END IF;
  INSERT INTO segn.segn_id (seg_s_ac_op, seg_o_ogg, seg_o_desc, segn_ogg_gen, seg_t_mat, office_manager, stato_segn, seg_i_rek_alias)
  VALUES (NEW.seg_s_ac_op, NEW.seg_o_ogg, NEW.seg_o_desc, NEW.segn_ogg_gen, 'VIDEOSORVEGLIANZA', 'Ufficio supporto informatico', 'Accettata',
          'TV-' || current_setting('prosit.user', true))
  RETURNING seg_id INTO NEW.seg_id;
  RETURN NEW;
END $$;
CREATE TRIGGER tvcc_ins INSTEAD OF INSERT ON segn.insert_ticket_tvcc_rekla_v FOR EACH ROW EXECUTE FUNCTION segn.tvcc_ins();

CREATE TABLE audit.daily_log_tvcc (id serial PRIMARY KEY, note text NOT NULL, insert_by text, insert_date timestamptz DEFAULT now());
CREATE VIEW audit.list_tvcc_reported AS
  SELECT a.id_app, a.lett_targh,
         count(s.seg_id) > 0 AS reported, count(s.seg_id) AS num_reported,
         array_remove(array_agg(DISTINCT s.stato_segn), NULL) AS stati,
         COALESCE(json_agg(json_build_object('seg_id', s.seg_id)) FILTER (WHERE s.seg_id IS NOT NULL), '[]') AS issues
    FROM (VALUES ('TLC-01','no'),('TLC-02','si'),('TLC-03','no')) a(id_app, lett_targh)
    LEFT JOIN segn.segn_id s ON s.segn_ogg_gen = a.id_app AND s.stato_segn <> 'Verificata e chiusa'
   GROUP BY a.id_app, a.lett_targh;

CREATE TABLE buildings.tickets (id serial, detail_status int, entry_date date);
INSERT INTO buildings.tickets (detail_status, entry_date) VALUES (1, current_date), (3, current_date), (3, current_date);
CREATE TABLE buildings.list_tickets_full_all_v (id int PRIMARY KEY, id_alias text, detailed_name text, organization_name text,
  aborted text, issue_subject text, issue_description text, entry_date date, full_name text);
INSERT INTO buildings.list_tickets_full_all_v VALUES (10,'BIT-10','Presa in carico','Scuola Media',null,'Perdita','Acqua dal soffitto',current_date,'Anna Bianchi');
CREATE TABLE buildings.reporting_ticket (id serial, id_ticket int, action_description text, action_user text, action_date timestamptz, action_note text, status text);
INSERT INTO buildings.reporting_ticket (id_ticket,action_description,action_user,action_date,action_note) VALUES (10,'Presa in carico','tecnico','2026-10-05','Sopralluogo fissato');
CREATE TABLE buildings.attached_docs_ticket (id_ticket int, doc_name text, doc_url text);
INSERT INTO buildings.attached_docs_ticket VALUES (10,'foto.jpg','https://example.it/foto.jpg');
CREATE VIEW buildings.list_tickets_v AS SELECT * FROM buildings.list_tickets_full_all_v;
CREATE VIEW buildings.list_tickets_status_v AS SELECT id, detailed_name FROM buildings.list_tickets_full_all_v;

\connect prosit_test_llpp
CREATE SCHEMA prog;
CREATE TABLE prog.manomissioni_sheet_v (id int PRIMARY KEY, uuid_request text, stato text, descr_int text, ubi text, last_update timestamptz,
  insert_canal text, data_insert date, external_contacts_name text, external_contacts_surname text, ente text,
  is_public_utility boolean, data_start date, data_finish date, execute_work_by text, external_executer_work jsonb,
  figure_responsabili jsonb, checked boolean, check_result jsonb, id_issue_twprj int, garanzia_tipo text);
INSERT INTO prog.manomissioni_sheet_v VALUES (19,'MAN-19','Autorizzata','Scavo fibra','Via Verdi',now(),'PEC',current_date,'Luca','Neri','Open Fiber',
  true, current_date - 2, current_date + 5, 'Impresa', '{"ragione":"Scavi Srl","pec1":"scavi@pec.it"}', '{"direttore_lavori":"Ing. Blu"}',
  true, '[{"date":"2026-10-10","author":"vigile","comment":"Ripristino ok"}]', 456, 'Fideiussione');
CREATE TABLE prog.manomissioni_attachs (id serial, id_man int, url_path text, title_attach text);
INSERT INTO prog.manomissioni_attachs (id_man,url_path,title_attach) VALUES (19,'../media/img/14-attivita-su-territorio/01-manomissioni/progetto 1.pdf','Progetto');

\connect prosit_test_vp
CREATE SCHEMA gestione; CREATE SCHEMA interventi;
CREATE TABLE gestione.pian_int (id serial PRIMARY KEY, id_zona int, ty_int text, date_int date, execution boolean, date_int_ex date, planned_int text, manteiner text, data_ins_plan timestamptz DEFAULT now());
CREATE TABLE gestione.pian_int_street (id serial PRIMARY KEY, strada text, ty_int text, date_int date, execution boolean, date_int_ex date, planned_int text, manteiner text, data_ins_plan timestamptz DEFAULT now());
CREATE TABLE interventi.join_int_user (int_name text, usergroup text);
INSERT INTO interventi.join_int_user VALUES ('potature siepi','man_verde_es'),('falciatura tappeti erbosi','man_verde_es');
CREATE TABLE interventi.obj_mantainer_int (id int, name_obj text, maintainer text, int_ty text);
INSERT INTO interventi.obj_mantainer_int VALUES (12,'PARCO FLUVIALE','man_verde_es','potature siepi');

\connect prosit_test_ip_2020_2022
CREATE SCHEMA punti_luce; CREATE SCHEMA manutenzioni;
CREATE TABLE punti_luce.sheet_supporti_v (pl_id int PRIMARY KEY, num_punti_luce int, pl_is_arm text, pl_is_alt numeric, pl_is_mat text, pl_is_sts text);
INSERT INTO punti_luce.sheet_supporti_v VALUES (1001, 2, 'LED', 8.5, 'Acciaio', 'Buono');
CREATE TABLE manutenzioni.timeline_updates_v (pl_id int, data_int date, ty_int text);
INSERT INTO manutenzioni.timeline_updates_v VALUES (1001,'2026-03-01','Ispezione'),(1001,'2026-06-01','Sostituzione lampada');
