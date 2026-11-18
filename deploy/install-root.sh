#!/usr/bin/env bash
# =============================================================================
# PROSIT — installazione DA ROOT (per l'IT): /opt/prosit, /etc/prosit, Apache
# -----------------------------------------------------------------------------
# Senza permessi di root usare invece ../install.sh (installazione nella home).
#   sudo bash deploy/install-root.sh [opzioni]
#
#   --archive FILE      archivio .zip delle cartelle della vecchia VM: importa
#                       services.json (open data), log meteo, token QField
#   --lizmap-www PATH   cartella www di Lizmap come la vede Apache
#                       (predefinito: /var/www/lizmap-attiva/www se esiste, altrimenti /var/www/html/lizmap)
#   --pg-host HOST      PostgreSQL (predefinito localhost)
#   --pg-port PORT      PostgreSQL (predefinito 5432)
#   --skip-db           non crea/aggiorna i ruoli prosit_ro / prosit_rw
#   --skip-apache       non modifica Apache (stampa le istruzioni)
#   --yes               non chiede conferma
#
# Si puo' rilanciare: aggiorna il codice, non tocca /etc/prosit ne' i dati in var/.
# Ogni modifica ad Apache e' preceduta da una copia in /root/prosit-backup-<data>/
# e annullata da sola se la configurazione non e' valida o la verifica fallisce.
# =============================================================================
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST=/opt/prosit
ETC=/etc/prosit
WEB_USER=www-data
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP=/root/prosit-backup-$STAMP
CRED=/root/prosit-credenziali-$STAMP.txt

ARCHIVE=""; LIZMAP_WWW=""; PG_HOST=localhost; PG_PORT=5432
SKIP_DB=0; SKIP_APACHE=0; YES=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --archive) ARCHIVE="$2"; shift 2 ;;
    --lizmap-www) LIZMAP_WWW="$2"; shift 2 ;;
    --pg-host) PG_HOST="$2"; shift 2 ;;
    --pg-port) PG_PORT="$2"; shift 2 ;;
    --skip-db) SKIP_DB=1; shift ;;
    --skip-apache) SKIP_APACHE=1; shift ;;
    --yes|-y) YES=1; shift ;;
    -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
    *) echo "Opzione sconosciuta: $1" >&2; exit 2 ;;
  esac
done

c_ok=$'\033[32m'; c_err=$'\033[31m'; c_warn=$'\033[33m'; c_info=$'\033[36m'; c_end=$'\033[0m'
ok()   { echo "${c_ok}OK${c_end}    $*"; }
info() { echo "${c_info}--${c_end}    $*"; }
warn() { echo "${c_warn}WARN${c_end}  $*"; WARNINGS+=("$*"); }
die()  { echo "${c_err}ERRORE${c_end} $*" >&2; exit 1; }
WARNINGS=()

[[ $EUID -eq 0 ]] || die "eseguire come root (sudo bash install.sh)"

# -----------------------------------------------------------------------------
echo "== 1. Prerequisiti"
command -v php >/dev/null || die "php non trovato"
PHPV=$(php -r 'echo PHP_VERSION_ID;')
[[ $PHPV -ge 80100 ]] || die "serve PHP >= 8.1 (trovato $(php -r 'echo PHP_VERSION;'))"
for ext in pdo_pgsql pdo_sqlite curl json mbstring session; do
  php -m | grep -qi "^$ext$" || die "estensione PHP mancante: $ext (es. apt install php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-${ext/pdo_/})"
done
ok "PHP $(php -r 'echo PHP_VERSION;') con le estensioni necessarie"
id "$WEB_USER" >/dev/null 2>&1 || die "utente $WEB_USER inesistente"

if [[ -z "$LIZMAP_WWW" ]]; then
  for c in /var/www/lizmap-attiva/www /var/www/html/lizmap; do
    [[ -e "$c" ]] && { LIZMAP_WWW="$c"; break; }
  done
fi
[[ -n "$LIZMAP_WWW" && -d "$LIZMAP_WWW" ]] || die "cartella www di Lizmap non trovata: indicarla con --lizmap-www"
LZ_REAL=$(readlink -f "$LIZMAP_WWW")
LZ_VER=$(grep -o '<version[^<]*</version>' "$LZ_REAL/../project.xml" 2>/dev/null | sed 's/<[^>]*>//g' || true)
ok "Lizmap ${LZ_VER:-?}: $LIZMAP_WWW -> $LZ_REAL"

if [[ $YES -eq 0 ]]; then
  echo; echo "Installo Prosit in $DEST (configurazione in $ETC)."
  read -r -p "Procedo? [s/N] " r; [[ "$r" =~ ^[sSyY]$ ]] || exit 0
