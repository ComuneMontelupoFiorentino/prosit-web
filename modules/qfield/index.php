<?php
/**
 * index.php Servizio che RICEVE i dati inviati dall'app Android.
 *
 * PHP puro: nessuna libreria esterna, nessun processo da tenere acceso.
 * Basta copiare questa cartella sul webserver.
 *
 * Endpoint:
 *   GET  .../index.php?a=health   -> l'app verifica che il server risponda
 *   GET  .../index.php?a=profilo  -> dice cosa il server si aspetta per quel token
 *   POST .../index.php?a=upload   -> riceve i file
 *
 * (Con il file .htaccess incluso funzionano anche gli indirizzi
 *  puliti: .../health, .../profilo, .../upload)
 */

declare(strict_types=1);

// Non mostriamo mai gli errori PHP a schermo: uscirebbe HTML e l'app
// si aspetta JSON. Li scriviamo invece nel nostro log.
ini_set('display_errors', '0');
error_reporting(E_ALL);

date_default_timezone_set('Europe/Rome');

// La configurazione contiene il TOKEN: meglio tenerla fuori dalla cartella
// pubblica. Se imposti la variabile d'ambiente UPLOADER_CONFIG (in Apache:
// SetEnv UPLOADER_CONFIG /etc/qfield-uploader/config.json) viene usata quella,
// altrimenti si ripiega sul config.json accanto a index.php.
$_cfg = getenv('UPLOADER_CONFIG') ?: ($_SERVER['UPLOADER_CONFIG'] ?? '');
define('CONFIG_FILE', ($_cfg && is_readable($_cfg)) ? $_cfg : __DIR__ . '/config.json');

// Ogni richiesta ha un suo codice e un cronometro: cosi' nel log si segue
// una singola operazione dall'inizio alla fine, anche se ne arrivano piu'
// contemporaneamente.
$REQ = bin2hex(random_bytes(3));
$T0  = microtime(true);

/**
 * Riga di log con codice richiesta e millisecondi trascorsi.
 * $livello: INFO (normale) | DETT (solo se log_level = "dettagliato") | ERR
 */
function logga(string $msg, string $livello = 'INFO'): void
{
    $CONFIG = $GLOBALS['CONFIG'] ?? null;
    if ($livello === 'DETT' && ($CONFIG['log_level'] ?? 'normale') !== 'dettagliato') {
        return;   // dettaglio disattivato: non intaso il log
    }
    $req = $GLOBALS['REQ'] ?? '------';
    $t0  = $GLOBALS['T0'] ?? microtime(true);
    scriviLog(sprintf('[%s] %6.0fms %-4s %s', $req, (microtime(true) - $t0) * 1000,
                      $livello, $msg));
}


// ---------------------------------------------------------------------------
// RISPOSTE COERENTI
// ---------------------------------------------------------------------------
// TUTTE le risposte hanno la stessa forma, cosi' l'app sa sempre cosa aspettarsi.
function risposta(bool $ok, string $code, string $message, int $http,
                  ?string $uploadId = null, $details = null): void
{
    // Tutte le risposte passano di qui: e' il posto giusto per chiudere la
    // traccia nel log, cosi' nessuna richiesta resta senza esito registrato.
    @logga(sprintf('<<< FINE  HTTP %d  %s  |  %s', $http, $code, $message),
           $ok ? 'INFO' : 'ERR');

    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok'        => $ok,
        'code'      => $code,
        'message'   => $message,
        'upload_id' => $uploadId,
        'timestamp' => date('c'),
        'details'   => $details ?? new stdClass(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// Anche un errore imprevisto di PHP esce in JSON, mai come pagina HTML.
set_exception_handler(function ($e) {
    global $CONFIG;
    // Qui NON deve poter fallire nulla, altrimenti la risposta esce vuota.
    @scriviLog('ERRORE imprevisto: ' . $e->getMessage()
               . ' [' . $e->getFile() . ':' . $e->getLine() . ']');
    $dettagli = null;
    // Con "debug": true nel config, l'errore vero finisce nella risposta.
    // Utile in fase di installazione. Toglilo in produzione.
    if (!empty($CONFIG['debug'])) {
        $dettagli = [
            'errore' => $e->getMessage(),
            'file'   => $e->getFile(),
            'riga'   => $e->getLine(),
        ];
    }
    risposta(false, 'ERRORE_INTERNO', 'Errore interno del server.', 500, null, $dettagli);
});

set_error_handler(function ($no, $str, $file, $line) {
    // 1) Rispetta l'operatore @ e il livello di error_reporting.
    //    Senza questo controllo, un @file_put_contents fallito diventerebbe
    //    un'eccezione e farebbe morire tutto il servizio.
    if (!(error_reporting() & $no)) {
        return true;   // gestito: non fare nulla
    }
    // 2) Gli avvisi non sono errori fatali: li annoto e proseguo.
    if ($no & (E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE)) {
        @scriviLog(sprintf('AVVISO PHP: %s [%s:%d]', $str, $file, $line));
        return true;
    }
    // 3) Solo i problemi seri diventano eccezioni.
    throw new ErrorException($str, 0, $no, $file, $line);
});


// ---------------------------------------------------------------------------
// CONFIGURAZIONE E LOG
// ---------------------------------------------------------------------------
function caricaConfig(): array
{
    if (!is_readable(CONFIG_FILE)) {
        risposta(false, 'CONFIG_MANCANTE',
                 'Configurazione del server non leggibile.', 500);
    }
    $cfg = json_decode((string)file_get_contents(CONFIG_FILE), true);
    if (!is_array($cfg)) {
        risposta(false, 'CONFIG_NON_VALIDA',
                 'Configurazione del server non valida (JSON errato).', 500);
    }
    return $cfg;
}

$CONFIG = caricaConfig();

// Scrive nel log senza MAI poter far fallire il servizio: se il percorso
// configurato non e' scrivibile ripiega sulla cartella temporanea, e se
// nemmeno quella funziona rinuncia in silenzio.
function scriviLog(string $msg): void
{
    $CONFIG = $GLOBALS['CONFIG'] ?? null;   // puo' non essere ancora caricata
    static $file = null;

    if ($file === null) {
        $candidati = [];
        if (!empty($CONFIG['log_file'])) $candidati[] = (string)$CONFIG['log_file'];
        $candidati[] = __DIR__ . '/server.log';
        $candidati[] = rtrim(sys_get_temp_dir(), '/') . '/uploader.log';

        $file = false;
        foreach ($candidati as $c) {
            $dir = dirname($c);
            if (!is_dir($dir)) continue;                 // la cartella deve esistere
            if (!is_writable(is_file($c) ? $c : $dir)) continue;
            $file = $c;
            break;
        }
    }

    if ($file === false) return;   // nessun posto dove scrivere: pazienza

    try {
        @file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL,
                           FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // il log non deve mai essere la causa di un errore
    }
}

// Dove sta scrivendo davvero il log (lo mostra /health)
function logAttivo(): string
{
    $CONFIG = $GLOBALS['CONFIG'] ?? null;
    $candidati = [];
    if (!empty($CONFIG['log_file'])) $candidati[] = (string)$CONFIG['log_file'];
    $candidati[] = __DIR__ . '/server.log';
    $candidati[] = rtrim(sys_get_temp_dir(), '/') . '/uploader.log';
    foreach ($candidati as $c) {
        $dir = dirname($c);
        if (is_dir($dir) && is_writable(is_file($c) ? $c : $dir)) {
            $nota = ($c === ($CONFIG['log_file'] ?? null)) ? '' : '  <-- RIPIEGO: quello configurato non e\' scrivibile!';
            return $c . $nota;
        }
    }
    return 'NESSUNO: il log non viene scritto da nessuna parte.';
}


// ---------------------------------------------------------------------------
// SICUREZZA: whitelist dei token
// ---------------------------------------------------------------------------
function leggiToken(): ?string
{
    // Alcuni server non passano l'header Authorization a PHP: proviamo
    // tutte le strade possibili, incluso l'header alternativo X-API-Key.
    $auth = null;
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k])) { $auth = $_SERVER[$k]; break; }
    }
    if ($auth === null && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $nome => $valore) {
            if (strcasecmp($nome, 'Authorization') === 0) { $auth = $valore; break; }
        }
    }
    if ($auth !== null) {
        if (stripos(trim($auth), 'bearer ') === 0) {
            return trim(substr(trim($auth), 7));
        }
        return trim($auth);
    }
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        return trim($_SERVER['HTTP_X_API_KEY']);
    }
    return null;
}

