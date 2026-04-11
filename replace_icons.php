<?php
// replace_icons.php — pobiera lepsze ikony dla "duchowatych"
// Uruchom: php replace_icons.php

$ICONS_DIR = __DIR__ . '/data/icons/';

// ── Mapa: plik → lista URL do próbowania po kolei ─────────────────────────────
// Kolejność: Clearbit (wysokiej jakości logo) → DuckDuckGo favicon → Google favicon
$replacements = [

    'trustpilot.png' => [
        'https://logo.clearbit.com/trustpilot.com',
        'https://icons.duckduckgo.com/ip3/trustpilot.com.ico',
    ],
    'warsaw.png' => [  // Sklep Wawa = mindcloud.pl/my-account
        'https://logo.clearbit.com/mindcloud.pl',
        'https://icons.duckduckgo.com/ip3/mindcloud.pl.ico',
    ],
    'smsapi.png' => [
        'https://logo.clearbit.com/smsapi.pl',
        'https://icons.duckduckgo.com/ip3/smsapi.pl.ico',
    ],
    'tpay.png' => [
        'https://logo.clearbit.com/tpay.com',
        'https://icons.duckduckgo.com/ip3/tpay.com.ico',
    ],
    'gsc.png' => [
        'https://www.google.com/s2/favicons?domain=search.google.com&sz=128',
        'https://logo.clearbit.com/google.com',
        'https://icons.duckduckgo.com/ip3/search.google.com.ico',
    ],
    'cpanel.png' => [
        'https://logo.clearbit.com/cpanel.com',
        'https://icons.duckduckgo.com/ip3/cpanel.com.ico',
    ],
    'webd.png' => [
        'https://logo.clearbit.com/webd.pl',
        'https://icons.duckduckgo.com/ip3/webd.pl.ico',
    ],
    'segregatory.png' => [
        'https://logo.clearbit.com/drukarniaszczecin.pl',
        'https://icons.duckduckgo.com/ip3/drukarniaszczecin.pl.ico',
    ],
    'bitly.png' => [
        'https://logo.clearbit.com/bitly.com',
        'https://icons.duckduckgo.com/ip3/bitly.com.ico',
    ],
    'gemini.png' => [
        'https://www.google.com/s2/favicons?domain=gemini.google.com&sz=128',
        'https://logo.clearbit.com/deepmind.google',
        'https://icons.duckduckgo.com/ip3/gemini.google.com.ico',
    ],
    'przelewy24.png' => [
        'https://logo.clearbit.com/przelewy24.pl',
        'https://icons.duckduckgo.com/ip3/przelewy24.pl.ico',
    ],
    'psota5mcs.png' => [
        'https://logo.clearbit.com/p5.edu.pl',
        'https://icons.duckduckgo.com/ip3/mcs.p5.edu.pl.ico',
    ],
    'psota5.png' => [
        'https://logo.clearbit.com/p5.edu.pl',
        'https://icons.duckduckgo.com/ip3/ans.p5.edu.pl.ico',
    ],
    'sroka.png' => [
        'https://icons.duckduckgo.com/ip3/sroka.host884922.xce.pl.ico',
        'https://www.google.com/s2/favicons?domain=sroka.host884922.xce.pl&sz=128',
    ],
    // dodatkowe słabe
    'claude.png' => [
        'https://logo.clearbit.com/anthropic.com',
        'https://icons.duckduckgo.com/ip3/claude.ai.ico',
    ],
    'chatgpt.png' => [
        'https://logo.clearbit.com/openai.com',
        'https://icons.duckduckgo.com/ip3/chatgpt.com.ico',
    ],
];

// ── Helpers ───────────────────────────────────────────────────────────────────

function fetchUrl(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; icon-fetcher/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($data && $code === 200 && strlen($data) > 200) ? $data : null;
}

function processImage(string $data): ?\GdImage {
    $src = @imagecreatefromstring($data);
    if (!$src) return null;
    imagealphablending($src, false);
    imagesavealpha($src, true);

    $sw = imagesx($src); $sh = imagesy($src);
    $size = min($sw, $sh);
    $sx = (int)(($sw - $size) / 2);
    $sy = (int)(($sh - $size) / 2);

    $dst = imagecreatetruecolor(50, 50);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $clear = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $clear);
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, 50, 50, $size, $size);
    imagedestroy($src);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    return $dst;
}

