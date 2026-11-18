<?php
/**
 * importa_censimento.php
 * ----------------------
 * Elaborazione finale, richiamata dal servizio di upload quando la sessione
 * e' completa (aggancio "post_close" in config.json).
 *
 * Riceve:
 *   $csvPath    - COPIA del CSV ricevuto (l'originale non viene toccato)
 *   $imagesPath - cartella con le sole immagini della sessione
 *   $zipPath    - dove creare lo ZIP
 *   $service    - servizio per lo script di sincronizzazione
 *   $mediaFolder- sottocartella dentro media/img
 *   $campiFoto  - colonne che contengono i percorsi delle foto
 *   $delimiter  - separatore del CSV. DEVE combaciare con quello usato
 *                 dall'app (voce "delimiter" nel suo config) e con quello
 *                 che si aspetta sync.php.
 *   $phpCli     - eseguibile PHP a riga di comando (se vuoto lo cerca da solo)
 *   $sudoUser   - se indicato, sync.php viene lanciato come QUEL utente
 *                 invece che come www-data. Serve quando lo strumento di
 *                 sincronizzazione ha i suoi file e i suoi permessi.
 *
 * TRACCIA NEL LOG
 * Ogni fase scrive nel log del servizio (funzione 'logga' di index.php).
 * Se questo file viene eseguito da solo, i messaggi vanno a schermo.
 */

// Se lo script gira dentro il servizio usa il suo log; altrimenti stampa.
if (!function_exists('tracciaImport')) {
    function tracciaImport(string $msg, string $livello = 'INFO'): void {
        if (function_exists('logga')) {
            logga('  ' . $msg, $livello);
        } else {
            echo date('H:i:s') . " $msg\n";
        }
    }
}

/**
 * Trova l'eseguibile PHP da riga di comando.
 *
 * ATTENZIONE: PHP_BINARY NON si puo' usare qui. Da terminale contiene il
 * percorso giusto, ma dentro Apache e' vuoto (o punta al webserver): il
 * comando diventerebbe "'' script.php ..." e la shell risponderebbe
 * "sh: 1: : Permission denied" con codice 127.
 */
function trovaPhpCli(string $indicato = ''): string
{
    $candidati = [];
    if ($indicato !== '') {
        $candidati[] = $indicato;
    }
    // PHP_BINARY va bene SOLO se stiamo davvero girando da riga di comando
    if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && PHP_BINARY !== '') {
        $candidati[] = PHP_BINARY;
    }
    // I percorsi tipici, partendo dalla versione in uso
    $v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    foreach (["/usr/bin/php$v", "/usr/local/bin/php$v", "/usr/bin/php",
              "/usr/local/bin/php", "/usr/bin/php-cli"] as $c) {
        $candidati[] = $c;
    }

    foreach ($candidati as $c) {
        if ($c !== '' && @is_file($c) && @is_executable($c)) {
            return $c;
        }
    }

    throw new Exception(
        "Non trovo l'eseguibile PHP da riga di comando (provati: "
        . implode(', ', array_filter($candidati)) . "). "
        . "Indicalo nel config del server: post_close.params.php_cli, "
        . "es. \"/usr/bin/php$v\"."
    );
}