function trovaClient(?string $token): ?array
{
    global $CONFIG;
    if ($token === null || $token === '') return null;
    foreach ($CONFIG['clients'] ?? [] as $client) {
        if (($client['enabled'] ?? true) !== true) continue;
        // hash_equals confronta senza rivelare informazioni dal tempo di risposta
        if (hash_equals((string)($client['token'] ?? ''), $token)) {
            return $client;
        }
    }
    return null;
}


// ---------------------------------------------------------------------------
// UTILITA'
// ---------------------------------------------------------------------------
function nomeSicuro(string $nome): string
{
    // Via percorsi e caratteri strani: un file non deve poter uscire
    // dalla cartella prevista (es. "../../etc/passwd").
    $nome = basename($nome);
    $nome = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $nome) ?? 'file';
    $nome = trim($nome);
    if ($nome === '' || $nome === '.' || $nome === '..') $nome = 'file';
    return substr($nome, 0, 150);
}

function estensione(string $nome): string
{
    return strtolower(pathinfo($nome, PATHINFO_EXTENSION));
}

function costruisciDestinazione(array $profilo, array $client, string $uploadId, string $sessionId = ''): string
{
    $sost = [
        '{client_id}' => preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($client['id'] ?? 'sconosciuto')),
        '{anno}'      => date('Y'),
        '{mese}'      => date('m'),
        '{giorno}'    => date('d'),
        '{data}'      => date('Y-m-d'),
        '{upload_id}' => $uploadId,
        '{session_id}' => $sessionId,
    ];
    $dir = strtr((string)$profilo['destination'], $sost);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        risposta(false, 'DESTINAZIONE_NON_VALIDA',
                 'Impossibile creare la cartella di destinazione.', 500, $uploadId);
    }
    if (!is_writable($dir)) {
        risposta(false, 'DESTINAZIONE_NON_SCRIVIBILE',
                 'La cartella di destinazione non e\' scrivibile.', 500, $uploadId);
    }
    return $dir;
}

/**
 * PHP presenta i file caricati in due forme diverse a seconda che il campo
 * sia singolo ("gpkg") o multiplo ("immagini[]"). Qui le uniformiamo:
 * ogni campo diventa una lista di file, sempre.
 */
function normalizzaFiles(): array
{
    $risultato = [];
    foreach ($_FILES as $campo => $dati) {
        // Nel caso multiplo, 'name' e' un array
        if (is_array($dati['name'])) {
            $n = count($dati['name']);
            for ($i = 0; $i < $n; $i++) {
                $risultato[$campo][] = [
                    'name'     => (string)$dati['name'][$i],
                    'tmp_name' => (string)$dati['tmp_name'][$i],
                    'size'     => (int)$dati['size'][$i],
                    'error'    => (int)$dati['error'][$i],
                ];
            }
        } else {
            $risultato[$campo][] = [
                'name'     => (string)$dati['name'],
                'tmp_name' => (string)$dati['tmp_name'],
                'size'     => (int)$dati['size'],
                'error'    => (int)$dati['error'],
            ];
        }
    }
    return $risultato;
}

function descriviErroreUpload(int $code): ?string
{
    switch ($code) {
        case UPLOAD_ERR_OK:         return null;
        case UPLOAD_ERR_INI_SIZE:   return "Il file supera il limite del server (upload_max_filesize).";
        case UPLOAD_ERR_FORM_SIZE:  return "Il file supera il limite indicato dal modulo.";
        case UPLOAD_ERR_PARTIAL:    return "Il file e' arrivato solo in parte (connessione interrotta?).";
        case UPLOAD_ERR_NO_FILE:    return "Nessun file inviato.";
        case UPLOAD_ERR_NO_TMP_DIR: return "Manca la cartella temporanea sul server.";
        case UPLOAD_ERR_CANT_WRITE: return "Il server non riesce a scrivere su disco.";
        case UPLOAD_ERR_EXTENSION:  return "Upload bloccato da un'estensione PHP.";
        default:                    return "Errore di upload sconosciuto (codice $code).";
    }
}

