<?php

/** Comprime una carpeta en un ZIP (con la carpeta como raíz). Uso: php comprimir.php <carpeta> <archivo.zip> */
[, $carpeta, $zip] = $argv;
$carpeta = rtrim(str_replace('\\', '/', realpath($carpeta)), '/');
$raiz = basename($carpeta);
@unlink($zip);
$z = new ZipArchive;
if ($z->open($zip, ZipArchive::CREATE) !== true) {
    exit("No se pudo crear {$zip}\n");
}
$archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta, FilesystemIterator::SKIP_DOTS));
foreach ($archivos as $archivo) {
    $ruta = str_replace('\\', '/', $archivo->getPathname());
    $z->addFile($ruta, $raiz.'/'.substr($ruta, strlen($carpeta) + 1));
}
$z->close();
echo basename($zip).': '.round(filesize($zip) / 1048576, 1)." MB\n";
