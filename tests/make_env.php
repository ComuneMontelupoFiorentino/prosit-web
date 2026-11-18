<?php
// Crea la configurazione di prova: pg_service sui database prosit_test_*, chiavi macchina,
// Lizmap (utente e gruppi) chiesto alla Prosit API v2 del Lizmap di prova.
// uso: php tests/make_env.php <dir>
$d = $argv[1];
$pgh = getenv('PGHOST') ?: '127.0.0.1';
$pgp = getenv('PGPORT') ?: '5432';
$lz = rtrim(getenv('LIZMAP_URL') ?: 'http://127.0.0.1/lizmap', '/');
@mkdir("$d/etc", 0777, true); @mkdir("$d/var", 0777, true); @mkdir("$d/lizmap/www", 0777, true);
$svc = '';
foreach (['segnalazioni_23' => 1, 'llpp' => 0, 'vp' => 1, 'ip_2020_2022' => 0] as $db => $rw) {
    $svc .= "[$db]\nhost=$pgh\nport=$pgp\ndbname=prosit_test_$db\nuser=prosit_test_ro\npassword=ro_test\n\n";
    if ($rw) {
        $svc .= "[{$db}_rw]\nhost=$pgh\nport=$pgp\ndbname=prosit_test_$db\nuser=prosit_test_rw\npassword=rw_test\n\n";
    }
}
file_put_contents("$d/etc/pg_service.conf", $svc);
$h = hash('sha256', 'chiave-meteo-di-prova');
file_put_contents("$d/etc/api_keys.php", "<?php return ['meteo' => ['sha256' => '$h', 'query_param' => true], 'qfield' => ['sha256' => '" . hash('sha256', 'qf') . "', 'ips' => ['10.9.9.9']]];");
file_put_contents("$d/etc/prosit.php", "<?php return ['debug' => false, 'paths' => ['var' => '$d/var'],
  'auth' => ['lizmap_www' => '$d/lizmap/www', 'api_keys_file' => '$d/etc/api_keys.php',
             'lizmap_api' => ['url' => '$lz/api.php/prosit/v2/auth/me', 'cache_secondi' => 0]],
  'db' => ['service_file' => '$d/etc/pg_service.conf'],
  'opendata' => ['services_path' => '$d/var/data/opendata/services.json', 'backup_dir' => '$d/var/data/opendata/backup'],
  'meteo' => ['log_file' => '$d/var/data/meteo/dati_meteo.log'],
  'rekla' => ['base_url' => 'http://127.0.0.1:1/']];");
