<?php
declare(strict_types=1);

namespace Prosit\Api;

use Prosit\Http\ApiException;
use Prosit\Support\Config;
use Prosit\Support\Log;

/**
 * Traduce gli errori PostgreSQL in risposte comprensibili, senza rivelare
 * SQL, nomi di tabelle o dettagli interni. Il dettaglio completo va nel log
 * (cercare il request_id della risposta).
 */
final class ErrorMapper
{
    public static function fromThrowable(\Throwable $e): ApiException
    {
        if ($e instanceof ApiException) {
            return $e;
        }
        Log::error(get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), 'api');

        if ($e instanceof \PDOException) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            $msg = (string) ($e->errorInfo[2] ?? $e->getMessage());
            $mapped = self::pg($state, $msg);
            if ($mapped !== null) {
                return $mapped;
            }
        }
        $debug = (bool) Config::get('debug', false);
        return new ApiException(500, 'Errore interno del server.' . ($debug ? ' ' . $e->getMessage() : ''));
    }

    private static function pg(string $state, string $msg): ?ApiException
    {
        $column = preg_match('/column "([^"]+)"/', $msg, $m) ? $m[1] : null;
        $class = substr($state, 0, 2);
        return match (true) {
            $state === '23505' => new ApiException(409, 'Esiste gia\' un record con questo valore (vincolo di unicita\').'),
            $state === '23503' => new ApiException(409, 'Il record e\' collegato ad altri dati, oppure fa riferimento a un dato inesistente.'),
            $state === '23502' => ApiException::unprocessable('Campo obbligatorio mancante' . ($column ? ": $column" : '.')),
            $state === '23514' => ApiException::unprocessable('Valore non ammesso dai controlli del database.'),
            $state === '22001' => ApiException::unprocessable('Testo troppo lungo' . ($column ? " per il campo $column" : '.')),
            in_array($state, ['22P02', '22007', '22008', '22003', '22023', '22P04'], true)
                => ApiException::badRequest('Valore non valido per il tipo del campo.'),
            $state === 'P0001' => ApiException::unprocessable(self::firstLine($msg)),
            $state === '42501' => ApiException::forbidden('Il ruolo del database usato dall\'API non ha i permessi su questi dati (vedi sql/10_grant_prosit.sql e bin/prosit check).'),
            $state === '57014' => new ApiException(504, 'Il database ha impiegato troppo tempo a rispondere.'),
            $state === '0A000' => new ApiException(409, 'Operazione non supportata da questa tabella/vista.'),
            $class === '08' || $state === '57P01' || $state === '57P03' => new ApiException(503, 'Database non raggiungibile, riprovare tra poco.'),
            $state === '40001' || $state === '40P01' => new ApiException(409, 'Conflitto con un\'altra modifica in corso, riprovare.'),
            default => null,
        };
    }

    /** Messaggi di RAISE EXCEPTION scritti nei trigger: si mostra solo la prima riga, senza il prefisso tecnico. */
    private static function firstLine(string $msg): string
    {
        $msg = preg_replace('/^SQLSTATE\[[^\]]+\]:[^:]*:\s*\d*\s*(ERROR:\s*)?/i', '', $msg) ?? $msg;
        $msg = preg_replace('/^\s*(ERROR|ERRORE)\s*:\s*/i', '', $msg) ?? $msg;
        $line = trim(strtok($msg, "\n") ?: $msg);
        return $line !== '' ? mb_substr($line, 0, 300) : 'Operazione rifiutata dal database.';
    }
}
