<?php
// check_icons.php — diagnozuje "duchowatość" ikon po usunięciu tła
$dir = __DIR__ . '/data/icons/';
$files = glob($dir . '*.png');
sort($files);

$results = [];
foreach ($files as $file) {
    $img = @imagecreatefrompng($file);
    if (!$img) { $results[] = ['name'=>basename($file),'score'=>-1,'reason'=>'błąd odczytu']; continue; }
    imagealphablending($img, false);
    $w = imagesx($img); $h = imagesy($img);
    $total = $w * $h;
    $opaque = 0; $semiopaque = 0; $totalAlpha = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($img, $x, $y);
            $a = ($c >> 24) & 0x7F; // 0=pełny kolor, 127=przezroczysty
            $totalAlpha += $a;
            if ($a < 20)  $opaque++;
            if ($a < 60)  $semiopaque++;
        }
    }
    imagedestroy($img);
    $pctOpaque = round($opaque / $total * 100, 1);
    $pctSemi   = round($semiopaque / $total * 100, 1);
    $avgAlpha  = round($totalAlpha / $total, 1);
    $results[] = [
        'name'      => basename($file),
        'pctOpaque' => $pctOpaque,
        'pctSemi'   => $pctSemi,
        'avgAlpha'  => $avgAlpha,
        'ghost'     => $pctOpaque < 15,
    ];
    imagedestroy($img) ; // already destroyed, harmless
}

// sortuj od najgorszych
usort($results, fn($a,$b) => $a['pctOpaque'] <=> $b['pctOpaque']);

$isCli = php_sapi_name() === 'cli';
$nl = $isCli ? "\n" : "<br>";

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Icon check</title>"
       . "<style>body{font-family:monospace;background:#111;color:#eee;padding:20px}"
       . "table{border-collapse:collapse;width:100%}th,td{padding:4px 8px;text-align:left;border-bottom:1px solid #333}"
       . ".bad{color:#f66}.ok{color:#6f6}.mid{color:#fa0}"
       . ".preview{width:50px;height:50px;background:#555;border-radius:4px;display:inline-block;vertical-align:middle}"
       . "</style></head><body>";
    echo "<h2 style='color:#7ec8e3'>Analiza ikon — widoczność po usunięciu tła</h2>";
    echo "<table><tr><th>Ikona</th><th>Podgląd</th><th>% opaque</th><th>% semi</th><th>Ocena</th></tr>";
    foreach ($results as $r) {
        if ($r['pctOpaque'] < 0) continue;
        $cls = $r['pctOpaque'] < 10 ? 'bad' : ($r['pctOpaque'] < 20 ? 'mid' : 'ok');
        $label = $r['pctOpaque'] < 10 ? '⚠ DUCH' : ($r['pctOpaque'] < 20 ? '~ słaby' : '✓ ok');
        echo "<tr class='$cls'>"
           . "<td>{$r['name']}</td>"
           . "<td><img src='data/icons/{$r['name']}?v=" . time() . "' style='width:50px;height:50px;background:#555;border-radius:4px'></td>"
           . "<td>{$r['pctOpaque']}%</td>"
           . "<td>{$r['pctSemi']}%</td>"
           . "<td>$label</td></tr>";
    }
    echo "</table></body></html>";
} else {
    foreach ($results as $r) {
        if ($r['pctOpaque'] < 0) continue;
        $flag = $r['pctOpaque'] < 10 ? ' <<< DUCH' : ($r['pctOpaque'] < 20 ? ' ~ słaby' : '');
        printf("%-30s opaque:%5.1f%%  semi:%5.1f%%%s\n", $r['name'], $r['pctOpaque'], $r['pctSemi'], $flag);
    }
}
