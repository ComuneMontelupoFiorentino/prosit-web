#!/usr/bin/env bash
# =============================================================================
# Test automatici di Prosit (servizi, autenticazione tramite Lizmap, pagine) su database DI PROVA.
#
#   PGHOST=127.0.0.1 PGPORT=5432 PGUSER=postgres PGPASSWORD=... LIZMAP_URL=http://127.0.0.1/lizmap \
#   COOKIE_ADMIN=PHPSESSID=... COOKIE_VIGILE=PHPSESSID=... COOKIE_COOP=PHPSESSID=... bash tests/run.sh
#
# Serve un Lizmap con il modulo prositapi (Prosit API v2) e tre utenti collegati: uno in
# "admins", uno in "vigili", uno in "man_verde_es" (i cookie si copiano dal browser).
# Crea/ricrea SOLO i database prosit_test_* e i ruoli prosit_test_ro / prosit_test_rw:
# i database e i ruoli veri non vengono toccati. Serve un PostgreSQL con PostGIS.
# Avvia un server PHP temporaneo su 127.0.0.1:$PORT (predefinito 8765).
# =============================================================================
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=${PORT:-8765}
WORK=$(mktemp -d)
PASS=0; FAIL=0
cleanup() { [[ -n "${SRV:-}" ]] && kill "$SRV" 2>/dev/null; rm -rf "$WORK"; }
trap cleanup EXIT

echo "== database di prova"
psql -qX -v ON_ERROR_STOP=1 -d postgres -f "$ROOT/tests/fixtures.sql" >/dev/null 2>"$WORK/fx.err" || { cat "$WORK/fx.err"; exit 1; }
sed -e 's/prosit_ro/prosit_test_ro/g; s/prosit_rw/prosit_test_rw/g' \
    -e 's/^\\connect \(.*\)$/\\connect prosit_test_\1/' \
    -e 's/ON DATABASE \([a-z0-9_]*\) TO/ON DATABASE prosit_test_\1 TO/' \
    "$ROOT/sql/10_grant_prosit.sql" > "$WORK/grant.sql"
cp "$ROOT/sql/_grant_helper.sql" "$WORK/"
(cd "$WORK" && psql -qX -v ON_ERROR_STOP=1 -d postgres -v ro_pass=ro_test -v rw_pass=rw_test -f grant.sql >/dev/null) || exit 1

echo "== ambiente (configurazione di prova; utente e gruppi dal Lizmap di prova)"
php "$ROOT/tests/make_env.php" "$WORK" >/dev/null
PROSIT_CONFIG="$WORK/etc/prosit.php" php -S 127.0.0.1:$PORT "$ROOT/tests/router.php" >"$WORK/server.log" 2>&1 &
SRV=$!; sleep 1

B=http://127.0.0.1:$PORT/lizmap/prosit/api/v1
P=http://127.0.0.1:$PORT/lizmap/prosit
ADMIN="Cookie: ${COOKIE_ADMIN:?cookie di sessione Lizmap di un utente del gruppo admins (PHPSESSID=...)}"
VIG="Cookie: ${COOKIE_VIGILE:?cookie di un utente del gruppo vigili}"
COOP="Cookie: ${COOKIE_COOP:?cookie di un utente del gruppo man_verde_es}"

t() { # descrizione, codice HTTP atteso, testo atteso nella risposta, argomenti curl...
  local desc="$1" code="$2" want="$3"; shift 3
  local body status
  body=$(curl -s -w $'\n%{http_code}' "$@"); status=${body##*$'\n'}; body=${body%$'\n'*}
  if [[ "$status" == "$code" && "$body" == *"$want"* ]]; then PASS=$((PASS+1)); echo "  ok   $desc"
  else FAIL=$((FAIL+1)); echo "  FAIL $desc -> HTTP $status ${body:0:200}"; fi
}

echo "== servizio e autenticazione (utente chiesto a Lizmap)"
t "health senza login"                  200 '"prosit-api"'           $B/health
t "forma senza riscrittura Apache"      200 '"prosit-api"'           $P/api/index.php/v1/health
t "health completa: Lizmap raggiungibile" 200 '"lizmap_api":"ok"'   "$B/health?full=1"
t "anonimo respinto"                    401 'Sessione Lizmap'        $B/auth/me
t "sessione Lizmap riconosciuta"        200 '"auth_type":"session"'  -H "$ADMIN" $B/auth/me
t "gruppi per nome"                     200 'Polizia Municipale'     -H "$VIG" $B/auth/me
t "nome e cognome separati"             200 '"firstname":"Vito"'     -H "$VIG" $B/auth/me
t "app consentite per gruppo"           200 '"registro-tvcc":true'   -H "$VIG" $B/auth/me
t "sessione inesistente respinta"       401 'Sessione'               -H "Cookie: PHPSESSID=abcdefghijklmnopqrstuvwxyz0123" $B/auth/me
t "metodo non previsto"                 405 'Metodo'                 -X POST -H "$ADMIN" $B/auth/me

echo "== vecchia API v1 tolta (i dati sono sulla Prosit API v2)"
t "risorse generiche"                   404 'prosit/v2'              -H "$ADMIN" $B/segnalazioni
t "record per chiave"                   404 'prosit/v2'              -H "$ADMIN" $B/segnalazioni/1
t "elenco risorse"                      404 'prosit/v2'              -H "$ADMIN" $B/resources
t "mail del ticket"                     404 'prosit/v2'              -X POST -H "$ADMIN" $B/segnalazioni/1/mail
t "calendario"                          404 'prosit/v2'              -H "$COOP" $B/calendario/context
t "vecchia /data/servizio/tabella"      404 'prosit/v2'              -H "$ADMIN" "$P/api/index.php/data/segnalazioni_23/segn.segn_id"
t "session-check tolto"                 404 ''                       -H "$ADMIN" $P/api/session-check.php

echo "== servizi rimasti"
t "documenti: login richiesto"          401 'Sessione'               "$B/documenti/rekla?post_id=1&file=abc"
t "documenti: parametri validati"       400 'Parametri'              -H "$ADMIN" "$B/documenti/rekla?post_id=1&file=../x"
t "ainop tolto"                         404 'prosit/v2'              -H "$ADMIN" $B/ainop

echo "== pagine"
t "report: login richiesto"             401 'Accesso richiesto'      $P/app/report-ticket/
t "report: conteggi"                    200 'TOTALE'                 -H "$COOP" $P/app/report-ticket/
t "opendata: gruppo richiesto"          403 'opnd'                   -H "$VIG" $P/app/opendata-admin/
t "registro TVCC: gruppo richiesto"     403 'Accesso negato'         -H "$COOP" $P/app/registro-tvcc/
t "registro TVCC: aperto ai vigili"     200 'prosit-api-v2.js'       -H "$VIG" $P/app/registro-tvcc/
t "calendario: aperto al manutentore"   200 'prosit-api-v2.js'       -H "$COOP" $P/app/calendario-verde/
t "prosit.js senza client v1"           200 'prosit-api-v2.js'       $P/assets/prosit.js

echo
echo "Superati: $PASS   Falliti: $FAIL"
[[ $FAIL -eq 0 ]]
