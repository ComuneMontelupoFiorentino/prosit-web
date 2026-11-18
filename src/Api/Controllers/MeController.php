<?php
declare(strict_types=1);

namespace Prosit\Api\Controllers;

use Prosit\Auth\Auth;
use Prosit\Http\Response;
use Prosit\Support\Config;

final class MeController
{
    public static function routes(): array
    {
        return [
            ['GET', '/health', [self::class, 'health']],
            ['GET', '/auth/me', [self::class, 'me']],
        ];
    }

    /**
     * Usato dall'installatore e dal monitoraggio, senza login.
     * Con ?full=1 e SOLO da questa macchina (127.0.0.1 / ::1) esegue anche i controlli
     * con l'utente del server web: configurazione, Lizmap (auth/me della API v2), cartelle, database.
     * Cosi' la verifica "come www-data" non richiede sudo.
     */
    public static function health(): never
    {
        $out = ['service' => 'prosit-api', 'version' => PROSIT_VERSION, 'time' => date('c')];
        $local = in_array(\Prosit\Http\Request::ip(), ['127.0.0.1', '::1'], true);
        if ($local && ($_GET['full'] ?? '') === '1') {
            $c = [];
            $c['config'] = is_readable(PROSIT_ETC . '/prosit.php') ? 'ok' : 'manca ' . PROSIT_ETC . '/prosit.php';
            $c['php_user'] = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?';
            $c['lizmap_api'] = \Prosit\Auth\LizmapApi::check();
            foreach (['log', 'cache', 'data'] as $d) {
                $c['var_' . $d] = is_writable(\Prosit\Support\Config::varPath($d)) ? 'ok' : 'non scrivibile';
            }
            foreach (\Prosit\Db\Db::services() as $s) {
                try {
                    \Prosit\Db\Db::connect($s)->query('SELECT 1')->fetch();
                    $c['db_' . $s] = 'ok';
                } catch (\Throwable $e) {
                    $c['db_' . $s] = 'errore: ' . preg_replace('/\s+/', ' ', $e->getMessage());
                }
            }
            $out['checks'] = $c;
        }
        Response::json($out);
    }

    public static function me(): never
    {
        $me = Auth::requireAuthenticated();
        $d = $me->toArray();
        $isAdmin = $me->inGroup('admins');
        $d['permissions'] = ['is_admin' => $isAdmin, 'is_edition' => $isAdmin];
        $d['apps'] = [];
        foreach (array_keys((array) Config::get('apps', [])) as $app) {
            $d['apps'][$app] = $me->allows(self::appRule($app));
        }
        Response::json($d);
    }

    /** Regola di accesso di un'applicazione (config apps), [] se non definita = nessuno. */
    public static function appRule(string $app): array
    {
        $rule = Config::get('apps.' . $app, []);
        if (is_array($rule) && isset($rule['from'])) {
            $rule = Config::get((string) $rule['from'], []);
        }
        return array_values(array_map('strval', (array) $rule));
    }
}
