<?php
declare(strict_types=1);

namespace Prosit\Db;

use PDO;
use Prosit\Support\Config;

/**
 * Connessioni PostgreSQL tramite pg_service.conf (un solo file, fuori dalla web root:
 * local/pg_service.conf, leggibile solo dal proprietario e da www-data). Nel codice nessuna password.
 *
 *   Db::connect('segnalazioni_23')            lettura
 *   Db::connect('segnalazioni_23', true)      scrittura: usa la sezione [segnalazioni_23_rw]
 *                                             se esiste, altrimenti la stessa di lettura
 *
 * Ogni connessione: application_name=prosit, timeout delle query, errori come eccezioni,
 * prepared statement veri (niente emulazione).
 */
final class Db
{
    /** @var array<string,PDO> */
    private static array $pool = [];
    private static ?array $sections = null;

    public static function connect(string $service, bool $write = false): PDO
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $service)) {
            throw new \InvalidArgumentException('Nome servizio non valido');
        }
        if ($write && self::hasService($service . '_rw')) {
            $service .= '_rw';
        }
        if (isset(self::$pool[$service])) {
            return self::$pool[$service];
        }
        if (!self::hasService($service)) {
            throw new \RuntimeException("Servizio PostgreSQL [$service] non definito in " . self::serviceFile());
        }
        putenv('PGSERVICEFILE=' . self::serviceFile());
        putenv('PGAPPNAME=prosit');
        $pdo = new PDO('pgsql:service=' . $service, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $timeout = (int) Config::get('db.statement_timeout_ms', 30000);
        $pdo->exec('SET statement_timeout = ' . max(1000, $timeout));
        $pdo->exec("SET application_name = 'prosit'");
        return self::$pool[$service] = $pdo;
    }

    public static function serviceFile(): string
    {
        return (string) Config::get('db.service_file', PROSIT_ETC . '/pg_service.conf');
    }

    public static function hasService(string $name): bool
    {
        return in_array($name, self::services(), true);
    }

    /** Nomi delle sezioni presenti in pg_service.conf. */
    public static function services(): array
    {
        if (self::$sections !== null) {
            return self::$sections;
        }
        $file = self::serviceFile();
        $text = is_readable($file) ? (string) file_get_contents($file) : '';
        preg_match_all('/^\s*\[([^\]]+)\]\s*$/m', $text, $m);
        return self::$sections = array_map('trim', $m[1]);
    }

    /**
     * Esegue $fn in una transazione. Imposta anche "prosit.user" (visibile ai trigger con
     * current_setting('prosit.user', true)) per sapere chi ha fatto la modifica.
     */
    public static function transaction(PDO $pdo, string $user, callable $fn): mixed
    {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT set_config('prosit.user', :u, true)");
            $st->execute([':u' => $user]);
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Solo per i test. */
    public static function reset(): void
    {
        self::$pool = [];
        self::$sections = null;
    }
}