fi

# -----------------------------------------------------------------------------
echo "== 2. Codice in $DEST"
mkdir -p "$DEST" "$BACKUP"
# configurazione Apache attualmente in uso: serve per il ripristino se la nuova non funziona
[[ -d "$DEST/deploy/apache" ]] && cp -a "$DEST/deploy/apache" "$BACKUP/prosit-apache-precedente"
if command -v rsync >/dev/null; then
  rsync -a --delete --exclude '/var/' --exclude '/.git/' "$SRC/" "$DEST/"
else
  find "$DEST" -mindepth 1 -maxdepth 1 ! -name var -exec rm -rf {} +
  (cd "$SRC" && tar cf - --exclude=./var .) | (cd "$DEST" && tar xf -)
fi
mkdir -p "$DEST"/var/{log,cache,data/opendata/backup,data/meteo}
chown -R root:root "$DEST"
find "$DEST" -path "$DEST/var" -prune -o -type d -exec chmod 755 {} +
find "$DEST" -path "$DEST/var" -prune -o -type f -exec chmod 644 {} +
chmod 755 "$DEST/bin/prosit" "$DEST/install.sh" "$DEST/deploy/install-root.sh"
chown -R "$WEB_USER:$WEB_USER" "$DEST/var"
chmod -R u=rwX,g=rwX,o= "$DEST/var"
ok "codice copiato (sola lettura per $WEB_USER), var/ scrivibile da $WEB_USER"

# -----------------------------------------------------------------------------
echo "== 3. Configurazione in $ETC"
mkdir -p "$ETC"; chown root:"$WEB_USER" "$ETC"; chmod 750 "$ETC"
gen() { php -r 'echo bin2hex(random_bytes(20));'; }
NEW_CRED=0
if [[ ! -f "$ETC/pg_service.conf" ]]; then
  RO_PASS=$(gen); RW_PASS=$(gen)
  sed -e "s/__RO_PASS__/$RO_PASS/g" -e "s/__RW_PASS__/$RW_PASS/g" -e "s/^host=localhost/host=$PG_HOST/" -e "s/^port=5432/port=$PG_PORT/" \
      "$DEST/etc/pg_service.conf.example" > "$ETC/pg_service.conf"
  NEW_CRED=1; ok "creato pg_service.conf con password nuove per prosit_ro / prosit_rw"
else
  RO_PASS=$(awk -F= '/^\[/{s=$0} s=="[segnalazioni_23]" && $1=="password"{print $2; exit}' "$ETC/pg_service.conf")
  RW_PASS=$(awk -F= '/^\[/{s=$0} s=="[segnalazioni_23_rw]" && $1=="password"{print $2; exit}' "$ETC/pg_service.conf")
  info "pg_service.conf gia' presente: non modificato"
fi
if [[ ! -f "$ETC/prosit.php" ]]; then
  sed "s#__LIZMAP_WWW__#$LIZMAP_WWW#" "$DEST/etc/prosit.php.example" > "$ETC/prosit.php"
  ok "creato prosit.php (lizmap_www = $LIZMAP_WWW)"