function opaquePercent(\GdImage $img): float {
    $w = imagesx($img); $h = imagesy($img); $op = 0;
    for ($y = 0; $y < $h; $y++)
        for ($x = 0; $x < $w; $x++)
            if ((($imagecolorat = imagecolorat($img,$x,$y)) >> 24 & 0x7F) < 20) $op++;
    return round($op / ($w * $h) * 100, 1);
}

// ── Usuń tło (flood-fill z narożników) ────────────────────────────────────────
function removeBg(\GdImage $img, float $tol = 55): \GdImage {
    $w = imagesx($img); $h = imagesy($img);
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopy($dst, $img, 0, 0, 0, 0, $w, $h);
    imagealphablending($dst, false);

    $corners = [
        imagecolorsforindex($img, imagecolorat($img, 0, 0)),
        imagecolorsforindex($img, imagecolorat($img, $w-1, 0)),
        imagecolorsforindex($img, imagecolorat($img, 0, $h-1)),
        imagecolorsforindex($img, imagecolorat($img, $w-1, $h-1)),
    ];
    usort($corners, fn($a,$b)=>($b['red']+$b['green']+$b['blue'])<=>($a['red']+$a['green']+$a['blue']));
    [$bgR,$bgG,$bgB] = [$corners[0]['red'],$corners[0]['green'],$corners[0]['blue']];

    $visited = array_fill(0, $w*$h, false);
    $stack   = [[0,0],[$w-1,0],[0,$h-1],[$w-1,$h-1]];
    while ($stack) {
        [$x,$y] = array_pop($stack);
        if ($x<0||$x>=$w||$y<0||$y>=$h) continue;
        $idx=$y*$w+$x; if ($visited[$idx]) continue; $visited[$idx]=true;
        $rgba = imagecolorsforindex($dst, imagecolorat($dst,$x,$y));
        if ($rgba['alpha']>=100) continue;
        $dist = sqrt(($rgba['red']-$bgR)**2+($rgba['green']-$bgG)**2+($rgba['blue']-$bgB)**2);
        if ($dist<=$tol) {
            imagesetpixel($dst,$x,$y,imagecolorallocatealpha($dst,0,0,0,127));
            $stack[]=[$x+1,$y];$stack[]=[$x-1,$y];$stack[]=[$x,$y+1];$stack[]=[$x,$y-1];
        }
    }
    return $dst;
}

// ── Główna pętla ──────────────────────────────────────────────────────────────
$isCli = php_sapi_name() === 'cli';
$nl    = $isCli ? "\n" : "<br>\n";

echo "=== Wymiana ikon ===$nl$nl";

foreach ($replacements as $filename => $urls) {
    $path = $ICONS_DIR . $filename;

    // sprawdź obecną widoczność
    $current = null;
    if (file_exists($path)) {
        $tmp = @imagecreatefrompng($path);
        if ($tmp) { $before = opaquePercent($tmp); imagedestroy($tmp); }
        else $before = 0;
    } else {
        $before = 0;
    }

    $downloaded = false;
    foreach ($urls as $url) {
        echo "  [$filename] próba: $url ... $nl";
        $data = fetchUrl($url);
        if (!$data) { echo "    → brak odpowiedzi$nl"; continue; }

        $img = processImage($data);
        if (!$img) { echo "    → nie można przetworzyć obrazu$nl"; continue; }

        // usuń tło
        $clean = removeBg($img);
        imagedestroy($img);

        $after = opaquePercent($clean);

        // zapisz tylko jeśli poprawa jest znacząca
        if ($after < $before && $before > 5) {
            echo "    → gorsza jakość ($after% vs $before%), pomijam$nl";
            imagedestroy($clean);
            continue;
        }

        imagepng($clean, $path, 9);
        imagedestroy($clean);
        echo "    → OK! opaque: {$before}% → {$after}%$nl";
        $downloaded = true;
        break;
    }

    if (!$downloaded) {
        echo "  [$filename] BRAK LEPSZEGO ŹRÓDŁA — zostawiam oryginał$nl";
    }
    echo $nl;
}

echo "=== Gotowe ===$nl";
