<?php
/** Publiczna tablica kafelków (stub: public_html/index.php). Wymaga PIN-u albo sesji admina; ikony idą przez icon.php. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
pin_require_access();
$data_dir    = DATA_DIR;
$kafelki_raw = @file_get_contents($data_dir . '/kafelki.config');
$menu_raw    = @file_get_contents($data_dir . '/menu.config');
$settings_raw= @file_get_contents($data_dir . '/settings.config');
$kafelki_arr = $kafelki_raw  ? (json_decode($kafelki_raw,  true) ?: []) : [];
$menu_arr    = $menu_raw     ? (json_decode($menu_raw,     true) ?: []) : [];
$settings    = $settings_raw ? (json_decode($settings_raw, true) ?: []) : [];
$init_cols   = (int)($settings['cols'] ?? 10);
if ($init_cols < 2) $init_cols = 2;
$icons_v = filemtime(ICONS_DIR) ?: time();

$tiles_by_id = [];
foreach ($kafelki_arr as $k) $tiles_by_id[$k['id']] = $k;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Galaktyka</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="stylesheet" href="assets/fonts.css">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #ffffff; font-family: 'Inter', 'Segoe UI', Arial, sans-serif; min-height: 100vh; padding: 10px; }
/* Delikatna kratka w tle: jedno oczko = jedna komórka (80x88 + odstęp 6px = 86x94).
   Linie leżą w środku odstępów: pierwsza komórka zaczyna się na 14px (padding body 10 + .grid-wrap 4),
   więc przesunięcie to 14 - 3 = 11px. Zmiana rozmiaru .cell/.tile lub gap wymaga zmiany tych liczb. */
body {
  background-image:
    linear-gradient(to right,  #eceff3 1px, transparent 1px),
    linear-gradient(to bottom, #eceff3 1px, transparent 1px);
  background-size: 86px 94px;
  background-position: 11px 11px;
}
.grid-wrap { padding: 4px; }
#mainGrid { display: flex; flex-direction: column; gap: 6px; width: 100%; }
.grid-row  { display: flex; flex-wrap: wrap; gap: 6px; }
.cell { width: 80px; height: 88px; }
.tile {
  display: flex; flex-direction: column; align-items: center;
  justify-content: flex-start; padding-top: 8px;
  width: 80px; height: 88px; background: #fff; border-radius: 10px;
  text-decoration: none; color: #444; font-size: 0.65rem; text-align: center;
  transition: background 0.15s, transform 0.1s, box-shadow 0.15s; cursor: pointer; overflow: hidden;
  box-shadow: 0 1px 4px rgba(0,0,0,0.10);
}
.tile:hover { background: #e8f0fe; transform: scale(1.06); color: #1a73e8; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.tile img { width: 50px; height: 50px; object-fit: contain; border-radius: 6px; margin-bottom: 5px; pointer-events: none; }
.tile .label { width: 76px; overflow: hidden; white-space: normal; word-break: break-word; padding: 0 3px; line-height: 1.2; }
.tile-header {
  background: #dceefb; border-left: 4px solid #1a73e8; color: #1a73e8;
  font-weight: 700; font-size: 0.72rem; cursor: default;
  justify-content: center; padding-top: 0;
}
.tile-header:hover { background: #dceefb; transform: none; color: #1a73e8; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.empty-cell { width: 80px; height: 88px; background: rgba(0,0,0,0.03); border-radius: 10px; border: 1px dashed rgba(0,0,0,0.08); }
.tile-separator { background: repeating-linear-gradient(135deg,#f0f0f0 0,#f0f0f0 5px,#e0e0e0 5px,#e0e0e0 10px); border: 1px dashed #bbb; cursor: default; }
.tile-separator:hover { background: repeating-linear-gradient(135deg,#f0f0f0 0,#f0f0f0 5px,#e0e0e0 5px,#e0e0e0 10px) !important; transform: none !important; box-shadow: 0 1px 4px rgba(0,0,0,.10) !important; }
</style>
</head>
<body>
<div class="grid-wrap">
  <div id="mainGrid"></div>
</div>

<script>
var TILES   = <?= json_encode($tiles_by_id, JSON_UNESCAPED_UNICODE) ?>;
var MENU    = <?= json_encode($menu_arr,    JSON_UNESCAPED_UNICODE) ?>;
var COLS    = <?= $init_cols ?>;
var ICONS_V = <?= $icons_v ?>;

function buildGrid() {
  var g = {}, maxRow = 0;
  MENU.forEach(function(p) {
    if (!g[p.rzad]) g[p.rzad] = {};
    g[p.rzad][p.kolumna] = p.id;
    if (p.rzad > maxRow) maxRow = p.rzad;
  });
  return { g: g, maxRow: maxRow };
}

function renderGrid() {
  var data = buildGrid();
  var container = document.getElementById('mainGrid');
  container.style.maxWidth = (COLS * 86 - 6) + 'px';
  var html = '';
  for (var r = 1; r <= data.maxRow; r++) {
    var rowHtml = '';
    for (var c = 1; c <= COLS; c++) {
      var id   = data.g[r] && data.g[r][c];
      var tile = id ? TILES[id] : null;
      if (!tile) continue; // pomiń puste
      if (tile.typ === 'naglowek') {
        rowHtml += '<div class="tile tile-header" title="' + esc(tile.tytul) + '">'
                 + '<span class="label">' + esc(tile.tytul) + '</span>'
                 + '</div>';
      } else if (tile.typ === 'separator') {
        rowHtml += '<div class="tile tile-separator"></div>';
      } else {
        rowHtml += '<a class="tile" href="' + esc(tile.url) + '" target="_blank" title="' + esc(tile.url) + '">'
                 + '<img src="icon.php?f=' + encodeURIComponent(tile.ikona) + '&v=' + ICONS_V + '" alt="" onerror="this.style.opacity=\'0.2\'">'
                 + '<span class="label">' + esc(tile.tytul) + '</span>'
                 + '</a>';
      }
    }
    if (rowHtml) html += '<div class="grid-row">' + rowHtml + '</div>';
  }
  container.innerHTML = html;
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

renderGrid();
</script>
</body>
</html>