function inByte(string $valore): int
{
    // Converte "8M", "512K", "1G" in byte
    $valore = trim($valore);
    if ($valore === '') return 0;
    $ultimo = strtolower($valore[strlen($valore) - 1]);
    $num = (int)$valore;
    switch ($ultimo) {
        case 'g': $num *= 1024; // continua
        case 'm': $num *= 1024; // continua
        case 'k': $num *= 1024;
    }
    return $num;
}


// ---------------------------------------------------------------------------
// ROUTING
// ---------------------------------------------------------------------------
$azione = $_GET['a'] ?? null;
if ($azione === null) {
    // Supporto anche gli indirizzi puliti tipo .../upload (con .htaccess)
    $path = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
    if ($path === '') {
        $path = trim(basename(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''), '/');
    }
    $azione = $path !== '' ? $path : 'health';
}

// Prima riga della traccia: chi chiama, cosa chiede, quanto pesa
logga(sprintf('>>> INIZIO %s "%s" da %s  (%s byte)  agent=%s',
    $_SERVER['REQUEST_METHOD'] ?? '?',
    $azione,
    $_SERVER['REMOTE_ADDR'] ?? '?',
    $_SERVER['CONTENT_LENGTH'] ?? '0',
    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 60)
));

switch ($azione) {
    case 'health':  azioneHealth();  break;
    case 'profilo': azioneProfilo(); break;
    case 'upload':  azioneUpload();  break;
    case 'chiudi':  azioneChiudi(); break;
    default:
        risposta(false, 'AZIONE_SCONOSCIUTA',
                 "Azione '$azione' non riconosciuta.", 404);
}


// ---------------------------------------------------------------------------
// AZIONE: health  l'app lo usa al passo 3
// ---------------------------------------------------------------------------
function azioneHealth(): void
{
    global $CONFIG;
    risposta(true, 'OK', 'Servizio attivo.', 200, null, [
        'profili_disponibili' => array_keys($CONFIG['profiles'] ?? []),
        'limiti_php' => [
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size'       => ini_get('post_max_size'),
            'max_file_uploads'    => ini_get('max_file_uploads'),
            'max_execution_time'  => ini_get('max_execution_time'),
            'memory_limit'        => ini_get('memory_limit'),
        ],
        // Serve a sapere QUALE configurazione modificare: quella del web,
        // non quella della riga di comando (che e' un'altra cosa).
        // Verifiche pratiche: log e cartelle di destinazione scrivibili?
        'diagnostica' => [
            'log_scritto_in'   => logAttivo(),
            'destinazioni'     => diagnosticaDestinazioni(),
            'config_in_uso'    => CONFIG_FILE,
            'php_cli_per_sync' => diagnosticaPhpCli(),
            'avvisi_sicurezza' => avvisiSicurezza(),
        ],
        'dove_configurare' => [
            'modalita_php'   => php_sapi_name(),
            'php_ini_in_uso' => php_ini_loaded_file() ?: 'nessuno',
            'user_ini_letto' => ini_get('user_ini.filename') ?: '(disattivato)',
            'utente_php'     => function_exists('posix_getpwuid')
                                ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?',
        ],
    ]);
}


// ---------------------------------------------------------------------------
// DIAGNOSTICA: c'e' un PHP a riga di comando per lanciare sync.php?
// (PHP_BINARY qui NON serve: dentro Apache e' vuoto)
// ---------------------------------------------------------------------------
function diagnosticaPhpCli(): string
{
    $CONFIG = $GLOBALS['CONFIG'] ?? [];
    $indicato = (string)($CONFIG['post_close']['params']['php_cli'] ?? '');
    $v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $candidati = array_filter([$indicato, "/usr/bin/php$v", "/usr/local/bin/php$v",
                               "/usr/bin/php", "/usr/local/bin/php"]);
    foreach ($candidati as $c) {
        if (@is_file($c) && @is_executable($c)) {
            return $c . ($c === $indicato ? '  (indicato nel config)' : '  (trovato da solo)');
        }
    }
    return 'NON TROVATO: indicalo in post_close.params.php_cli';
}


// ---------------------------------------------------------------------------
// DIAGNOSTICA: qualcosa di riservato e' finito dentro la cartella pubblica?
// ---------------------------------------------------------------------------
function avvisiSicurezza(): array
{
    $CONFIG = $GLOBALS['CONFIG'] ?? [];
    $avvisi = [];
    $radiceWeb = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: null;

    $dentroWeb = function (?string $percorso) use ($radiceWeb): bool {
        if (!$radiceWeb || !$percorso) return false;
        $p = realpath($percorso) ?: realpath(dirname($percorso));
        return $p && strpos($p, $radiceWeb) === 0;
    };

    if ($dentroWeb(CONFIG_FILE)) {
        $avvisi[] = "ATTENZIONE: la configurazione (che contiene il TOKEN) sta dentro "
                  . "la cartella pubblica. Verifica dal browser che non sia scaricabile: "
                  . "deve rispondere 403 o 404.";
    }
    if (!empty($CONFIG['log_file']) && $dentroWeb((string)$CONFIG['log_file'])) {
        $avvisi[] = "Il log sta dentro la cartella pubblica: verifica che non sia scaricabile.";
    }
    foreach ($CONFIG['profiles'] ?? [] as $nome => $p) {
        $base = (string)($p['destination'] ?? '');
        if (($pos = strpos($base, '{')) !== false) $base = rtrim(substr($base, 0, $pos), '/');
        if ($dentroWeb($base)) {
            $avvisi[] = "I file ricevuti dal profilo '$nome' finiscono dentro la cartella "
                      . "pubblica: verifica che non siano scaricabili da chiunque.";
        }
    }
    return $avvisi ?: ['nessun avviso'];
}


