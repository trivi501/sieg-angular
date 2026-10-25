<?php
// Servidor de demostración: el frontend compilado y la API de Laravel en el mismo origen (como en producción).
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$backend = dirname(__DIR__, 3).'/backend/public';
if (preg_match('#^/(api|sanctum|up|storage)(/|$)#', $ruta)) {
    $_SERVER['SCRIPT_FILENAME'] = $backend.'/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    chdir($backend);
    require $backend.'/index.php';
    return true;
}
$dist = __DIR__.'/dist/browser';
$archivo = realpath($dist.$ruta);
if ($archivo && str_starts_with($archivo, $dist) && is_file($archivo)) {
    $tipos = ['js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'json' => 'application/json', 'webp' => 'image/webp'];
    header('Content-Type: '.($tipos[pathinfo($archivo, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($archivo);
    return true;
}
header('Content-Type: text/html; charset=utf-8');
readfile($dist.'/index.html');
return true;
