<?php

/**
 * Génère les icônes de l'application installable (PWA) dans public/icons : un « P » blanc sur fond noir, avec le point lavande
 * de la charte. À relancer si la marque change : php bin/generate-pwa-icons.php
 */

$root = dirname(__DIR__);
$font = $root.'/assets/fonts/figtree-700.ttf';
$out = $root.'/public/icons';
if (!is_dir($out)) {
    mkdir($out, 0775, true);
}

/**
 * @param float $letter part de la hauteur occupée par la lettre (plus petite pour une icône « maskable », que le système rogne)
 * @param float $radius arrondi des coins, en part de la taille (0 : carré plein, rogné par le système)
 */
function icon(string $font, int $size, float $letter, float $radius): GdImage
{
    $s = 1024; // dessiné en grand puis réduit : bords lissés
    $img = imagecreatetruecolor($s, $s);
    imagesavealpha($img, true);
    imagealphablending($img, false);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagealphablending($img, true);

    $ink = imagecolorallocate($img, 18, 18, 18);
    $white = imagecolorallocate($img, 255, 255, 255);
    $accent = imagecolorallocate($img, 169, 155, 249);

    if ($radius > 0) {
        $r = (int) ($s * $radius);
        imagefilledrectangle($img, $r, 0, $s - $r, $s, $ink);
        imagefilledrectangle($img, 0, $r, $s, $s - $r, $ink);
        foreach ([[$r, $r], [$s - $r, $r], [$r, $s - $r], [$s - $r, $s - $r]] as [$x, $y]) {
            imagefilledellipse($img, $x, $y, $r * 2, $r * 2, $ink);
        }
    } else {
        imagefilledrectangle($img, 0, 0, $s, $s, $ink);
    }

    // Taille de police telle que la hauteur de la capitale « P » vaut $letter × $s.
    $pt = 100;
    $box = imagettfbbox($pt, 0, $font, 'P');
    $pt *= ($letter * $s) / abs($box[7] - $box[1]);
    $box = imagettfbbox($pt, 0, $font, 'P');
    $w = abs($box[4] - $box[0]);
    $h = abs($box[7] - $box[1]);
    $x = (int) (($s - $w) / 2 - $box[0] - $s * 0.03);
    $y = (int) (($s - $h) / 2 + $h);
    imagettftext($img, $pt, 0, $x, $y, $white, $font, 'P');

    // Le point lavande, au pied de la lettre.
    $dot = (int) ($s * 0.075);
    imagefilledellipse($img, (int) ($x + $w + $s * 0.075), (int) ($y - $dot * 0.2), $dot * 2, $dot * 2, $accent);

    $small = imagecreatetruecolor($size, $size);
    imagesavealpha($small, true);
    imagealphablending($small, false);
    imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
    imagecopyresampled($small, $img, 0, 0, 0, 0, $size, $size, $s, $s);

    return $small;
}

$files = [
    'icon-192.png' => [192, 0.50, 0.22],
    'icon-512.png' => [512, 0.50, 0.22],
    'icon-maskable-512.png' => [512, 0.36, 0],
    'apple-touch-icon.png' => [180, 0.44, 0],
    'favicon-32.png' => [32, 0.56, 0.2],
];
foreach ($files as $name => [$size, $letter, $radius]) {
    imagepng(icon($font, $size, $letter, $radius), $out.'/'.$name, 9);
    echo "$name\n";
}