// ---------------------------------------------------------------------------
// DIAGNOSTICA: le cartelle di destinazione sono utilizzabili?
// Meglio scoprirlo da /health che al primo invio sul campo.
// ---------------------------------------------------------------------------
function diagnosticaDestinazioni(): array
{
    global $CONFIG;
    $esito = [];
    foreach ($CONFIG['profiles'] ?? [] as $nome => $p) {
        $modello = (string)($p['destination'] ?? '');
        // Prendo la parte fissa del percorso, prima dei segnaposto {&}
        $base = $modello;
        if (($pos = strpos($modello, '{')) !== false) {
            $base = rtrim(substr($modello, 0, $pos), '/');
        }
        // Risalgo al primo antenato che esiste davvero
        $test = $base;
        while ($test !== '' && $test !== '/' && $test !== '.' && !is_dir($test)) {
            $test = dirname($test);
        }
        $utente = function_exists('posix_getpwuid')
                  ? (posix_getpwuid(posix_geteuid())['name'] ?? 'php') : 'php';
        if (!is_dir($test)) {
            $esito[$nome] = 'PERCORSO INESISTENTE: ' . $base;
        } elseif (!is_writable($test)) {
            $esito[$nome] = 'NON SCRIVIBILE da "' . $utente . '": ' . $test;
        } else {
            $esito[$nome] = 'OK (scrivibile: ' . $test . ')';
        }
    }
    return $esito;
}


// ---------------------------------------------------------------------------
// AZIONE: profilo  dice all'app cosa deve mandare
// ---------------------------------------------------------------------------
function azioneProfilo(): void
{
    global $CONFIG;
    $client = trovaClient(leggiToken());
    if ($client === null) {
        risposta(false, 'TOKEN_NON_VALIDO', 'Token non autorizzato.', 401);
    }
    $profilo = $CONFIG['profiles'][$client['profile']] ?? null;
    if ($profilo === null) {
        risposta(false, 'PROFILO_INESISTENTE',
                 'Il profilo associato al token non esiste.', 500);
    }
    risposta(true, 'OK', 'Profilo trovato.', 200, null, [
        'client'          => $client['id'] ?? null,
        'profilo'         => $client['profile'],
        'file_attesi'     => $profilo['expected_files'] ?? [],
        'campi_richiesti' => $profilo['required_fields'] ?? [],
    ]);
}


