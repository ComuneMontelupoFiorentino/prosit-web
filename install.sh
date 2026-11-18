#!/usr/bin/env bash
# =============================================================================
# PROSIT — installazione SENZA root (utente prosit2), senza toccare Apache ne' /etc
# -----------------------------------------------------------------------------
#   bash install.sh [opzioni]
#
#   --dest DIR          dove installare il codice (predefinito: /usr/local/bin/prosit/prosit-web
#                       se /usr/local/bin/prosit e' scrivibile, altrimenti ~/prosit)
#   --var DIR           dove tenere log, cache e dati (predefinito: /srv/data/prosit se /srv/data
#                       e' scrivibile, altrimenti <dest>/var); <dest>/var diventa un collegamento
#   --archive FILE      archivio .zip delle cartelle della vecchia VM: importa
#                       services.json (open data), log meteo, token QField
#   --lizmap-www PATH   cartella www di Lizmap (predefinito: /var/www/lizmap-attiva/www
#                       se esiste, altrimenti /var/www/html/lizmap)
#   --pg-ini FILE       credenziali di amministrazione PostgreSQL nel formato di pgzip.ini
#                       (predefinito: quello di TRANSFER-DB-compressed__pg2zip, se esiste)
#   --pg-user USER      in alternativa: utente amministratore (la password viene chiesta)
#   --skip-db           non crea/aggiorna i ruoli prosit_ro / prosit_rw
#   --projects DIR      cartella dei progetti QGIS di Lizmap (predefinito: ricavata dai
#                       repository di lizmapConfig.ini.php, altrimenti ~/lizmap-project):
#                       vi scrive media/js/default/prosit-lizmap.js per i popup QGIS
#   --legacy MODO       file "ponte" per i vecchi indirizzi nella www di Lizmap:
#                         completo (predefinito) tutti i vecchi indirizzi continuano a funzionare;
#                         minimo   solo quelli dei sistemi non riconfigurabili (stazione meteo,
#                                  app QField): la www di Lizmap resta pulita.
#                       La scelta viene ricordata (local/install.conf). Prima di passare a
#                       "minimo": bin/prosit legacy:report  mostra chi usa ancora i vecchi indirizzi.
#   --no-legacy         non crea ne' toglie file ponte
#   --remove-legacy     toglie i file ponte creati in precedenza ed esce
#   --yes               non chiede conferma
#
# Come funziona senza configurare Apache:
#   <www di Lizmap>/prosit  ->  collegamento simbolico a  <dest>/public
# Apache serve gia' tutto cio' che sta sotto /lizmap/, quindi i servizi rispondono su
# /lizmap/prosit/ e ricevono il cookie di sessione di Lizmap. Le password e i dati stanno
# in <dest>/local e <dest>/var, fuori da public/ e quindi mai raggiungibili dal browser.
#
# Unico requisito: poter scrivere nella cartella www di Lizmap (per il collegamento).
# Se non e' cosi', lo script si ferma e stampa l'unico comando da chiedere all'IT.
# Si puo' rilanciare: aggiorna il codice, non tocca local/ ne' var/.
# =============================================================================
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST=""; VAR_DIR=""
ARCHIVE=""; LIZMAP_WWW=""; PG_INI=""; PG_USER=""; PROJECTS=""
SKIP_DB=0; LEGACY=1; LEGACY_MODE=""; REMOVE_LEGACY=0; YES=0
WEB_USER=www-data
STAMP=$(date +%Y%m%d-%H%M%S)
MARK="prosit-legacy"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dest) DEST="$2"; shift 2 ;;
    --var) VAR_DIR="$2"; shift 2 ;;
    --archive) ARCHIVE="$2"; shift 2 ;;
    --lizmap-www) LIZMAP_WWW="$2"; shift 2 ;;
    --projects) PROJECTS="$2"; shift 2 ;;
    --pg-ini) PG_INI="$2"; shift 2 ;;
    --pg-user) PG_USER="$2"; shift 2 ;;
    --skip-db) SKIP_DB=1; shift ;;
    --no-legacy) LEGACY=0; shift ;;
    --legacy) LEGACY_MODE="$2"; shift 2 ;;
    --remove-legacy) REMOVE_LEGACY=1; shift ;;
    --yes|-y) YES=1; shift ;;
    -h|--help) sed -n '2,45p' "$0"; exit 0 ;;
    *) echo "Opzione sconosciuta: $1" >&2; exit 2 ;;
  esac
