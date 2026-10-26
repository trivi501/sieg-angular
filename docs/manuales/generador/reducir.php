<?php

/**
 * Lo mismo que sips en imagenes.sh, con GD: cada PNG de <capturas> a JPEG de 1600 px de ancho (calidad 70) en <imgj>,
 * y sus medidas en <imgj>/medidas.txt ("nombre ancho alto").
 * Uso: php reducir.php <capturas> <imgj>
 */
[, $origen, $destino] = $argv;
$medidas = '';
foreach (glob("{$origen}/*.png") as $png) {
    $nombre = basename($png, '.png');
    $img = imagecreatefrompng($png);
    [$w, $h] = [imagesx($img), imagesy($img)];
    $alto = (int) round($h * 1600 / $w);
    $chica = imagecreatetruecolor(1600, $alto);
    imagecopyresampled($chica, $img, 0, 0, 0, 0, 1600, $alto, $w, $h);
    imagejpeg($chica, "{$destino}/{$nombre}.jpg", 70);
    $medidas .= "{$nombre} 1600 {$alto}\n";
}
file_put_contents("{$destino}/medidas.txt", $medidas);