// ---------------------------------------------------------------------------
// AZIONE: upload  il cuore del servizio
// ---------------------------------------------------------------------------
function azioneUpload(): void
{
    global $CONFIG;

    $uploadId = bin2hex(random_bytes(6));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        risposta(false, 'METODO_NON_AMMESSO',
                 'Questo indirizzo accetta solo richieste POST.', 405, $uploadId);
    }

    // --- 1. TOKEN ---------------------------------------------------------
    $token = leggiToken();
    if ($token === null) {
        logga("upload $uploadId: token assente");
        risposta(false, 'TOKEN_MANCANTE',
                 'Token di autenticazione assente.', 401, $uploadId);
    }
    $client = trovaClient($token);
    if ($client === null) {
        logga("upload $uploadId: TOKEN NON VALIDO", 'ERR');
        risposta(false, 'TOKEN_NON_VALIDO', 'Token non autorizzato.', 401, $uploadId);
    }
    logga("upload $uploadId: token OK, client '{$client['id']}'");

    // --- 2. PROFILO -------------------------------------------------------
    $nomeProfilo = (string)($client['profile'] ?? '');
    $profilo = $CONFIG['profiles'][$nomeProfilo] ?? null;
    if ($profilo === null) {
        logga("upload $uploadId: profilo '$nomeProfilo' inesistente", 'ERR');
        risposta(false, 'PROFILO_INESISTENTE',
                 'Configurazione del server incompleta per questo token.',
                 500, $uploadId);
    }

    // --- 3. CONTROLLO "RICHIESTA TROPPO GRANDE" ---------------------------
    // Se si supera post_max_size, PHP svuota $_POST e $_FILES senza dire nulla:
    // senza questo controllo l'app riceverebbe un messaggio fuorviante.
    $lunghezza = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $maxPost = inByte((string)ini_get('post_max_size'));
    if (empty($_FILES) && empty($_POST) && $maxPost > 0 && $lunghezza > $maxPost) {
        logga("upload $uploadId: richiesta $lunghezza byte oltre post_max_size $maxPost", 'ERR');
        risposta(false, 'RICHIESTA_TROPPO_GRANDE',
                 'I dati inviati superano il limite del server (post_max_size = '
                 . ini_get('post_max_size') . ').', 413, $uploadId, [
                     'inviato_byte'   => $lunghezza,
                     'massimo_byte'   => $maxPost,
                 ]);
    }

    $files = normalizzaFiles();
    $campiTesto = [];
    foreach ($_POST as $k => $v) {
        if (is_scalar($v)) $campiTesto[(string)$k] = (string)$v;
    }

    // --- 3-bis. CONTROLLO ANTI-PERDITA SILENZIOSA -------------------------
    // L'app dichiara quanti file sta inviando per ogni campo ("_manifest").
    // PHP, in alcuni casi, scarta file SENZA segnalare nulla:
    //   - se si supera 'max_file_uploads' (di serie: 20 file per richiesta);
    //   - se un campo multiplo non finisce con "[]".
    // Senza questo controllo il server risponderebbe "OK" pur avendo perso
    // dei file. Qui invece ce ne accorgiamo e lo diciamo chiaramente.
    if (isset($campiTesto['_manifest'])) {
        $atteso = json_decode($campiTesto['_manifest'], true);
        unset($campiTesto['_manifest']);   // non e' un dato dell'utente
        if (is_array($atteso)) {
            $discrepanze = [];
            foreach ($atteso as $campo => $quanti) {
                $campo = rtrim((string)$campo, '[]');
                $ricevuti = count($files[$campo] ?? []);
                if ($ricevuti !== (int)$quanti) {
                    $discrepanze[] = [
                        'campo'     => $campo,
                        'dichiarati'=> (int)$quanti,
                        'ricevuti'  => $ricevuti,
                    ];
                }
            }
            if ($discrepanze) {
                logga("upload $uploadId: PERDITA FILE " . json_encode($discrepanze), 'ERR');
                risposta(false, 'FILE_PERSI_IN_TRASMISSIONE',
                    'Il numero di file ricevuti non corrisponde a quelli inviati. '
                    . 'Probabile limite del server superato (max_file_uploads = '
                    . ini_get('max_file_uploads') . ').',
                    400, $uploadId, [
                        'discrepanze'      => $discrepanze,
                        'max_file_uploads' => ini_get('max_file_uploads'),
                    ]);
            }
        }
    }

    // --- 4. CAMPI OBBLIGATORI --------------------------------------------
    $mancanti = [];
    foreach ($profilo['required_fields'] ?? [] as $campo) {
        if (!array_key_exists($campo, $campiTesto) || $campiTesto[$campo] === '') {
            $mancanti[] = $campo;
        }
    }
    if ($mancanti) {
        risposta(false, 'CAMPI_MANCANTI',
                 'Mancano i campi obbligatori: ' . implode(', ', $mancanti),
                 400, $uploadId, ['campi_mancanti' => $mancanti]);
    }

    // --- 5. VALIDAZIONE DEI FILE (tutto PRIMA di salvare) -----------------
    // --- 5. SESSIONE ------------------------------------------------------
    // Tutti i lotti di uno stesso invio condividono il 'session_id': cosi'
    // finiscono nella stessa cartella e il server sa cosa ha gia' ricevuto.
    $sessionId = (string)($campiTesto['session_id'] ?? '');
    unset($campiTesto['session_id']);
    if (strpos((string)$profilo['destination'], '{session_id}') !== false && $sessionId === '') {
        risposta(false, 'SESSIONE_MANCANTE',
                 'Manca il session_id: l\'app deve inviarlo con ogni lotto.',
                 400, $uploadId);
    }
    // Il session_id finisce in un nome di cartella: ammetto solo caratteri sicuri.
    if ($sessionId !== '' && !preg_match('/^[A-Za-z0-9_-]{6,64}$/', $sessionId)) {
        risposta(false, 'SESSIONE_NON_VALIDA',
                 'Il session_id contiene caratteri non ammessi.', 400, $uploadId);
    }

    $destinazione = costruisciDestinazione($profilo, $client, $uploadId, $sessionId);
    $statoSessione = leggiStatoSessione($destinazione);

    // --- 6. VALIDAZIONE DEI FILE (tutto PRIMA di salvare) -----------------
    // NOTA: 'min_count'/'max_count' valgono per l'INTERA sessione, non per il
    // singolo lotto. Qui controlliamo di non sforare il massimo; il minimo
    // viene verificato alla chiusura della sessione.
    $errori = [];
    $daSalvare = [];
    $campiAttesi = [];
    $sottocartelle = [];   // campo -> sottocartella in cui depositare i file

    foreach ($profilo['expected_files'] ?? [] as $atteso) {
        $campo = rtrim((string)$atteso['field'], '[]');
        $campiAttesi[] = $campo;
        if (!empty($atteso['subfolder'])) {
            // Solo un nome di cartella semplice: niente percorsi
            $sottocartelle[$campo] = preg_replace('/[^A-Za-z0-9._-]/', '_',
                                                  (string)$atteso['subfolder']);
        }

        $maxN = (int)($atteso['max_count'] ?? 1);
        $estensioni = array_map('strtolower', $atteso['extensions'] ?? []);
        $maxMb = (float)($atteso['max_size_mb'] ?? 100);

        $arrivati = $files[$campo] ?? [];
        $arrivati = array_values(array_filter($arrivati, fn($f) =>
            !($f['error'] === UPLOAD_ERR_NO_FILE && $f['name'] === '')));
        if (!$arrivati) continue;

        // Quanti ne ho gia' in questa sessione + quanti ne arrivano ora
        $giaInSessione = count($statoSessione['file'][$campo] ?? []);
        if ($giaInSessione + count($arrivati) > $maxN) {
            $errori[] = ['campo' => $campo, 'errore' => sprintf(
                'Troppi file per la sessione: massimo %d, gia\' presenti %d, in arrivo %d.',
                $maxN, $giaInSessione, count($arrivati))];
            continue;
        }

        foreach ($arrivati as $f) {
            $errUpload = descriviErroreUpload($f['error']);
            if ($errUpload !== null) {
                $errori[] = ['campo' => $campo, 'file' => $f['name'], 'errore' => $errUpload];
                continue;
            }
            if (!is_uploaded_file($f['tmp_name'])) {
                $errori[] = ['campo' => $campo, 'file' => $f['name'],
                             'errore' => 'File non valido.'];
                continue;
            }
            $ext = estensione($f['name']);
            if ($estensioni && !in_array($ext, $estensioni, true)) {
                $errori[] = ['campo' => $campo, 'file' => $f['name'],
                    'errore' => sprintf("Estensione '.%s' non ammessa (ammesse: %s).",
                                        $ext, implode(', ', $estensioni))];
                continue;
            }
            if ($f['size'] <= 0) {
                // File vuoto (0 byte): lo saltiamo con un avviso invece di
                // bloccare l'intero lotto. Un file da 0 byte non contiene
                // immagine, quindi non c'e' nulla da salvare.
                logga("upload $uploadId: foto vuota ignorata (0 byte): {$f['name']}", 'ERR');
                continue;
            }
            if ($f['size'] > $maxMb * 1048576) {
                $errori[] = ['campo' => $campo, 'file' => $f['name'],
                    'errore' => sprintf('File troppo grande: %.1f MB (massimo %.1f MB).',
                                        $f['size'] / 1048576, $maxMb)];
                continue;
            }
            $daSalvare[] = ['campo' => $campo, 'file' => $f];
        }
    }

    if (($profilo['allow_extra_files'] ?? false) !== true) {
        foreach (array_keys($files) as $campo) {
            if (!in_array($campo, $campiAttesi, true)) {
                $errori[] = ['campo' => $campo,
                             'errore' => 'Campo file non previsto da questo profilo.'];
            }
        }
    }

    if ($errori) {
        logga("upload $uploadId: validazione fallita " . json_encode($errori, JSON_UNESCAPED_UNICODE), 'ERR');
        risposta(false, 'FILE_NON_VALIDI',
                 'I file inviati non rispettano il profilo atteso.',
                 400, $uploadId, ['errori' => $errori]);
    }

    logga(sprintf('upload %s: validazione superata, %d file da salvare in %s',
                  $uploadId, count($daSalvare), $destinazione));

    // --- 7. SALVATAGGIO (saltando cio' che c'e' gia') ---------------------
    $salvati = [];
    $giaPresenti = [];
    $scritti = [];

    try {
        foreach ($daSalvare as $elemento) {
            $f = $elemento['file'];
            $campo = $elemento['campo'];
            $nome = nomeSicuro($f['name']);
            $hash = hash_file('sha256', $f['tmp_name']);

            // GIA' RICEVUTO? Se in questa sessione c'e' gia' un file con lo
            // stesso nome E lo stesso contenuto, non lo riscrivo. E' cosi' che
            // un tentativo ripetuto non crea duplicati.
            $esistente = $statoSessione['file'][$campo][$nome] ?? null;
            if ($esistente !== null && ($esistente['sha256'] ?? '') === $hash) {
                $giaPresenti[] = ['campo' => $campo, 'nome_file' => $nome];
                logga("  gia' presente, salto: $campo/$nome", 'DETT');
                continue;
            }

            // Ogni campo puo' avere la sua sottocartella (es. le immagini
            // in "DCIM"): serve a tenere separati i file, cosi' chi elabora
            // dopo trova solo cio' che gli interessa.
            $dirCampo = $destinazione;
            if (isset($sottocartelle[$campo])) {
                $dirCampo .= DIRECTORY_SEPARATOR . $sottocartelle[$campo];
                if (!is_dir($dirCampo) && !@mkdir($dirCampo, 0775, true) && !is_dir($dirCampo)) {
                    throw new RuntimeException("Impossibile creare la sottocartella '{$sottocartelle[$campo]}'.");
                }
            }

            $finale = $dirCampo . DIRECTORY_SEPARATOR . $nome;
            // Stesso nome ma contenuto diverso: non sovrascrivo, rinomino.
            if (file_exists($finale) && ($profilo['overwrite'] ?? false) !== true) {
                $base = pathinfo($nome, PATHINFO_FILENAME);
                $est  = pathinfo($nome, PATHINFO_EXTENSION);
                $nome = $base . '_' . $uploadId . ($est !== '' ? '.' . $est : '');
                $finale = $dirCampo . DIRECTORY_SEPARATOR . $nome;
            }

            if (!@move_uploaded_file($f['tmp_name'], $finale)) {
                throw new RuntimeException("Impossibile salvare il file '$nome'.");
            }
            $scritti[] = $finale;
            logga(sprintf('  salvato %s/%s (%.1f KB)', $campo, $nome, $f['size'] / 1024), 'DETT');

            $salvati[] = [
                'campo'           => $campo,
                'nome_file'       => $nome,
                'dimensione_byte' => $f['size'],
                'sha256'          => $hash,
            ];
        }
    } catch (Throwable $e) {
        foreach ($scritti as $p) @unlink($p);
        logga("upload $uploadId: errore salvataggio - " . $e->getMessage(), 'ERR');
        risposta(false, 'ERRORE_SALVATAGGIO',
                 'Errore durante il salvataggio dei file.', 500, $uploadId);
    }

    // --- 8. AGGIORNO LO STATO DELLA SESSIONE ------------------------------
    foreach ($salvati as $s) {
        $statoSessione['file'][$s['campo']][$s['nome_file']] = [
            'sha256'          => $s['sha256'],
            'dimensione_byte' => $s['dimensione_byte'],
            'ricevuto_il'     => date('c'),
        ];
    }
    $statoSessione['client']  = $client['id'] ?? null;
    $statoSessione['profilo'] = $nomeProfilo;
    $statoSessione['campi']   = $campiTesto;
    $statoSessione['aggiornato_il'] = date('c');
    scriviStatoSessione($destinazione, $statoSessione);

    $totali = [];
    foreach ($statoSessione['file'] as $campo => $lista) {
        $totali[$campo] = count($lista);
    }

    logga(sprintf('upload %s: sessione %s -> %d nuovi, %d gia\' presenti, totali %s',
                  $uploadId, $sessionId, count($salvati), count($giaPresenti),
                  json_encode($totali)));

    // --- 9. RISPOSTA ------------------------------------------------------
    risposta(true, 'OK',
             sprintf('Lotto ricevuto: %d nuovi file, %d gia\' presenti.',
                     count($salvati), count($giaPresenti)),
             200, $uploadId, [
                 'session_id'      => $sessionId,
                 'cartella'        => $destinazione,
                 'file_salvati'    => $salvati,
                 'gia_presenti'    => $giaPresenti,
                 'totali_sessione' => $totali,
                 'campi_ricevuti'  => $campiTesto,
             ]);
}


