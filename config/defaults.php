<?php
/**
 * Valori predefiniti di Prosit. NON modificare qui le impostazioni della macchina:
 * vanno in local/prosit.php (o /etc/prosit/prosit.php se installato da root; vedi etc/prosit.php.example), che sovrascrive questo file.
 */
return [
    'timezone' => 'Europe/Rome',
    'debug' => false,                       // true = messaggi d'errore dettagliati nelle risposte (MAI in produzione)

    'paths' => [
        'var' => PROSIT_ROOT . '/var',      // log, cache, dati (scrivibile da www-data)
        'base_url' => '/lizmap/prosit',     // indirizzo pubblico della cartella public/
    ],

    'auth' => [
        // Cartella www di Lizmap come la vede Apache. Usare il collegamento simbolico
        // (es. /var/www/html/lizmap o /var/www/lizmap-attiva/www), non la versione:
        // cosi' un cambio di versione di Lizmap viene seguito da solo.
        'lizmap_www' => '/var/www/html/lizmap',
        'session' => [
            'cookie_names' => ['PHPSESSID'],   // [sessions] name= di Lizmap, se impostato
        ],
        // l'utente collegato e i suoi gruppi li dice Lizmap (Prosit API v2, GET /auth/me)
        'lizmap_api' => [
            'url' => 'http://127.0.0.1/lizmap/api.php/prosit/v2/auth/me',
            'cache_secondi' => 30,
            'timeout' => 3,
        ],
        'api_keys_file' => PROSIT_ETC . '/api_keys.php',
    ],

    'db' => [
        'service_file' => PROSIT_ETC . '/pg_service.conf',
        'statement_timeout_ms' => 30000,
    ],

    'api' => [
        // prefisso tolto dal percorso prima del routing; anche /index.php che segue viene tolto:
        // /lizmap/prosit/api/index.php/v1/... = /v1/...
        'prefixes' => ['/lizmap/prosit/api'],
        'max_body_bytes' => 1048576,
    ],

    'security' => [
        // altri indirizzi da cui il browser puo' inviare modifiche (oltre a quello della richiesta),
        // es. se Lizmap e' raggiungibile sia via IP sia via nome: ['https://gis.comune.example.it']
        'allowed_origins' => [],
    ],


    'rekla' => [
        'base_url' => 'http://10.0.35.1/',     // Rekla raggiungibile dal server
        'rule' => ['@user'],
        'timeout' => 60,
    ],

    // Registro controlli videosorveglianza
    'tvcc' => [
        'groups' => ['admins', 'vigili', 'ced', 'Polizia Municipale', 'Ufficio supporto informatico'],
        'login_url' => '/lizmap/index.php/view/map?repository=segn&project=rekla',
    ],

    // Chi vede quale applicazione: usato dai pulsanti nelle mappe Lizmap (GET /v1/auth/me -> apps)
    // e, dove indicato, anche dal server. Gettoni: '@user', '@public', '@key:NOME', gruppi Lizmap.
    // Le voci con 'from' riusano una regola gia' definita altrove.
    'apps' => [
        'registro-tvcc'     => ['from' => 'tvcc.groups'],
        'calendario-verde'  => ['admins', 'man_verde_es', 'man_int', 'man', 'urp'],
        'report-ticket'     => ['@user'],
        'report-ticket-bit' => ['admins', 'Ufficio Manutenzioni e Viabilità'],   // controllato anche dal server
        'opendata-admin'    => ['from' => 'opendata.group'],
    ],

    // Report ticket (ex popup-ticket-data-report)
    'report' => [
        'service' => 'segnalazioni_23',
        'manutentori' => ['man_ip_es', 'man_verde_es', 'man_es_tlc'],
    ],

    // Pannello services.json degli open data
    'opendata' => [
        'group' => 'opnd',
        'services_path' => PROSIT_ROOT . '/var/data/opendata/services.json',
        'backup_dir' => PROSIT_ROOT . '/var/data/opendata/backup',
        'test_host_marker' => '10.0.43.51',
        'prod_relay_prefix' => 'https://www.comune.montelupo-fiorentino.fi.it/comune-in-chiaro/api/opendata_relay.php?u=',
        'prod_host_base' => 'https://www.comune.montelupo-fiorentino.fi.it/comune-in-chiaro',
        'test_host_base' => 'http://10.0.43.51/lizmap/comune-in-chiaro',
    ],

    // Stazione meteo
    'meteo' => [
        'key' => 'meteo',                      // nome della chiave in api_keys.php
        'log_file' => PROSIT_ROOT . '/var/data/meteo/dati_meteo.log',
        'max_bytes' => 65536,
    ],

];
