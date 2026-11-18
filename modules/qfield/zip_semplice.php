<?php
/**
 * zip_semplice.php
 * ----------------
 * Crea un file ZIP senza usare l'estensione PHP 'zip' (ZipArchive).
 *
 * PERCHE' ESISTE
 * Su molti server l'estensione zip non c'e' e non si puo' installare. Il
 * formato ZIP pero' e' pubblico e documentato: qui lo scriviamo a mano.
 * Serve solo 'zlib' (praticamente sempre presente dentro PHP); se manca,
 * l'archivio viene creato senza compressione — piu' grande, ma valido e
 * apribile normalmente da Windows, Mac e Linux.
 *
 * COME LAVORA
 * I file non vengono mai caricati interi in memoria: si leggono e comprimono
 * a blocchi da 1 MB. Cosi' regge anche centinaia di foto senza problemi.
 */

/**
 * Comprime tutto il contenuto di una cartella (sottocartelle incluse).
 *
 * @return array  ['file' => quanti, 'byte_originali' => ..., 'byte_zip' => ...]
 * @throws Exception in caso di problemi
 */
function creaZipSemplice(string $cartella, string $zipPath): array
{
    if (!is_dir($cartella)) {
        throw new Exception("Cartella da comprimere non trovata: $cartella");
    }

    // --- Raccolgo l'elenco dei file --------------------------------------
    $elenco = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cartella, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        if (!$f->isFile()) continue;
        $relativo = substr($f->getPathname(), strlen(rtrim($cartella, '/')) + 1);
        $elenco[str_replace('\\', '/', $relativo)] = $f->getPathname();
    }
    ksort($elenco);

    if (count($elenco) > 65535) {
        throw new Exception("Troppi file per un ZIP semplice: " . count($elenco)
                            . " (massimo 65535).");
    }

    $dirZip = dirname($zipPath);
    if (!is_dir($dirZip) && !@mkdir($dirZip, 0775, true) && !is_dir($dirZip)) {
        throw new Exception("Cartella dello ZIP non creabile: $dirZip");
    }

    $zip = @fopen($zipPath, 'wb');
    if (!$zip) {
        throw new Exception("Impossibile scrivere lo ZIP: $zipPath");
    }

    // Comprimiamo solo se zlib e' disponibile; altrimenti archiviamo e basta.
    $comprimi = function_exists('deflate_init');

    $centrale = '';     // "indice" dell'archivio, va scritto in fondo
    $offset = 0;        // posizione del prossimo file dentro lo ZIP
    $totOriginali = 0;
    $totCompressi = 0;

    try {
        foreach ($elenco as $nome => $percorso) {
            $r = _zipAggiungiFile($zip, $nome, $percorso, $comprimi, $offset, $centrale);
            $totOriginali += $r['originale'];
            $totCompressi += $r['compresso'];
        }

        // --- Indice finale (central directory) + chiusura ------------------
        $inizioIndice = $offset;
        fwrite($zip, $centrale);
        $dimIndice = strlen($centrale);

        // End of central directory
        fwrite($zip, pack('VvvvvVVv',
            0x06054b50,          // firma
            0, 0,                // numero disco / disco dell'indice
            count($elenco),      // voci su questo disco
            count($elenco),      // voci totali
            $dimIndice,
            $inizioIndice,
            0                    // lunghezza commento
        ));
    } finally {
        fclose($zip);
    }

    // Un ZIP con oltre 4 GB richiederebbe il formato ZIP64: qui ci fermiamo
    // prima, con un messaggio chiaro, invece di produrre un file corrotto.
    if ($totCompressi > 0xFFFFFFFF || $totOriginali > 0xFFFFFFFF) {
        @unlink($zipPath);
        throw new Exception("Archivio oltre 4 GB: serve il formato ZIP64, "
                            . "non supportato da questo compressore.");
    }

    return [
        'file'           => count($elenco),
        'byte_originali' => $totOriginali,
        'byte_zip'       => filesize($zipPath) ?: $totCompressi,
        'compresso'      => $comprimi,
    ];
}


