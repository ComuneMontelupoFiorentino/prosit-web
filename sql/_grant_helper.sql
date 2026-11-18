-- Funzione temporanea usata da 10_grant_prosit.sql (inclusa dopo ogni \connect).
-- g(ruolo, privilegi, 'schema.oggetto'): concede USAGE sullo schema, i privilegi
-- sull'oggetto e, per INSERT, l'uso delle sequenze delle sue colonne serial/identity.
-- Se l'oggetto non esiste lo segnala e prosegue.
CREATE OR REPLACE FUNCTION pg_temp.g(_role text, _privs text, _rel text)
RETURNS void LANGUAGE plpgsql AS $f$
DECLARE
  r regclass := to_regclass(_rel);
  seq record;
BEGIN
  IF r IS NULL THEN
    RAISE NOTICE 'MANCA %: permesso % a % non dato', _rel, _privs, _role;
    RETURN;
  END IF;
  EXECUTE format('GRANT USAGE ON SCHEMA %I TO %I', split_part(_rel, '.', 1), _role);
  EXECUTE format('GRANT %s ON %s TO %I', _privs, r, _role);
  IF _privs ILIKE '%INSERT%' THEN
    FOR seq IN
      SELECT DISTINCT d.objid::regclass AS s
        FROM pg_depend d JOIN pg_class c ON c.oid = d.objid AND c.relkind = 'S'
       WHERE d.refobjid = r AND d.deptype IN ('a', 'i')
    LOOP
      EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE %s TO %I', seq.s, _role);
    END LOOP;
  END IF;
END $f$;