done

c_ok=$'\033[32m'; c_err=$'\033[31m'; c_warn=$'\033[33m'; c_info=$'\033[36m'; c_end=$'\033[0m'
ok()   { echo "${c_ok}OK${c_end}    $*"; }
info() { echo "${c_info}--${c_end}    $*"; }
warn() { echo "${c_warn}WARN${c_end}  $*"; WARNINGS+=("$*"); }
die()  { echo "${c_err}ERRORE${c_end} $*" >&2; exit 1; }
WARNINGS=()

[[ $EUID -ne 0 ]] || die "questo script va eseguito come utente normale (es. prosit2). Da root usare deploy/install-root.sh"
# cartelle scrivibili su questa VM: /usr/local/bin/prosit (codice) e /srv/data (dati)
if [[ -z "$DEST" ]]; then
  if [[ -d /usr/local/bin/prosit && -w /usr/local/bin/prosit ]]; then DEST=/usr/local/bin/prosit/prosit-web; else DEST="$HOME/prosit"; fi
fi
DEST=$(realpath -m "$DEST")
if [[ -z "$VAR_DIR" ]]; then
  if [[ -L "$DEST/var" ]]; then VAR_DIR=$(readlink -f "$DEST/var")                       # installazione precedente
  elif [[ -d "$DEST/var" ]]; then VAR_DIR="$DEST/var"
  elif [[ -d /srv/data && -w /srv/data ]]; then VAR_DIR=/srv/data/prosit
  else VAR_DIR="$DEST/var"; fi
fi
VAR_DIR=$(realpath -m "$VAR_DIR")
INSTALL_CONF="$DEST/local/install.conf"
if [[ -z "$LEGACY_MODE" && -f "$INSTALL_CONF" ]]; then
  LEGACY_MODE=$(sed -n 's/^LEGACY_MODE=\([a-z]*\)$/\1/p' "$INSTALL_CONF" | tail -1)
fi
LEGACY_MODE=${LEGACY_MODE:-completo}
[[ "$LEGACY_MODE" == completo || "$LEGACY_MODE" == minimo ]] || die "--legacy vuole: completo oppure minimo"
ME=$(id -un)

# -----------------------------------------------------------------------------
echo "== 1. Prerequisiti"
command -v php >/dev/null || die "php non trovato"
[[ $(php -r 'echo PHP_VERSION_ID;') -ge 80100 ]] || die "serve PHP >= 8.1"
for ext in pdo_pgsql pdo_sqlite curl json mbstring session; do
  php -m | grep -qi "^$ext$" || die "estensione PHP mancante nel PHP da riga di comando: $ext"
done
command -v setfacl >/dev/null || die "setfacl non disponibile (pacchetto acl): serve per dare a www-data l'accesso ai soli file necessari"
command -v curl >/dev/null || die "curl non trovato"
ok "PHP $(php -r 'echo PHP_VERSION;'), setfacl, curl"

if [[ -z "$LIZMAP_WWW" ]]; then
  for c in /var/www/lizmap-attiva/www /var/www/html/lizmap; do [[ -e "$c" ]] && { LIZMAP_WWW="$c"; break; }; done
fi
[[ -n "$LIZMAP_WWW" && -d "$LIZMAP_WWW" ]] || die "cartella www di Lizmap non trovata: indicarla con --lizmap-www"
LZ_REAL=$(readlink -f "$LIZMAP_WWW")
LZ_VER=$(grep -o '<version[^<]*</version>' "$LZ_REAL/../project.xml" 2>/dev/null | sed 's/<[^>]*>//g' || true)
ok "Lizmap ${LZ_VER:-?}: $LIZMAP_WWW -> $LZ_REAL"