function importaCensimento(
    string $csvPath,
    string $imagesPath,
    string $zipPath,
    string $service,
    string $mediaFolder,
    array $campiFoto = ['alb_foto1','alb_foto1a','alb_foto2','alb_foto3'],
    string $delimiter = ',',
    string $phpCli = '',
    string $sudoUser = '',
    string $mediaBase = '',
    string $syncScript = ''
): array {

    // Cartella media dei progetti Lizmap (dove "../media/img/..." del progetto porta davvero).
    // Con Lizmap 3.9 i progetti sono fuori dalla cartella del programma: va indicata
    // in post_close.params.media_base di qfield.json.
    if ($mediaBase === '') {
        throw new Exception("post_close.params.media_base non configurato in qfield.json");
    }
    $baseMedia = $mediaBase;
    $destinationImages = rtrim($baseMedia, '/') . "/" . trim($mediaFolder, '/');

    // Il CSV contiene percorsi tipo "DCIM/DCIM-20260716-1430.jpg".
    // Va sostituita SOLO la cartella iniziale, non il nome del file
    // (che in QField comincia anch'esso per "DCIM").
    $cartellaOrigine = "DCIM";
    $replaceTo       = "../media/img/" . trim($mediaFolder, '/');

    $numeroImmagini = 0;
    $righeModificate = 0;
    $cronometro = microtime(true);

    // --- CONTROLLI INIZIALI ------------------------------------------------
    tracciaImport("[1/5] Controlli iniziali");
    if (!file_exists($csvPath)) {
        throw new Exception("CSV non trovato: $csvPath");
    }
    if (!is_dir($imagesPath)) {
        throw new Exception("Cartella immagini non trovata: $imagesPath");
    }
    if (!is_dir($destinationImages)) {
        if (!@mkdir($destinationImages, 0775, true) && !is_dir($destinationImages)) {
            throw new Exception("Impossibile creare la cartella destinazione: $destinationImages");
        }
    }

    tracciaImport(sprintf('      csv=%s (%.1f KB), immagini=%s, destinazione=%s',
        basename($csvPath), filesize($csvPath) / 1024,
        $imagesPath, $destinationImages));

    // --- MODIFICA CSV ------------------------------------------------------
    $t = microtime(true);
    tracciaImport("[2/5] Riscrittura percorsi foto nel CSV");
    $tmpCsv = $csvPath . ".tmp";
    $in  = fopen($csvPath, 'r');
    $out = fopen($tmpCsv, 'w');
    if (!$in || !$out) {
        throw new Exception("Errore apertura CSV");
    }

    $header = fgetcsv($in, 0, $delimiter);
    if ($header === false) {
        fclose($in); fclose($out);
        throw new Exception("CSV vuoto");
    }

    // Se l'intestazione ha una sola colonna, il separatore e' sbagliato:
    // meglio fermarsi subito che importare dati incomprensibili.
    if (count($header) === 1) {
        fclose($in); fclose($out);
        throw new Exception(
            "Il CSV non sembra separato da '$delimiter' (intestazione con una sola "
            . "colonna: '{$header[0]}'). Controlla 'delimiter' nel config dell'app."
        );
    }

    fputcsv($out, $header, $delimiter);

    // Indici delle colonne foto realmente presenti
    $indexes = [];
    $trovati = [];
    foreach ($campiFoto as $field) {
        $idx = array_search($field, $header, true);
        if ($idx !== false) {
            $indexes[] = $idx;
            $trovati[] = $field;
        }
    }
    if (!$indexes) {
        fclose($in); fclose($out);
        throw new Exception(
            "Nessuna delle colonne foto (" . implode(', ', $campiFoto) . ") "
            . "esiste nel CSV. Colonne presenti: " . implode(', ', $header)
        );
    }

    while (($row = fgetcsv($in, 0, $delimiter)) !== false) {
        // Normalizza: qualsiasi cella vuota o di soli spazi diventa stringa vuota "pulita",
        // cosi' sync.php la riconosce in modo uniforme come NULL.
        foreach ($row as $k => $v) {
            if ($v !== null && trim($v) === '') {
                $row[$k] = '';
            }
        }
        foreach ($indexes as $idx) {
            if (!isset($row[$idx]) || $row[$idx] === '') continue;

            $valore = str_replace('\\', '/', trim($row[$idx]));

            // Sostituisco solo il prefisso "DCIM/" all'inizio del percorso.
            // NB: una str_replace secca rovinerebbe anche il nome del file.
            if (preg_match('#^' . preg_quote($cartellaOrigine, '#') . '/#', $valore)) {
                $row[$idx] = $replaceTo . substr($valore, strlen($cartellaOrigine));
                $righeModificate++;
            } elseif (strpos($valore, '/') === false) {
                // Solo il nome del file, senza cartella: aggiungo il percorso.
                $row[$idx] = $replaceTo . '/' . $valore;
                $righeModificate++;
            }
        }
        fputcsv($out, $row, $delimiter);
    }

    fclose($in);
    fclose($out);

    if (!rename($tmpCsv, $csvPath)) {
        throw new Exception("Errore sostituzione CSV");
    }
    tracciaImport(sprintf('      colonne foto trovate: %s | percorsi riscritti: %d | %.2fs',
        implode(', ', $trovati), $righeModificate, microtime(true) - $t));

    // --- COPIA IMMAGINI ----------------------------------------------------
    $t = microtime(true);
    tracciaImport("[3/5] Copia immagini in media/img");
    $byteCopiati = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($imagesPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;

        $dest = $destinationImages . "/" . $file->getFilename();
        if (!copy($file->getPathname(), $dest)) {
            throw new Exception("Errore copiando {$file->getFilename()}");
        }
        $numeroImmagini++;
        $byteCopiati += $file->getSize();
    }
    tracciaImport(sprintf('      copiate %d immagini (%.1f MB) in %.2fs',
        $numeroImmagini, $byteCopiati / 1048576, microtime(true) - $t));

    // --- SYNC DATABASE -----------------------------------------------------
    $t = microtime(true);
    tracciaImport("[4/5] Sincronizzazione database");
    if ($syncScript === '') {
        $syncScript = '/usr/local/bin/prosit/UPSERT-sync-db2pgsql__trigger/sync.php';
    }
    if (!is_file($syncScript)) {
        throw new Exception("Script di sincronizzazione non trovato: $syncScript");
    }
    if (!function_exists('exec')) {
        throw new Exception("La funzione exec() e' disabilitata in PHP "
                            . "(disable_functions): impossibile lanciare sync.php.");
    }

    $php = trovaPhpCli($phpCli);
    tracciaImport("      eseguibile PHP: $php");

    $command = sprintf(
        '%s %s --service=%s --source=%s 2>&1',
        escapeshellarg($php),
        escapeshellarg($syncScript),
        escapeshellarg($service),
        escapeshellarg($csvPath)
    );

    // Lo strumento di sincronizzazione ha i suoi file e i suoi permessi,
    // pensati per un utente diverso da quello del webserver. Se indichi
    // 'sudo_user' lo eseguiamo come quell'utente, invece di dover aprire
    // i permessi a www-data cartella per cartella.
    // Serve una riga in /etc/sudoers.d/ (vedi il README).
    if ($sudoUser !== '') {
        if (!preg_match('/^[a-z_][a-z0-9_-]*$/i', $sudoUser)) {
            throw new Exception("Nome utente non valido per sudo: $sudoUser");
        }
        $command = 'sudo -n -u ' . escapeshellarg($sudoUser) . ' ' . $command;
        tracciaImport("      eseguito come utente: $sudoUser");
    }

    tracciaImport("      comando: $command", 'DETT');
    $uscita = [];
    $exitCode = 0;
    exec($command, $uscita, $exitCode);
    foreach ($uscita as $riga) {
        tracciaImport('      sync> ' . $riga);
    }
    tracciaImport(sprintf('      sync terminato con codice %d in %.2fs',
                          $exitCode, microtime(true) - $t));

    if ($exitCode !== 0) {
        throw new Exception("Errore durante sync database (codice $exitCode): "
                            . implode(' | ', array_slice($uscita, -5)));
    }

    // ATTENZIONE: sync.php puo' terminare con codice 0 ANCHE se non ha
    // importato nulla (lo dichiara solo nel riepilogo finale). Senza questo
    // controllo il servizio direbbe "tutto ok" con zero righe nel database.
    // Qui leggiamo il riepilogo e ci fermiamo se qualcosa non torna.
    $riepilogo = implode("\n", $uscita);
    $errori = 0;
    $inseriti = $aggiornati = null;
    if (preg_match('/errori\s*=\s*(\d+)/i', $riepilogo, $m))     $errori = (int)$m[1];
    if (preg_match('/inseriti\s*=\s*(\d+)/i', $riepilogo, $m))   $inseriti = (int)$m[1];
    if (preg_match('/aggiornati\s*=\s*(\d+)/i', $riepilogo, $m)) $aggiornati = (int)$m[1];

    if ($errori > 0) {
        // Prendo il primo errore vero: e' quello che spiega la causa.
        $primo = '';
        foreach ($uscita as $riga) {
            if (stripos($riga, 'ERROR') !== false) { $primo = trim($riga); break; }
        }
        throw new Exception(sprintf(
            'Sincronizzazione fallita: %d righe in errore (inserite: %s, aggiornate: %s). '
            . 'Primo errore: %s',
            $errori,
            $inseriti === null ? '?' : $inseriti,
            $aggiornati === null ? '?' : $aggiornati,
            substr($primo, 0, 300)
        ));
    }

    tracciaImport(sprintf('      riepilogo sync: inserite=%s, aggiornate=%s, errori=0',
        $inseriti === null ? '?' : $inseriti,
        $aggiornati === null ? '?' : $aggiornati));

    // --- CREA ZIP ----------------------------------------------------------
    // Se l'estensione 'zip' c'e', la usiamo. Altrimenti ripieghiamo sul
    // compressore scritto in PHP puro (zip_semplice.php): stesso risultato,
    // nessun pacchetto da installare.
    $t = microtime(true);
    tracciaImport("[5/5] Creazione ZIP");
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Impossibile creare lo ZIP: $zipPath");
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($imagesPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $n = 0;
        foreach ($files as $file) {
            if (!$file->isFile()) continue;
            $relative = substr($file->getPathname(), strlen(rtrim($imagesPath, '/')) + 1);
            $zip->addFile($file->getPathname(), $relative);
            $n++;
        }
        $zip->close();
        tracciaImport(sprintf('      ZIP con ZipArchive: %d file, %.1f MB in %.2fs -> %s',
            $n, (filesize($zipPath) ?: 0) / 1048576, microtime(true) - $t, $zipPath));
    } else {
        require_once __DIR__ . '/zip_semplice.php';
        $r = creaZipSemplice($imagesPath, $zipPath);
        tracciaImport(sprintf('      ZIP in PHP puro: %d file, %.1f MB -> %.1f MB in %.2fs -> %s',
            $r['file'], $r['byte_originali'] / 1048576, $r['byte_zip'] / 1048576,
            microtime(true) - $t, $zipPath));
        if (!$r['compresso']) {
            tracciaImport('      nota: zlib assente, archivio non compresso (valido lo stesso)');
        }
    }
    tracciaImport(sprintf('FINE elaborazione in %.2fs totali', microtime(true) - $cronometro));

    // --- RISULTATO ---------------------------------------------------------
    return [
        'csv'               => $csvPath,
        'zip'               => $zipPath,
        'cartella_immagini' => $destinationImages,
        'copiate'           => $numeroImmagini,
        'colonne_foto'      => $trovati,
        'percorsi_riscritti'=> $righeModificate,
    ];
}