<?php
// convert_icons.php — usuwa tło z ikon, zapisuje jako przezroczysty PNG
// Uruchom raz: php convert_icons.php lub przez przeglądarkę

$icons_dir = __DIR__ . '/data/icons/';
$tolerance  = 55;   // fuzz: im wyższy tym więcej kolorów usuwa (0–441)

// ---- helpers ----------------------------------------------------------------

function colorDist(int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): float {
    return sqrt(($r1-$r2)**2 + ($g1-$g2)**2 + ($b1-$b2)**2);
}

/**
 * Flood-fill z 4 narożników — usuwa piksele podobne do tła (alfa → 127).
 * Używa stosu (array_pop) zamiast kolejki, bo przy 50×50 kolejność nie ma znaczenia.
 */
function removeBg(\GdImage $img, float $tol): \GdImage {
    $w = imagesx($img);
    $h = imagesy($img);

    // nowy obraz z kanałem alfa
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $clear = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $clear);
    imagecopy($dst, $img, 0, 0, 0, 0, $w, $h);
    imagealphablending($dst, false);

    // próbkuj kolor tła z 4 narożników — bierz najjaśniejszy (zazwyczaj biały/szary bg)
    $corners = [
        imagecolorsforindex($img, imagecolorat($img, 0,    0)),
        imagecolorsforindex($img, imagecolorat($img, $w-1, 0)),
        imagecolorsforindex($img, imagecolorat($img, 0,    $h-1)),
        imagecolorsforindex($img, imagecolorat($img, $w-1, $h-1)),
    ];
    // wybierz narożnik o największej jasności (r+g+b)
    usort($corners, fn($a,$b) =>
        ($b['red']+$b['green']+$b['blue']) <=> ($a['red']+$a['green']+$a['blue'])
    );
    [$bgR, $bgG, $bgB] = [$corners[0]['red'], $corners[0]['green'], $corners[0]['blue']];

    // flood fill ze wszystkich 4 narożników jednocześnie
    $visited = array_fill(0, $w * $h, false);
    $stack   = [[0,0], [$w-1,0], [0,$h-1], [$w-1,$h-1]];

    while ($stack) {
        [$x, $y] = array_pop($stack);
        if ($x < 0 || $x >= $w || $y < 0 || $y >= $h) continue;
        $idx = $y * $w + $x;
        if ($visited[$idx]) continue;
        $visited[$idx] = true;

        $c    = imagecolorat($dst, $x, $y);
        $rgba = imagecolorsforindex($dst, $c);

        if ($rgba['alpha'] >= 100) continue; // już prawie przezroczysty

        $dist = colorDist($rgba['red'], $rgba['green'], $rgba['blue'], $bgR, $bgG, $bgB);

        if ($dist <= $tol) {
            // wylicz alpha proporcjonalnie do odległości (miękka krawędź)
            $alphaVal = min(127, (int)(($dist / $tol) * 127));
            imagesetpixel($dst, $x, $y, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            $stack[] = [$x+1, $y];
            $stack[] = [$x-1, $y];
            $stack[] = [$x, $y+1];
            $stack[] = [$x, $y-1];
        }
    }

    return $dst;
}

// ---- main -------------------------------------------------------------------

$files = glob($icons_dir . '*.png');
sort($files);

$ok   = [];
$skip = [];
$err  = [];

foreach ($files as $file) {
    $name = basename($file);

    $src = @imagecreatefrompng($file);
    if (!$src) { $err[] = $name; continue; }

    imagealphablending($src, false);
    imagesavealpha($src, true);

    // sprawdź czy już ma przezroczystość — jeśli nie ma białego/jasnoszarego tła pomiń
    // (nie pomijamy — przetwarzamy wszystkie, żeby ujednolicić)

    $dst = removeBg($src, $tolerance);
    imagedestroy($src);

    imagesavealpha($dst, true);
    $saved = imagepng($dst, $file, 9);
    imagedestroy($dst);

    if ($saved) $ok[] = $name;
    else        $err[] = $name;
}

// ---- output -----------------------------------------------------------------
$isCli = php_sapi_name() === 'cli';
$nl    = $isCli ? "\n" : "<br>";

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>"
       . "<title>Convert Icons</title>"
       . "<style>body{font-family:monospace;padding:20px;background:#f0f2f5}"
       . ".ok{color:green}.err{color:red}.skip{color:#888}"
       . ".preview{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px;background:#555;padding:12px;border-radius:8px}"
       . ".preview img{width:50px;height:50px;border:1px solid #888}"
       . "</style></head><body>";
}

echo "=== Konwersja ikon (tol=$tolerance) ===$nl$nl";
echo "OK    : " . count($ok)   . "$nl";
echo "Błąd  : " . count($err)  . "$nl";
if ($err) echo "  → " . implode(', ', $err) . $nl;

if (!$isCli) {
    echo "<div class='preview'>";
    foreach ($ok as $n) {
        echo "<img src='data/icons/" . htmlspecialchars($n) . "?v=" . time() . "' title='$n'>";
    }
    echo "</div></body></html>";
}