LZ_CONF=$(realpath -m "$LZ_REAL/../var/config/lizmapConfig.ini.php")
# cartelle media/ dei repository Lizmap (una per riga): <cartella del repository>/../media
lizmap_media_dirs() {
  if [[ -n "$PROJECTS" ]]; then echo "$(realpath -m "$PROJECTS")/media"; return; fi
  local out=""
  if [[ -r "$LZ_CONF" ]]; then
    out=$(php -r '$a = @parse_ini_file($argv[1], true, INI_SCANNER_RAW) ?: [];
      $seen = [];
      foreach ($a as $sec => $v) {
        if (strncmp($sec, "repository:", 11) !== 0 || empty($v["path"])) continue;
        $d = dirname(rtrim(trim($v["path"], "\"\x27"), "/")) . "/media";
        $seen[$d] = 1;
      }
      echo implode("\n", array_keys($seen));' "$LZ_CONF")
  fi
  if [[ -n "$out" ]]; then echo "$out"; else echo "$HOME/lizmap-project/media"; fi
}

# --- solo rimozione dei file ponte
if [[ $REMOVE_LEGACY -eq 1 ]]; then
  n=0
  while IFS= read -r f; do
    rm -f "$f"; n=$((n+1))
    # toglie le cartelle rimaste vuote, risalendo fino alla www di Lizmap (esclusa)
    d=$(dirname "$f"); while [[ "$d" != "$LZ_REAL" && "$d" == "$LZ_REAL"/* ]] && rmdir "$d" 2>/dev/null; do d=$(dirname "$d"); done
  done < <(grep -rlF "$MARK" "$LZ_REAL" --include='*.php' --include='*.html' --include='.user.ini' 2>/dev/null || true)
  for d in $(lizmap_media_dirs); do
    f="$d/js/default/prosit-lizmap.js"
    if [[ -f "$f" ]] && grep -qF "$MARK" "$f"; then rm -f "$f"; n=$((n+1)); fi
  done
  [[ -L "$LZ_REAL/prosit" ]] && info "il collegamento $LZ_REAL/prosit resta (toglierlo con: rm $LZ_REAL/prosit)"
  ok "rimossi $n file ponte"; exit 0
fi

if [[ ! -w "$LZ_REAL" ]]; then
  echo
  echo "Non posso scrivere in $LZ_REAL: serve per il collegamento /lizmap/prosit."
  echo "Chiedere all'IT di eseguire UNA volta (non tocca Apache ne' i file di Lizmap):"
  echo
  echo "    setfacl -m u:$ME:rwx $LZ_REAL"
  echo
  echo "(oppure lo stesso setfacl -R su tutta la cartella di Lizmap, come gia' fatto per la configurazione)"
  exit 1
fi
ok "cartella www di Lizmap scrivibile"

if [[ $YES -eq 0 ]]; then
  echo; echo "Installo Prosit in $DEST e lo pubblico su /lizmap/prosit."
  read -r -p "Procedo? [s/N] " r; [[ "$r" =~ ^[sSyY]$ ]] || exit 0
fi

# -----------------------------------------------------------------------------
echo "== 2. Codice in $DEST"
mkdir -p "$DEST"
if [[ "$SRC" != "$DEST" ]]; then
  if command -v rsync >/dev/null; then
    rsync -a --delete --exclude '/var' --exclude '/local' --exclude '/.git/' "$SRC/" "$DEST/"
  else
    find "$DEST" -mindepth 1 -maxdepth 1 ! -name var ! -name local -exec rm -rf {} +
    (cd "$SRC" && tar cf - --exclude=./var --exclude=./local .) | (cd "$DEST" && tar xf -)
  fi
fi
if [[ "$VAR_DIR" != "$DEST/var" ]]; then
  [[ -e "$DEST/var" && ! -L "$DEST/var" ]] && die "$DEST/var e' una cartella: spostarne il contenuto in $VAR_DIR, toglierla e rilanciare"
  mkdir -p "$VAR_DIR"
  ln -sfn "$VAR_DIR" "$DEST/var"
fi
# installazione precedente in ~/prosit (versioni <= 1.2): riusa configurazione e dati
OLD="$HOME/prosit"
if [[ "$DEST" != "$OLD" && -f "$OLD/local/prosit.php" && ! -f "$DEST/local/prosit.php" ]]; then
  mkdir -p "$DEST/local" && cp -a "$OLD/local/." "$DEST/local/"
  [[ -f "$DEST/local/qfield.json" ]] && sed -i "s#$OLD/#$DEST/#g" "$DEST/local/qfield.json"
  info "configurazione ripresa da $OLD/local (password e chiavi invariate)"
  if [[ -d "$OLD/var/data" && ! -e "$VAR_DIR/data/opendata/services.json" ]]; then
    mkdir -p "$VAR_DIR/data" && cp -a "$OLD/var/data/." "$VAR_DIR/data/" 2>/dev/null || true
    info "dati ripresi da $OLD/var/data"
  fi
  MOVED_FROM="$OLD"
fi
mkdir -p "$VAR_DIR"/{log,cache,data/opendata/backup,data/meteo,data/qfield/tmp,data/qfield/zip} "$DEST/local"
# codice: leggibile da tutti (non contiene segreti), modificabile solo da $ME
find "$DEST" \( -path "$DEST/var" -o -path "$DEST/local" \) -prune -o -type d -exec chmod 755 {} +
find "$DEST" \( -path "$DEST/var" -o -path "$DEST/local" \) -prune -o -type f -exec chmod 644 {} +
chmod 755 "$DEST/bin/prosit" "$DEST/install.sh" "$DEST/deploy/install-root.sh"
# il server web deve poter attraversare le cartelle che portano a $DEST
for start in "$DEST" "$VAR_DIR"; do
  p="$start"
  while p=$(dirname "$p"); [[ "$p" != "/" ]]; do
    if [[ -O "$p" ]]; then setfacl -m u:$WEB_USER:x "$p"; fi
  done
done
# var/: scrivibile da www-data e da $ME (ACL anche per i file creati in futuro); nessun altro
# (solo sui file di $ME: quelli creati da www-data hanno gia' le ACL ereditate dalle cartelle)
find "$VAR_DIR" -user "$ME" -type d -exec chmod 770 {} +
find "$VAR_DIR" -user "$ME" -exec setfacl -m u:$WEB_USER:rwX,u:$ME:rwX {} +
find "$VAR_DIR" -user "$ME" -type d -exec setfacl -d -m u:$WEB_USER:rwX,u:$ME:rwX,o::- {} +
ok "codice in $DEST; dati in $VAR_DIR (scrivibili solo da $ME e $WEB_USER)"

# -----------------------------------------------------------------------------
echo "== 3. Configurazione locale in $DEST/local"
ETC="$DEST/local"
gen() { php -r 'echo bin2hex(random_bytes(20));'; }
CRED="$ETC/credenziali-$STAMP.txt"
# le credenziali generate si salvano SUBITO: se lo script si interrompe dopo, non vanno perse
save_cred() {
  ( umask 077
    [[ -f "$CRED" ]] || { echo "Prosit — credenziali generate il $(date)"; echo "Conservare in un posto sicuro e poi cancellare questo file."; echo; } > "$CRED"
    echo "$1" >> "$CRED" )
}
NEW_CRED=0
PGH=localhost; PGP=5432
if [[ -z "$PG_INI" && -z "$PG_USER" ]]; then
  for c in /usr/local/bin/prosit/TRANSFER-DB-compressed__pg2zip/pgzip.ini "$HOME/pgzip.ini" ./pgzip.ini; do [[ -r "$c" ]] && { PG_INI="$c"; break; }; done
fi
# lettura di pgzip.ini con lo stesso parser di pgzip (Python configparser): accetta i commenti "#",
# che il parser INI di PHP rifiuta (in quel caso tutti i valori risultavano vuoti)
ini() { python3 -c 'import configparser, sys
c = configparser.RawConfigParser()
with open(sys.argv[1], encoding="utf-8", errors="surrogateescape") as f: c.read_file(f)
v = c.get("postgresql", sys.argv[2], fallback="").strip().strip("\"\x27")
sys.stdout.buffer.write(v.encode("utf-8", "surrogateescape"))' "$PG_INI" "$1"; }
if [[ -n "$PG_INI" ]]; then
  PGH=$(ini host); PGH=${PGH:-localhost}; PGP=$(ini port); PGP=${PGP:-5432}
fi
[[ "$PGP" =~ ^[0-9]+$ ]] || die "porta PostgreSQL non valida: '$PGP'"
[[ "$PGH" =~ ^[A-Za-z0-9.:_-]+$ ]] || die "host PostgreSQL non valido: '$PGH'"
if [[ ! -f "$ETC/pg_service.conf" ]]; then
  RO_PASS=$(gen); RW_PASS=$(gen)
  sed -e "s/__RO_PASS__/$RO_PASS/g" -e "s/__RW_PASS__/$RW_PASS/g" -e "s/^host=localhost/host=$PGH/" -e "s/^port=5432/port=$PGP/" \
      "$DEST/etc/pg_service.conf.example" > "$ETC/pg_service.conf"
  save_cred "PostgreSQL prosit_ro: $RO_PASS"; save_cred "PostgreSQL prosit_rw: $RW_PASS"
  NEW_CRED=1; ok "creato pg_service.conf con password nuove per prosit_ro / prosit_rw"
else
  RO_PASS=$(awk -F= '/^\[/{s=$0} s=="[segnalazioni_23]" && $1=="password"{print $2; exit}' "$ETC/pg_service.conf")
  RW_PASS=$(awk -F= '/^\[/{s=$0} s=="[segnalazioni_23_rw]" && $1=="password"{print $2; exit}' "$ETC/pg_service.conf")
  info "pg_service.conf gia' presente: non modificato"
fi
[[ -f "$ETC/prosit.php" ]] || { sed "s#__LIZMAP_WWW__#$LIZMAP_WWW#" "$DEST/etc/prosit.php.example" > "$ETC/prosit.php"; ok "creato prosit.php"; }
[[ -f "$ETC/api_keys.php" ]] || { cp "$DEST/etc/api_keys.php.example" "$ETC/api_keys.php"; ok "creato api_keys.php (stazione meteo accettata per IP)"; }
[[ -f "$ETC/qfield.json" ]] || { sed "s#__PROSIT__#$DEST#g" "$DEST/etc/qfield.json.example" > "$ETC/qfield.json"; ok "creato qfield.json"; }
# segreti: leggibili solo da $ME e (in sola lettura) da www-data
chmod 750 "$ETC"
for f in "$ETC"/*; do
  case "$f" in *credenziali-*) chmod 600 "$f" ;; *) chmod 640 "$f"; setfacl -m u:$WEB_USER:r "$f" ;; esac
done
setfacl -m u:$WEB_USER:rx "$ETC"
ok "local/: 0750, file 0640 + lettura per $WEB_USER"

# -----------------------------------------------------------------------------
echo "== 4. Ruoli PostgreSQL"
if [[ $SKIP_DB -eq 1 ]]; then
  info "saltato (--skip-db)"
else
  command -v psql >/dev/null || die "psql non trovato"
  PSQL=(psql -X -q -v ON_ERROR_STOP=1 -d postgres)
  if [[ -n "$PG_INI" ]]; then
    export PGHOST="$PGH" PGPORT="$PGP" PGUSER="$(ini user)" PGPASSWORD="$(ini password)"
    [[ -n "$PGUSER" ]] || die "in $PG_INI non trovo 'user' nella sezione [postgresql]: rilanciare con --pg-user NOME (la password viene chiesta)"
    info "credenziali di amministrazione da $PG_INI (utente $PGUSER)"
  elif [[ -n "$PG_USER" ]]; then
    export PGHOST="$PGH" PGPORT="$PGP" PGUSER="$PG_USER"
    info "utente $PG_USER: psql chiedera' la password"
  else
    die "servono credenziali di amministrazione PostgreSQL: --pg-ini pgzip.ini oppure --pg-user postgres (o --skip-db)"
  fi
  set +e
  OUT=$(cd "$DEST/sql" && "${PSQL[@]}" -v ro_pass="$RO_PASS" -v rw_pass="$RW_PASS" -f 10_grant_prosit.sql 2>&1)
  RC=$?
  set -e
  unset PGPASSWORD
  echo "$OUT" | grep -v '^\s*$' | sed 's/^/      /'
  [[ $RC -eq 0 ]] || die "applicazione dei permessi fallita (vedi sopra)"
  echo "$OUT" | grep -q 'MANCA' && warn "alcuni oggetti del database non esistono (righe MANCA sopra)"
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
    cp "$f" "$DEST/var/data/meteo/dati_meteo.log"; ok "meteo: dati_meteo.log importato"
  fi
  f=$(find "$TMP" -path '*ws-censimento-qfield/config.json' | head -1)
  if [[ -n "$f" ]] && grep -q SOSTITUIRE "$ETC/qfield.json"; then
    php "$DEST/bin/prosit" qfield:import-tokens "$f" "$ETC/qfield.json" | sed 's/^/      /'
    chmod 640 "$ETC/qfield.json"; setfacl -m u:$WEB_USER:r "$ETC/qfield.json"
  fi
else
  if [[ ! -f "$DEST/var/data/opendata/services.json" ]]; then
    cp "$DEST/deploy/seed/opendata/services.json" "$DEST/var/data/opendata/services.json"
    info "open data: services.json iniziale dal pacchetto"
  fi
fi
find "$VAR_DIR/data" -user "$ME" -exec setfacl -m u:$WEB_USER:rwX,u:$ME:rwX {} +
MB=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["post_close"]["params"]["media_base"] ?? "";' "$ETC/qfield.json")
# percorso d'esempio non valido su questa VM: lo si ricava dai repository Lizmap (<progetti>/media/img)
if [[ -n "$MB" && ! -d "$MB" ]]; then
  for d in $(lizmap_media_dirs); do
    if [[ -d "$d" ]]; then
      MB="$d/img"
      php -r '$f=$argv[1]; $d=json_decode(file_get_contents($f),true); $d["post_close"]["params"]["media_base"]=$argv[2];
        file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");' "$ETC/qfield.json" "$MB"
      info "QField media_base impostata a $MB (cartella media dei progetti Lizmap)"
      break
    fi
  done
  [[ -d "$MB" ]] || { mkdir -p "$MB" 2>/dev/null && info "creata $MB"; } || true
fi
if [[ -n "$MB" ]]; then
  if [[ -d "$MB" && -O "$MB" ]]; then
    find "$MB" -user "$ME" -exec setfacl -m u:$WEB_USER:rwX {} + && find "$MB" -user "$ME" -type d -exec setfacl -d -m u:$WEB_USER:rwX,u:$ME:rwX {} +
    ok "QField media_base scrivibile da $WEB_USER: $MB"
  else
    warn "QField media_base $MB inesistente o non tuo: creala (mkdir -p) e rilancia, oppure correggi il percorso in local/qfield.json"
  fi
fi

# -----------------------------------------------------------------------------
echo "== 6. Pubblicazione su /lizmap/prosit"
LINK="$LZ_REAL/prosit"
if [[ -e "$LINK" && ! -L "$LINK" ]]; then
  die "$LINK esiste ed e' una cartella vera: spostarla e rilanciare"
fi
ln -sfn "$DEST/public" "$LINK"
ok "$LINK -> $DEST/public"

if [[ $LEGACY -eq 1 ]]; then
  n=0; skipped=0; removed=0; KEEP=()
  while read -r kind old new flag; do
    [[ -z "$kind" || "$kind" == \#* ]] && continue
    target="$LZ_REAL/$old"
    if [[ "$LEGACY_MODE" == minimo && "$flag" != fisso ]]; then
      continue     # tolto piu' sotto, se era stato creato da Prosit
    fi
    KEEP+=("$target")
    if [[ -e "$target" ]] && ! grep -qF "$MARK" "$target" 2>/dev/null; then
      warn "esiste gia' $target (non creato da Prosit): non lo sovrascrivo. Se e' il vecchio file copiato dalla vecchia VM, eliminarlo e rilanciare."
      skipped=$((skipped+1)); continue
    fi
    mkdir -p "$(dirname "$target")"
    url="/lizmap/prosit/$new"
    # i ponti non "fissi" registrano ogni uso (bin/prosit legacy:report), per sapere quando toglierli
    hit=""; beacon=""
    if [[ "$flag" != fisso ]]; then
      hit=$(printf "require_once '%s/src/legacy_hit.php';\nprosit_legacy_hit('/lizmap/%s');\n" "$DEST" "$old")
      beacon=$(printf 'try { navigator.sendBeacon("/lizmap/prosit/api/legacy-hit.php?p=" + encodeURIComponent("/lizmap/%s")); } catch (e) {}\n' "$old")
      hit+=$'\n'; beacon+=$'\n'      # $(...) toglie l'a capo finale
    fi
    case "$kind" in
      html)
        printf '<!doctype html><!-- %s: pagina spostata, generata da install.sh -->\n<meta charset="utf-8"><title>Pagina spostata</title>\n<script>\n%slocation.replace("%s" + location.search + location.hash);\n</script>\n<noscript><meta http-equiv="refresh" content="0;url=%s"></noscript>\n<p>La pagina &egrave; stata spostata: <a href="%s">%s</a></p>\n' "$MARK" "$beacon" "$url" "$url" "$url" "$url" > "$target" ;;
      redirect)
        printf '<?php // %s: indirizzo spostato, generato da install.sh\n%s$q = $_SERVER["QUERY_STRING"] ?? "";\nheader("Location: %s" . ($q !== "" ? "?" . $q : ""), true, 302);\n' "$MARK" "$hit" "$url" > "$target" ;;
      include)
        printf '<?php // %s: stesso indirizzo di prima, codice in %s\n%srequire %s;\n' "$MARK" "$DEST" "$hit" "'$DEST/public/$new'" > "$target"
        src_ini="$DEST/public/$(dirname "$new")/.user.ini"
        if [[ -f "$src_ini" ]]; then { echo "; $MARK"; cat "$src_ini"; } > "$(dirname "$target")/.user.ini"; KEEP+=("$(dirname "$target")/.user.ini"); fi ;;
      *) continue ;;
    esac
    n=$((n+1))
  done < "$DEST/deploy/legacy-map.txt"
  # file ponte di Prosit non piu' previsti (modo minimo, voci tolte dalla mappa): via, con le cartelle rimaste vuote
  while IFS= read -r f; do
    keep=0; for k in "${KEEP[@]}"; do [[ "$f" == "$k" ]] && { keep=1; break; }; done
    [[ $keep -eq 1 ]] && continue
    rm -f "$f"; removed=$((removed+1))
    d=$(dirname "$f"); while [[ "$d" != "$LZ_REAL" && "$d" == "$LZ_REAL"/* ]] && rmdir "$d" 2>/dev/null; do d=$(dirname "$d"); done
  done < <(grep -rlF "$MARK" "$LZ_REAL" --include='*.php' --include='*.html' --include='.user.ini' 2>/dev/null || true)
  ok "file ponte ($LEGACY_MODE): $n presenti, $removed tolti, $skipped saltati"
  [[ "$LEGACY_MODE" == minimo ]] && info "vecchi indirizzi attivi solo per i sistemi esterni (stazione meteo, QField)"
  ( umask 027; { echo "# scelte di install.sh (ricordate ai lanci successivi)"; echo "LEGACY_MODE=$LEGACY_MODE"; } > "$INSTALL_CONF" )
fi


# --- popup creati in QGIS: linguaggio comune delle schede dentro le mappe Lizmap
LOADER_SRC="$DEST/qgis/prosit-lizmap.loader.js"
for MD in $(lizmap_media_dirs); do
  f="$MD/js/default/prosit-lizmap.js"
  if [[ -e "$f" ]] && ! grep -qF "$MARK" "$f"; then
    warn "$f esiste e non e' di Prosit: non lo sovrascrivo"; continue
  fi
  if ! mkdir -p "$MD/js/default" 2>/dev/null || [[ ! -w "$MD/js/default" ]]; then
    warn "non posso scrivere in $MD/js/default: copiare a mano $LOADER_SRC in $f"; continue
  fi
  { echo "/* $MARK: generato da install.sh, sorgente $LOADER_SRC */"; cat "$LOADER_SRC"; } > "$f"
  chmod 644 "$f"; [[ -O "$MD/js/default" ]] && setfacl -m u:$WEB_USER:rx "$MD/js/default" "$MD/js" "$MD" 2>/dev/null || true
  ok "popup QGIS: $f"
done
if [[ -r "$LZ_CONF" ]]; then
  MISSING=$(php -r '$a = @parse_ini_file($argv[1], true, INI_SCANNER_RAW) ?: []; $m = [];
    foreach ($a as $sec => $v) {
      if (strncmp($sec, "repository:", 11) === 0 && trim((string) ($v["allowUserDefinedThemes"] ?? ""), "\"\x27 ") !== "1") $m[] = substr($sec, 11);
    }
    echo implode(" ", $m);' "$LZ_CONF")
  if [[ -n "$MISSING" ]]; then
    warn "repository Lizmap senza allowUserDefinedThemes=1 ($MISSING): i popup QGIS non caricheranno Prosit. Attivarlo da Amministrazione > Configurazione Lizmap > repository (casella \"Allow themes/JavaScript codes for this repository\") oppure aggiungere allowUserDefinedThemes=1 nella sezione [repository:...] di $LZ_CONF"
  else
    ok "allowUserDefinedThemes=1 su tutti i repository"
  fi
else
  info "non leggo $LZ_CONF: verificare che i repository abbiano allowUserDefinedThemes=1"
fi

# -----------------------------------------------------------------------------
echo "== 7. Verifica (eseguita da PHP-FPM, quindi come $WEB_USER)"
H=""
for base in http://127.0.0.1 https://127.0.0.1; do
  H=$(curl -sk --max-time 10 "$base/lizmap/prosit/api/index.php/v1/health?full=1" || true)
  [[ "$H" == *'"prosit-api"'* ]] && break
done
if [[ "$H" != *'"prosit-api"'* ]]; then
  echo "      risposta: ${H:0:300}"
  die "/lizmap/prosit non risponde come previsto. Cause tipiche: Apache non segue i collegamenti simbolici (Options FollowSymLinks) o $WEB_USER non puo' leggere $DEST"
fi
php -r '
  $d = json_decode(stream_get_contents(STDIN), true)["data"] ?? [];
  $bad = 0;
  foreach (($d["checks"] ?? []) as $k => $v) {
    $isOk = $v === "ok" || in_array($k, ["php_user", "session"], true);
    if (!$isOk) { $bad++; }
    printf("      %-6s %-32s %s\n", $isOk ? "ok" : "ERR", $k, $v);
  }
  exit($bad ? 1 : 0);' <<< "$H" || warn "la verifica ha trovato problemi (righe ERR sopra)"
php "$DEST/bin/prosit" check >/dev/null 2>&1 || true


[[ -n "${MOVED_FROM:-}" ]] && warn "Prosit ora sta in $DEST: verificato che tutto funzioni, la vecchia copia $MOVED_FROM si puo' cancellare"
[[ $NEW_CRED -eq 1 ]] && ok "credenziali generate in $CRED (leggibile solo da $ME)"

echo
echo "Installazione completata:"
echo "  Dati         Prosit API v2: /lizmap/api.php/prosit/v2  (Lizmap + PostgREST, pacchetto prosit-api)"
echo "  Servizi      /lizmap/prosit/api/index.php/v1/health  auth/me  documenti/rekla"
echo "  Schede       /lizmap/prosit/schede/disservizio.html?seg_id=..."
echo "  Catalogo     /lizmap/prosit/schede/catalogo.html  (componenti per i popup QGIS)"
echo "  Applicazioni /lizmap/prosit/app/registro-tvcc/  calendario-verde/  report-ticket/  opendata-admin/"
if [[ ${#WARNINGS[@]} -gt 0 ]]; then echo; echo "Da completare:"; for w in "${WARNINGS[@]}"; do echo "  - $w"; done; fi
