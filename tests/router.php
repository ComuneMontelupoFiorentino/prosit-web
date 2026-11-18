<?php
// Router per "php -S" nei test: simula il collegamento <www di Lizmap>/prosit -> public/
$root = dirname(__DIR__) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/lizmap/prosit/api(/|$)#', $path)) {
    require $root . '/api/index.php';
    return true;
}
if (str_starts_with($path, '/lizmap/prosit/')) {
    $rel = substr($path, strlen('/lizmap/prosit/'));
    $file = realpath($root . '/' . $rel);
    if ($file && is_dir($file)) { $file = realpath($file . '/index.php') ?: realpath($file . '/index.html'); }
    if (!$file || !str_starts_with($file, $root)) { http_response_code(404); echo 'not found'; return true; }
    if (str_ends_with($file, '.php')) { chdir(dirname($file)); $_SERVER['SCRIPT_FILENAME'] = $file; require $file; return true; }
    $types = ['html'=>'text/html','css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml','woff2'=>'font/woff2'];
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($file); return true;
}
http_response_code(404); echo 'fuori da prosit'; return true;
