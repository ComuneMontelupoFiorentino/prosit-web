<?php
declare(strict_types=1);

namespace Prosit\Api\Controllers;

use Prosit\Auth\Auth;
use Prosit\Http\ApiException;
use Prosit\Support\Config;
use Prosit\Support\Log;

/**
 * GET /v1/documenti/rekla?post_id=123&file=abc
 *
 * Gli allegati di Rekla sono raggiungibili solo dal server: questo endpoint li scarica
 * lato server e li gira al browser (sostituisce gestione-disservizi/documents/document_proxy.php).
 * Differenze rispetto al vecchio proxy:
 *  - serve la sessione Lizmap (prima chiunque raggiungesse il server poteva scaricare);
 *  - l'indirizzo di Rekla e' in configurazione (rekla.base_url);
 *  - il documento e' servito in sandbox (CSP), cosi' un HTML allegato non puo' eseguire
 *    script con i permessi del sito.
 */
final class DocumentController
{
    public static function routes(): array
    {
        return [['GET', '/documenti/rekla', [self::class, 'rekla']]];
    }

    public static function rekla(): never
    {
        $me = Auth::require((array) Config::get('rekla.rule', ['@user']));
        $postId = filter_input(INPUT_GET, 'post_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $file = (string) ($_GET['file'] ?? '');
        if (!$postId || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $file)) {
            throw ApiException::badRequest('Parametri non validi.');
        }
        $base = rtrim((string) Config::get('rekla.base_url', 'http://10.0.35.1/'), '/') . '/';
        $url = $base . '?' . http_build_query(['post_id' => $postId, 'dciaction' => 'downloadfile', 'file' => $file]);

        $allowedTypes = (array) Config::get('rekla.passthrough_headers', ['content-length', 'last-modified']);
        $status = 0;
        $started = false;
        $remoto = ['type' => '', 'name' => ''];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => (int) Config::get('rekla.timeout', 60),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $h) use (&$status, &$remoto, $allowedTypes): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int) $m[1];
                    $remoto = ['type' => '', 'name' => ''];
                    return strlen($h);
                }
                $p = strpos($h, ':');
                if ($p !== false && $status >= 200 && $status < 300) {
                    $name = strtolower(trim(substr($h, 0, $p)));
                    $val = trim(substr($h, $p + 1));
                    if ($name === 'content-type') {
                        $remoto['type'] = strtolower(trim(explode(';', $val)[0]));
                    } elseif ($name === 'content-disposition') {
                        $remoto['name'] = self::nomeDaDisposition($val);
                    } elseif (in_array($name, $allowedTypes, true)) {
                        header($name . ': ' . $val, true);
                    }
                }
                return strlen($h);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$status, &$started, &$remoto, $postId, $file): int {
                if ($status < 200 || $status >= 300) {
                    return strlen($data); // scarta il corpo degli errori remoti
                }
                if (!$started) {
                    $started = true;
                    self::intestazioni($remoto, $data, $postId, $file);
                }
                echo $data;
                flush();
                return strlen($data);
            },
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if (!$started) {
            Log::warn("Rekla post_id=$postId file=$file -> HTTP $status $err", 'api');
            throw new ApiException($status === 404 ? 404 : 502, $status === 404 ? 'Documento non trovato su Rekla.' : 'Rekla non raggiungibile o documento non disponibile.');
        }
        if ($ok === false) {
            Log::warn("Rekla trasferimento interrotto post_id=$postId: $err", 'api');
        }
        Log::info($me->login . " DOC rekla post_id=$postId file=$file", 'audit');
        exit;
    }

    /** Tipi che Rekla puo' dichiarare in modo generico: il tipo vero si ricava dal contenuto. */
    private const GENERICI = ['', 'application/octet-stream', 'application/force-download', 'application/download',
        'binary/octet-stream', 'application/x-download', 'application/unknown'];
    /** Tipi che il browser mostra da se' (gli altri si scaricano). */
    private const IN_LINEA = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'application/pdf', 'text/plain'];
    private const ESTENSIONI = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/bmp' => 'bmp', 'image/tiff' => 'tif', 'image/heic' => 'heic', 'application/pdf' => 'pdf', 'text/plain' => 'txt',
        'application/zip' => 'zip', 'application/msword' => 'doc', 'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.oasis.opendocument.text' => 'odt', 'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3'];

    /** filename / filename* dell'intestazione Content-Disposition di Rekla, ripulito */
    private static function nomeDaDisposition(string $v): string
    {
        $n = '';
        if (preg_match("/filename\\*\\s*=\\s*[^']*'[^']*'([^;]+)/i", $v, $m)) {
            $n = rawurldecode(trim($m[1], " \"'"));
        } elseif (preg_match('/filename\s*=\s*"([^"]*)"/i', $v, $m) || preg_match('/filename\s*=\s*([^;]+)/i', $v, $m)) {
            $n = trim($m[1], " \"'");
        }
        $n = basename(str_replace('\\', '/', $n));
        $n = preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '', $n) ?? '';
        return mb_substr(trim($n), 0, 150);
    }

    /**
     * Tipo, nome e modalita' di apertura del documento. Rekla spesso dichiara un tipo generico
     * (application/octet-stream, force-download) e nessun nome: il browser allora scaricava un file
     * senza estensione. Qui il tipo si ricava dai primi byte, il nome da Rekla o dal tipo, e foto e
     * PDF si aprono nel browser invece di essere scaricati.
     */
    private static function intestazioni(array $remoto, string $inizio, int $postId, string $file): void
    {
        $tipo = $remoto['type'];
        if (in_array($tipo, self::GENERICI, true) && class_exists('finfo')) {
            $rilevato = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($inizio);
            if ($rilevato !== '' && !in_array($rilevato, ['application/octet-stream', 'text/html', 'application/xhtml+xml', 'image/svg+xml'], true)) {
                $tipo = $rilevato;
            }
        }
        if (in_array($tipo, ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml', 'application/javascript', 'text/javascript'], true)) {
            $tipo = 'application/octet-stream';   // mai eseguire contenuti attivi sul dominio di Lizmap
        }
        $tipo = $tipo !== '' ? $tipo : 'application/octet-stream';
        $ext = self::ESTENSIONI[$tipo] ?? '';
        $nome = $remoto['name'];
        if ($nome === '') {
            $nome = 'rekla-' . $postId . '-' . $file . ($ext !== '' ? '.' . $ext : '');
        } elseif ($ext !== '' && !preg_match('/\.[A-Za-z0-9]{1,5}$/', $nome)) {
            $nome .= '.' . $ext;
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nome) ?: 'documento';
        $modo = in_array($tipo, self::IN_LINEA, true) ? 'inline' : 'attachment';
        header('Content-Type: ' . $tipo . ($tipo === 'text/plain' ? '; charset=utf-8' : ''));
        header('Content-Disposition: ' . $modo . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nome));
        header('X-Content-Type-Options: nosniff');
        // il visualizzatore PDF dei browser non funziona in una pagina "sandbox"
        header($tipo === 'application/pdf'
            ? "Content-Security-Policy: default-src 'none'; object-src 'self'; frame-ancestors 'self'"
            : "Content-Security-Policy: sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'");
        header('Cache-Control: private, max-age=300');
    }
}