/** Aggiunge un singolo file allo ZIP, leggendolo a blocchi. */
function _zipAggiungiFile($zip, string $nome, string $percorso, bool $comprimi,
                          int &$offset, string &$centrale): array
{
    $in = @fopen($percorso, 'rb');
    if (!$in) {
        throw new Exception("Impossibile leggere: $percorso");
    }

    [$oraDos, $dataDos] = _dataDos(@filemtime($percorso) ?: time());

    // Comprimo prima in un file temporaneo: serve a conoscere dimensione e
    // CRC PRIMA di scrivere l'intestazione, come vuole il formato ZIP.
    $tmp = tempnam(sys_get_temp_dir(), 'zipf');
    $out = fopen($tmp, 'w+b');

    $crc = hash_init('crc32b');
    $originale = 0;

    try {
        if ($comprimi) {
            $ctx = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
            while (!feof($in)) {
                $blocco = fread($in, 1048576);
                if ($blocco === false) break;
                if ($blocco !== '') {
                    $originale += strlen($blocco);
                    hash_update($crc, $blocco);
                    fwrite($out, deflate_add($ctx, $blocco, ZLIB_NO_FLUSH));
                }
            }
            fwrite($out, deflate_add($ctx, '', ZLIB_FINISH));
            $metodo = 8;   // deflate
        } else {
            while (!feof($in)) {
                $blocco = fread($in, 1048576);
                if ($blocco === false) break;
                if ($blocco !== '') {
                    $originale += strlen($blocco);
                    hash_update($crc, $blocco);
                    fwrite($out, $blocco);
                }
            }
            $metodo = 0;   // nessuna compressione
        }

        $compresso = ftell($out);
        $crcInt = unpack('N', hash_final($crc, true))[1];

        // Se la "compressione" ha ingrandito il file (capita con i JPEG, che
        // sono gia' compressi), lo archiviamo tale e quale.
        if ($comprimi && $compresso >= $originale) {
            fclose($out);
            @unlink($tmp);
            rewind($in);
            $tmp = tempnam(sys_get_temp_dir(), 'zipf');
            $out = fopen($tmp, 'w+b');
            stream_copy_to_stream($in, $out);
            $compresso = ftell($out);
            $metodo = 0;
        }

        $nomeBin = $nome;
        $flag = 0x0800;   // dice che il nome del file e' in UTF-8

        // Intestazione locale, subito prima dei dati
        $intestazione = pack('VvvvvvVVVvv',
            0x04034b50,   // firma
            20,           // versione necessaria per aprirlo
            $flag,
            $metodo,
            $oraDos, $dataDos,
            $crcInt,
            $compresso,
            $originale,
            strlen($nomeBin),
            0             // campo extra assente
        ) . $nomeBin;

        fwrite($zip, $intestazione);

        rewind($out);
        stream_copy_to_stream($out, $zip);

        // Voce corrispondente nell'indice finale
        $centrale .= pack('VvvvvvvVVVvvvvvVV',
            0x02014b50,   // firma
            0x031E,       // creato da: Unix
            20,           // versione necessaria
            $flag,
            $metodo,
            $oraDos, $dataDos,
            $crcInt,
            $compresso,
            $originale,
            strlen($nomeBin),
            0, 0,         // extra / commento
            0,            // numero disco
            0,            // attributi interni
            0x81A40000,   // permessi Unix (-rw-r--r--)
            $offset       // dove inizia il file dentro lo ZIP
        ) . $nomeBin;

        $offset += strlen($intestazione) + $compresso;

        return ['originale' => $originale, 'compresso' => $compresso];
    } finally {
        fclose($in);
        if (is_resource($out)) fclose($out);
        @unlink($tmp);
    }
}


/** Converte un timestamp nel formato data/ora usato dagli ZIP (eredita' DOS). */
function _dataDos(int $ts): array
{
    $d = getdate($ts);
    if ($d['year'] < 1980) {           // il formato non sa andare prima del 1980
        $d = getdate(315532800);       // 1 gennaio 1980
    }
    $ora  = ($d['hours'] << 11) | ($d['minutes'] << 5) | (intdiv($d['seconds'], 2));
    $data = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
    return [$ora, $data];
}