// ---------------------------------------------------------------------------
// STATO DELLA SESSIONE
// ---------------------------------------------------------------------------
// Un file nascosto nella cartella tiene traccia di cosa e' gia' arrivato:
// serve a riconoscere i duplicati e a contare i totali.
function percorsoStato(string $destinazione): string
{
    return $destinazione . DIRECTORY_SEPARATOR . '_stato_sessione.json';
}

function leggiStatoSessione(string $destinazione): array
{
    $p = percorsoStato($destinazione);
    if (!is_readable($p)) return ['file' => []];
    $d = json_decode((string)file_get_contents($p), true);
    return is_array($d) ? $d + ['file' => []] : ['file' => []];
}

function scriviStatoSessione(string $destinazione, array $stato): void
{
    @file_put_contents(percorsoStato($destinazione),
        json_encode($stato, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX);
}


// ---------------------------------------------------------------------------
// AZIONE: chiudi  l'app la chiama alla fine
// ---------------------------------------------------------------------------
// Qui si verifica che la sessione contenga TUTTO il necessario (i minimi del
// profilo). E' il vero "collaudo finale" dell'invio.
function azioneChiudi(): void
{
    global $CONFIG;

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        risposta(false, 'METODO_NON_AMMESSO', 'Serve una richiesta POST.', 405);
    }

    $client = trovaClient(leggiToken());
    if ($client === null) {
        risposta(false, 'TOKEN_NON_VALIDO', 'Token non autorizzato.', 401);
    }
    $nomeProfilo = (string)($client['profile'] ?? '');
    $profilo = $CONFIG['profiles'][$nomeProfilo] ?? null;
    if ($profilo === null) {
        risposta(false, 'PROFILO_INESISTENTE', 'Profilo inesistente.', 500);
    }

    $sessionId = (string)($_POST['session_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/', $sessionId)) {
        risposta(false, 'SESSIONE_NON_VALIDA', 'session_id assente o non valido.', 400);
    }

    $destinazione = costruisciDestinazione($profilo, $client, '', $sessionId);
    $stato = leggiStatoSessione($destinazione);

    // Controllo i minimi previsti dal profilo
    $mancanti = [];
    $totali = [];
    foreach ($profilo['expected_files'] ?? [] as $atteso) {
        $campo = rtrim((string)$atteso['field'], '[]');
        $minN = (int)($atteso['min_count'] ?? (($atteso['required'] ?? true) ? 1 : 0));
        $quanti = count($stato['file'][$campo] ?? []);
        $totali[$campo] = $quanti;
        if ($quanti < $minN) {
            $mancanti[] = ['campo' => $campo, 'attesi_almeno' => $minN, 'presenti' => $quanti];
        }
    }

    if ($mancanti) {
        logga("chiusura $sessionId: INCOMPLETA " . json_encode($mancanti), 'ERR');
        risposta(false, 'SESSIONE_INCOMPLETA',
                 'La sessione non contiene tutti i file previsti.',
                 400, null, ['mancanti' => $mancanti, 'totali' => $totali]);
    }

    // Ricevuta finale
    $totale = array_sum($totali);
    $stato['chiusa_il'] = date('c');
    $stato['completa'] = true;
    scriviStatoSessione($destinazione, $stato);

    $ricevuta = [
        'session_id'  => $sessionId,
        'client'      => $client['id'] ?? null,
        'profilo'     => $nomeProfilo,
        'chiusa_il'   => date('c'),
        'campi'       => $stato['campi'] ?? [],
        'totali'      => $totali,
        'file'        => $stato['file'] ?? [],
    ];
    @file_put_contents($destinazione . DIRECTORY_SEPARATOR . "ricevuta_$sessionId.json",
        json_encode($ricevuta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    logga("chiusura $sessionId: sessione completa, $totale file, ricevuta scritta");

    // --- ELABORAZIONE FINALE (script esterno) -----------------------------
    // Qui si aggancia il TUO script (es. importaCensimento): parte solo ora,
    // a sessione completa e verificata. Se fallisce, l'app lo sapr�.
    $import = null;
    if (!empty($CONFIG['post_close']['enabled'])) {
        if (!empty($stato['import_eseguito'])) {
            // Gia' importata: un nuovo tentativo non deve duplicare i dati.
            $import = ['ok' => true, 'saltato' => true,
                       'gia_eseguito_il' => $stato['import_eseguito']];
            logga("chiusura $sessionId: import gia' eseguito il {$stato['import_eseguito']}, salto");
        } else {
            $import = eseguiPostChiusura($CONFIG['post_close'], $destinazione,
                                         $sessionId, $client, $profilo, $stato);
            if (!empty($import['ok'])) {
                $stato['import_eseguito'] = date('c');
                $stato['import_risultato'] = $import['risultato'] ?? null;
                scriviStatoSessione($destinazione, $stato);
            } else {
                logga("chiusura $sessionId: IMPORT FALLITO - " . ($import['errore'] ?? '?'), 'ERR');
                risposta(false, 'IMPORT_FALLITO',
                         'File ricevuti correttamente, ma l\'elaborazione finale '
                         . 'e\' fallita: ' . ($import['errore'] ?? 'errore sconosciuto'),
                         500, null, [
                             'session_id' => $sessionId,
                             'cartella'   => $destinazione,
                             'errore'     => $import['errore'] ?? null,
                             'output'     => $import['output'] ?? null,
                         ]);
            }
        }
    }

    // Restituisco anche l'elenco completo con le impronte SHA256: l'app le
    // confronta con i file locali e cancella SOLO quelli davvero al sicuro.
    $elenco = [];
    foreach (($stato['file'] ?? []) as $campo => $lista) {
        foreach ($lista as $nome => $info) {
            $elenco[$nome] = $info['sha256'] ?? '';
        }
    }
    risposta(true, 'OK', "Sessione completa: $totale file ricevuti.", 200, null, [
        'session_id'  => $sessionId,
        'cartella'    => $destinazione,
        'totale_file' => $totale,
        'totali'      => $totali,
        'impronte'    => $elenco,
        'import'      => $import,
    ]);
}


// ---------------------------------------------------------------------------
// AGGANCIO PER SCRIPT ESTERNI
// ---------------------------------------------------------------------------
/**
 * Carica un file PHP e chiama una funzione al suo interno, passandole i
 * percorsi della sessione appena chiusa.
 *
 * Due accortezze importanti:
 *  - l'output dello script (echo, passthru...) viene CATTURATO: altrimenti
 *    finirebbe dentro la risposta JSON e la romperebbe;
 *  - il CSV ricevuto non viene dato in pasto direttamente: se ne passa una
 *    COPIA, cosi' l'originale caricato dall'app resta intatto anche se lo
 *    script lo riscrive.
 */
function eseguiPostChiusura(array $conf, string $destinazione, string $sessionId,
                            array $client, array $profilo, array $stato): array
{
    $script = (string)($conf['script'] ?? '');
    $funzione = (string)($conf['function'] ?? '');

    if (!is_file($script)) {
        return ['ok' => false, 'errore' => "Script non trovato: $script"];
    }

    $p = $conf['params'] ?? [];

    // --- Dove sta il CSV ricevuto? ---
    $campoCsv = (string)($p['csv_field'] ?? 'dati');
    $nomiCsv = array_keys($stato['file'][$campoCsv] ?? []);
    if (!$nomiCsv) {
        return ['ok' => false, 'errore' => "Nessun file nel campo '$campoCsv'."];
    }
    $subCsv = sottocartellaDi($profilo, $campoCsv);
    $csvRicevuto = $destinazione . ($subCsv ? DIRECTORY_SEPARATOR . $subCsv : '')
                   . DIRECTORY_SEPARATOR . $nomiCsv[0];

    // --- Dove stanno le immagini? ---
    $campoImg = (string)($p['images_field'] ?? 'immagini');
    $subImg = sottocartellaDi($profilo, $campoImg);
    $cartellaImg = $destinazione . ($subImg ? DIRECTORY_SEPARATOR . $subImg : '');

    // --- Sostituzione dei segnaposto nei parametri ---
    $sost = [
        '{session_id}' => $sessionId,
        '{client_id}'  => (string)($client['id'] ?? ''),
        '{anno}'  => date('Y'), '{mese}' => date('m'),
        '{giorno}' => date('d'), '{data}' => date('Y-m-d'),
        '{cartella}' => $destinazione,
    ];
    $risolvi = fn($v) => is_string($v) ? strtr($v, $sost) : $v;

    $zipPath     = $risolvi((string)($p['zip_path'] ?? ($destinazione . '/immagini.zip')));
    $service     = $risolvi((string)($p['service'] ?? ''));
    $mediaFolder = $risolvi((string)($p['media_folder'] ?? $sessionId));
    $campiFoto   = $p['campi_foto'] ?? null;

    // La cartella dello ZIP deve esistere
    $dirZip = dirname($zipPath);
    if (!is_dir($dirZip) && !@mkdir($dirZip, 0775, true) && !is_dir($dirZip)) {
        return ['ok' => false, 'errore' => "Cartella ZIP non creabile: $dirZip"];
    }

    // Lavoro su una COPIA del CSV: l'originale ricevuto resta com'e'.
    $dirLavoro = $destinazione . DIRECTORY_SEPARATOR . '_elaborazione';
    if (!is_dir($dirLavoro) && !@mkdir($dirLavoro, 0775, true) && !is_dir($dirLavoro)) {
        return ['ok' => false, 'errore' => "Cartella di lavoro non creabile: $dirLavoro"];
    }
    $csvLavoro = $dirLavoro . DIRECTORY_SEPARATOR . basename($csvRicevuto);
    if (!@copy($csvRicevuto, $csvLavoro)) {
        return ['ok' => false, 'errore' => "Impossibile copiare il CSV in $csvLavoro"];
    }

    require_once $script;
    if (!function_exists($funzione)) {
        return ['ok' => false,
                'errore' => "La funzione '$funzione' non esiste in $script."];
    }

    // I primi cinque valori vanno in ordine; gli altri li passiamo per NOME,
    // cosi' si possono indicare in qualsiasi combinazione senza sbagliare
    // l'ordine (funziona da PHP 8).
    $argomenti = [$csvLavoro, $cartellaImg, $zipPath, $service, $mediaFolder];
    if (is_array($campiFoto) && $campiFoto)  $argomenti['campiFoto'] = $campiFoto;
    if (!empty($p['delimiter']))             $argomenti['delimiter'] = (string)$p['delimiter'];
    if (!empty($p['php_cli']))               $argomenti['phpCli']    = (string)$p['php_cli'];
    if (!empty($p['sudo_user']))             $argomenti['sudoUser']   = (string)$p['sudo_user'];
    if (!empty($p['media_base']))            $argomenti['mediaBase']  = (string)$p['media_base'];
    if (!empty($p['sync_script']))           $argomenti['syncScript'] = (string)$p['sync_script'];

    logga("--- ELABORAZIONE FINALE ---");
    logga("  funzione : $funzione  (da " . basename($script) . ")");
    logga("  csv      : $csvLavoro");
    logga("  immagini : $cartellaImg");
    logga("  zip      : $zipPath");
    logga("  service  : $service | media_folder: $mediaFolder");
    logga("  separatore CSV: '" . ($p['delimiter'] ?? '(predefinito della funzione)') . "'");

    // Catturo tutto cio' che lo script stampa: non deve sporcare il JSON.
    ob_start();
    try {
        $inizio = microtime(true);
        $risultato = call_user_func_array($funzione, $argomenti);
        $output = ob_get_clean();
        foreach (array_filter(explode("\n", (string)$output)) as $riga) {
            logga('  | ' . rtrim($riga));   // cio' che lo script ha stampato
        }
        logga(sprintf('--- ELABORAZIONE COMPLETATA in %.1fs -> %s',
                      microtime(true) - $inizio,
                      json_encode($risultato, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        return ['ok' => true, 'risultato' => $risultato,
                'output' => trim((string)$output), 'csv_elaborato' => $csvLavoro];
    } catch (Throwable $e) {
        $output = ob_get_clean();
        foreach (array_filter(explode("\n", (string)$output)) as $riga) {
            logga('  | ' . rtrim($riga), 'ERR');
        }
        logga('--- ELABORAZIONE FALLITA: ' . $e->getMessage(), 'ERR');
        return ['ok' => false, 'errore' => $e->getMessage(),
                'output' => trim((string)$output)];
    }
}

/** Sottocartella prevista dal profilo per un certo campo (o stringa vuota). */
function sottocartellaDi(array $profilo, string $campo): string
{
    foreach ($profilo['expected_files'] ?? [] as $f) {
        if (rtrim((string)$f['field'], '[]') === $campo && !empty($f['subfolder'])) {
            return preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$f['subfolder']);
        }
    }
    return '';
}