fi
[[ -f "$ETC/api_keys.php" ]] || { cp "$DEST/etc/api_keys.php.example" "$ETC/api_keys.php"; ok "creato api_keys.php (stazione meteo accettata per IP)"; }
[[ -f "$ETC/qfield.json" ]] || { sed "s#__PROSIT__#$DEST#g" "$DEST/etc/qfield.json.example" > "$ETC/qfield.json"; ok "creato qfield.json (token da impostare)"; }
chown root:"$WEB_USER" "$ETC"/*; chmod 640 "$ETC"/*
ok "permessi $ETC: 0750 / file 0640 root:$WEB_USER"

# -----------------------------------------------------------------------------
echo "== 4. Ruoli PostgreSQL"
if [[ $SKIP_DB -eq 1 ]]; then
  info "saltato (--skip-db): eseguire a mano sql/10_grant_prosit.sql"
else
  command -v psql >/dev/null || die "psql non trovato"
  # come superuser postgres: socket locale (autenticazione peer) se il server e' su questa macchina
  PSQL_CONN=(-p "$PG_PORT"); [[ "$PG_HOST" != "localhost" ]] && PSQL_CONN+=(-h "$PG_HOST")
  set +e
  OUT=$(cd "$DEST/sql" && sudo -u postgres psql -X -q "${PSQL_CONN[@]}" -d postgres -v ON_ERROR_STOP=1 \
          -v ro_pass="$RO_PASS" -v rw_pass="$RW_PASS" -f 10_grant_prosit.sql 2>&1)
  RC=$?
  set -e
  echo "$OUT" | grep -v '^\s*$' | sed 's/^/      /'
  [[ $RC -eq 0 ]] || die "applicazione dei permessi fallita (vedi sopra). Si puo' rilanciare con --skip-db e farlo a mano."
  echo "$OUT" | grep -q 'MANCA' && warn "alcuni oggetti del database non esistono (righe MANCA sopra): le risorse corrispondenti daranno errore"
  ok "ruoli prosit_ro / prosit_rw e permessi applicati"
fi

# -----------------------------------------------------------------------------
echo "== 5. Dati della vecchia VM"
if [[ -n "$ARCHIVE" ]]; then
  [[ -f "$ARCHIVE" ]] || die "archivio non trovato: $ARCHIVE"
  command -v unzip >/dev/null || die "unzip non installato"
  TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
  unzip -q "$ARCHIVE" -d "$TMP"
  f=$(find "$TMP" -path '*opendata/admin-service/data/services.json' | head -1)
  if [[ -n "$f" && ! -f "$DEST/var/data/opendata/services.json" ]]; then
    cp "$f" "$DEST/var/data/opendata/services.json"
    cp "$(dirname "$f")"/backup/*.json "$DEST/var/data/opendata/backup/" 2>/dev/null || true
    ok "open data: services.json importato"
  fi
  f=$(find "$TMP" -path '*09-ambiente/dati_meteo.log' | head -1)
  if [[ -n "$f" && ! -f "$DEST/var/data/meteo/dati_meteo.log" ]]; then
    cp "$f" "$DEST/var/data/meteo/dati_meteo.log"; ok "meteo: dati_meteo.log importato ($(du -h "$f" | cut -f1))"
  fi
  f=$(find "$TMP" -path '*ws-censimento-qfield/config.json' | head -1)
  if [[ -n "$f" ]] && grep -q SOSTITUIRE "$ETC/qfield.json"; then
    php "$DEST/bin/prosit" qfield:import-tokens "$f" "$ETC/qfield.json" | sed 's/^/      /'
    chown root:"$WEB_USER" "$ETC/qfield.json"; chmod 640 "$ETC/qfield.json"
  fi
  chown -R "$WEB_USER:$WEB_USER" "$DEST/var/data"
else
  [[ -f "$DEST/var/data/opendata/services.json" ]] || { cp "$DEST/deploy/seed/opendata/services.json" "$DEST/var/data/opendata/services.json"; chown "$WEB_USER:$WEB_USER" "$DEST/var/data/opendata/services.json"; info "open data: services.json iniziale dal pacchetto"; }
  info "nessun --archive: log meteo e token QField non importati"
fi

# -----------------------------------------------------------------------------
echo "== 6. Cartelle di servizio"
mkdir -p "$DEST"/var/data/qfield/{tmp,zip}; chown -R "$WEB_USER:$WEB_USER" "$DEST/var/data/qfield"
ok "QField: $DEST/var/data/qfield (fuori dalla web root)"
if id prosit2 >/dev/null 2>&1 && command -v setfacl >/dev/null; then
  setfacl -m u:prosit2:rwx "$DEST/var/data/meteo" && setfacl -d -m u:prosit2:rwX,u:$WEB_USER:rwX "$DEST/var/data/meteo"
  [[ -f "$DEST/var/data/meteo/dati_meteo.log" ]] && setfacl -m u:prosit2:rw "$DEST/var/data/meteo/dati_meteo.log"
  setfacl -m u:prosit2:rx "$DEST/var" "$DEST/var/data" "$DEST/var/log"; setfacl -d -m u:prosit2:r "$DEST/var/log"
  ok "ACL per prosit2 su var/data/meteo (process_meteo_peaks.php) e lettura dei log"
else
  warn "utente prosit2 o setfacl assenti: permessi meteo per process_meteo_peaks.php da dare a mano"
fi
MB=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["post_close"]["params"]["media_base"] ?? "";' "$ETC/qfield.json")
if [[ -n "$MB" ]]; then
  if [[ -d "$MB" ]] && sudo -u "$WEB_USER" test -w "$MB"; then ok "QField media_base scrivibile: $MB"
  else warn "QField media_base $MB non esiste o non e' scrivibile da $WEB_USER: mkdir -p '$MB' && setfacl -R -m u:$WEB_USER:rwX,d:u:$WEB_USER:rwX '$MB'"; fi
fi

# -----------------------------------------------------------------------------
echo "== 7. Apache"
INC="Include $DEST/deploy/apache/prosit.inc"
if [[ $SKIP_APACHE -eq 1 ]] || ! command -v apache2ctl >/dev/null; then
  info "Apache non modificato. Aggiungere come PRIMA riga dentro ogni <VirtualHost> di Lizmap:"
  info "    $INC"
  info "poi: a2enmod headers && apache2ctl configtest && systemctl reload apache2"
else
  mapfile -t SITES < <(for f in /etc/apache2/sites-enabled/*.conf; do [[ -e "$f" ]] && readlink -f "$f"; done | sort -u)
  [[ ${#SITES[@]} -gt 0 ]] || die "nessun sito abilitato in /etc/apache2/sites-enabled"
  CHANGED=()
  for s in "${SITES[@]}"; do
    grep -q '<VirtualHost' "$s" || continue
    cp -p "$s" "$BACKUP/"
    if grep -qF "$INC" "$s"; then info "gia' incluso in $s"; continue; fi
    sed -i "/<VirtualHost[^>]*>/a\\    $INC" "$s"
    CHANGED+=("$s")
  done
  restore() {
    for s in "${CHANGED[@]}"; do cp -p "$BACKUP/$(basename "$s")" "$s"; done
    if [[ -d "$BACKUP/prosit-apache-precedente" ]]; then
      cp -a "$BACKUP/prosit-apache-precedente/." "$DEST/deploy/apache/"
    elif [[ ${#CHANGED[@]} -eq 0 ]]; then
      # prima installazione senza file modificati: disattiva l'include per sicurezza
      : > "$DEST/deploy/apache/prosit.inc"
    fi
    [[ "${HEADERS_ENABLED:-0}" -eq 1 ]] && a2dismod -q headers >/dev/null 2>&1 || true
    apache2ctl configtest >/dev/null 2>&1 && systemctl reload apache2 || true
  }
  HEADERS_ENABLED=0
  if [[ ! -e /etc/apache2/mods-enabled/headers.load ]]; then a2enmod -q headers >/dev/null; HEADERS_ENABLED=1; fi
  CT=$(apache2ctl configtest 2>&1 || true)
  if [[ "$CT" != *"Syntax OK"* ]]; then
    echo "$CT" | sed 's/^/      /'
    restore; die "configurazione Apache non valida: ripristinata la configurazione precedente (copia in $BACKUP)"
  fi
  systemctl reload apache2 || { restore; die "reload di Apache fallito: configurazione ripristinata"; }
  sleep 1
  H=""
  for url in http://127.0.0.1/lizmap/prosit/api/v1/health https://127.0.0.1/lizmap/prosit/api/v1/health; do
    H=$(curl -sk --max-time 5 "$url" || true); [[ "$H" == *'"prosit-api"'* ]] && break
  done
  if [[ "$H" != *'"prosit-api"'* ]]; then
    echo "      risposta: ${H:0:200}"
    restore; die "l'API non risponde come previsto su /lizmap/prosit (PHP non attivo per /opt/prosit?): Apache ripristinato da $BACKUP"
  fi
  ok "Apache: incluso in ${#CHANGED[@]} file (copia in $BACKUP), /lizmap/prosit/api/v1/health risponde"
fi

# -----------------------------------------------------------------------------
echo "== 8. Verifica"
sudo -u "$WEB_USER" env UPLOADER_CONFIG="$ETC/qfield.json" php "$DEST/bin/prosit" check | sed 's/^/      /' || warn "la verifica ha trovato problemi (vedi sopra): sudo -u $WEB_USER $DEST/bin/prosit check"

if [[ $NEW_CRED -eq 1 ]]; then
  umask 077
  {
    echo "Prosit — credenziali generate il $(date)"
    echo "Conservare in un posto sicuro e poi cancellare questo file."
    echo
    echo "PostgreSQL prosit_ro: $RO_PASS"
    echo "PostgreSQL prosit_rw: $RW_PASS"
  } > "$CRED"
  ok "credenziali generate salvate in $CRED (solo root)"
fi

echo
echo "Installazione completata. Indirizzi:"
echo "  Servizi      /lizmap/prosit/api/v1/health  (dati: Prosit API v2, /lizmap/api.php/prosit/v2)"
echo "  Schede       /lizmap/prosit/schede/disservizio.html?seg_id=..."
echo "  Applicazioni /lizmap/prosit/app/registro-tvcc/  calendario-verde/  report-ticket/  opendata-admin/"
if [[ ${#WARNINGS[@]} -gt 0 ]]; then echo; echo "Da completare:"; for w in "${WARNINGS[@]}"; do echo "  - $w"; done; fi
