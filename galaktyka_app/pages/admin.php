<?php
/** Panel admina (stub: public_html/admin.php). Sesję, DATA_DIR i ICONS_DIR daje bootstrap.php. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once APP_DIR . '/auth.php';
define('KAFELKI',     DATA_DIR . '/kafelki.config');
define('MENU',        DATA_DIR . '/menu.config');
define('SETTINGS',    DATA_DIR . '/settings.config');
define('UNDO',        DATA_DIR . '/undo.json');

// ─── Helpers ─────────────────────────────────────────────────────────────────

function load_json(string $file): array {
    $raw = @file_get_contents($file);
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

function save_json(string $file, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $fh   = fopen($file, 'c');
    if ($fh && flock($fh, LOCK_EX)) {
        ftruncate($fh, 0); fwrite($fh, $json);
        flock($fh, LOCK_UN); fclose($fh);
    }
}

/**
 * Wczytaj plik JSON z blokadą LOCK_SH (shared read lock).
 * Gwarantuje spójny odczyt gdy save_json trzyma LOCK_EX.
 */
function load_json_locked(string $file): array {
    $fh = @fopen($file, 'r');
    if (!$fh) return [];
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

/**
 * Wykonaj modyfikację pliku JSON atomowo: odczyt z LOCK_EX → zmiana → zapis.
 * Callback otrzymuje bieżącą tablicę i zwraca zmodyfikowaną.
 * Zwraca finalną tablicę po zapisie.
 */
function update_json_atomic(string $file, callable $fn): array {
    $fh = fopen($file, 'c+');
    if (!$fh) return [];
    flock($fh, LOCK_EX);
    $raw  = stream_get_contents($fh);
    $data = $raw ? (json_decode($raw, true) ?: []) : [];
    $data = $fn($data);
    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $data;
}

function save_undo_snapshot(): void {
    $snap = [
        'kafelki' => load_json_locked(KAFELKI),
        'menu'    => load_json_locked(MENU),
    ];
    save_json(UNDO, $snap);
}

function slugify(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'tile-' . time();
}

function make_unique_id(string $base, array $existing): string {
    $id = slugify($base);
    if (!in_array($id, $existing)) return $id;
    for ($i = 2; in_array("{$id}-{$i}", $existing); $i++);
    return "{$id}-{$i}";
}

function process_icon_upload(array $file, string $target = ''): array {
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/bmp'];
    if (!in_array($file['type'], $allowed)) return ['ok'=>false,'msg'=>'Niedozwolony format'];
    if ($file['size'] > 5 * 1024 * 1024)   return ['ok'=>false,'msg'=>'Plik za duży (max 5MB)'];
    if ($file['error'] !== UPLOAD_ERR_OK)   return ['ok'=>false,'msg'=>'Błąd uploadu #'.$file['error']];

    $src = @imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$src) return ['ok'=>false,'msg'=>'Nie można odczytać obrazu'];

    $sw   = imagesx($src); $sh = imagesy($src);
    $size = min($sw, $sh);
    $sx   = (int)(($sw - $size) / 2);
    $sy   = (int)(($sh - $size) / 2);

    $dst = imagecreatetruecolor(50, 50);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagefilledrectangle($dst, 0, 0, 50, 50, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, 50, 50, $size, $size);
    imagedestroy($src);
    imagealphablending($dst, false); imagesavealpha($dst, true);

    if ($target !== '') {
        $filename = preg_replace('/[^a-z0-9_\-.]/', '', $target);
        if (!preg_match('/\.png$/i', $filename)) $filename .= '.png';
    } else {
        $name = preg_replace('/[^a-z0-9_\-]/', '', strtolower(pathinfo($file['name'], PATHINFO_FILENAME))) ?: 'icon';
        $filename = $name . '.png';
        $path     = ICONS_DIR . $filename;
        for ($i = 1; file_exists($path); $i++) { $filename = $name.'-'.$i.'.png'; $path = ICONS_DIR.$filename; }
    }
    $path = ICONS_DIR . $filename;

    imagepng($dst, $path, 9); imagedestroy($dst);
    return ['ok'=>true,'filename'=>$filename];
}

// ─── PIN dostępu (zmiana PIN-u, zdjęcie blokady globalnej) ────────────────────

/** Obsługuje formularz "PIN dostępu" (zwykły POST z CSRF, nie AJAX). Zwraca [ok, komunikat] albo null, gdy to nie ten formularz. */
function admin_pin_handle(array $post): ?array {
    $action = $post['pin_action'] ?? '';
    if ($action === '') return null;
    if (!csrf_ok($post)) return [false, 'Sesja wygasła, spróbuj ponownie.'];
    if ($action === 'unblock') { pin_unblock_global(); return [true, 'Zdjęto blokadę PIN-u.']; }
    if ($action === 'change') {
        $new = (string)($post['new_pin'] ?? '');
        if (!pin_valid_format($new))                   return [false, 'PIN musi mieć dokładnie 4 cyfry.'];
        if ($new !== (string)($post['new_pin2'] ?? '')) return [false, 'PIN-y się różnią.'];
        pin_change($new);
        return [true, 'PIN zmieniony. Wszystkie dotychczasowe ciasteczka PIN przestały działać.'];
    }
    return null;
}

// ─── AJAX Handler ─────────────────────────────────────────────────────────────

$is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || isset($_POST['ajax']);

if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['admin'])) { echo json_encode(['ok'=>false,'msg'=>'Brak dostępu']); exit; }

    $action  = $_POST['action'] ?? '';
    $kafelki = load_json(KAFELKI);
    $menu    = load_json(MENU);
    $settings= load_json(SETTINGS);

    switch ($action) {

        // ── Dodaj ──────────────────────────────────────────────────────────
        case 'add':
            $tytul = trim($_POST['tytul'] ?? '');
            $typ   = trim($_POST['typ']   ?? '');
            $url   = trim($_POST['url']   ?? '');
            $ikona = trim($_POST['ikona'] ?? 'noicon.png');
            if (!$tytul && $typ !== 'separator') { echo json_encode(['ok'=>false,'msg'=>'Brak tytułu']); exit; }
            if (!$tytul && $typ === 'separator') $tytul = 'sep-' . time();
            if ($typ !== 'naglowek' && $typ !== 'separator' && !$url) { echo json_encode(['ok'=>false,'msg'=>'Brak URL']); exit; }
            save_undo_snapshot();
            if ($typ !== 'naglowek' && $typ !== 'separator' && !empty($_FILES['ikona_file']['name'])) {
                $up = process_icon_upload($_FILES['ikona_file']);
                if (!$up['ok']) { echo json_encode($up); exit; }
                $ikona = $up['filename'];
            }
            // Auto-placement: first row from top with free space, else new row
            $cols = (int)($settings['cols'] ?? 10);
            if ($cols < 2) $cols = 2;
            $row_cols = [];
            foreach ($menu as $p) {
                $r = (int)$p['rzad'];
                if (!isset($row_cols[$r])) $row_cols[$r] = [];
                $row_cols[$r][] = (int)$p['kolumna'];
            }
            $max_row = $row_cols ? max(array_keys($row_cols)) : 0;
            $rzad = null; $kol = null;
            for ($r = 1; $r <= $max_row; $r++) {
                $used = $row_cols[$r] ?? [];
                if (count($used) < $cols) {
                    $rzad = $r; $kol = 1;
                    while (in_array($kol, $used)) $kol++;
                    break;
                }
            }
            if ($rzad === null) { $rzad = $max_row + 1; $kol = 1; }
            $existing_ids = array_column($kafelki, 'id');
            $id = make_unique_id($tytul, $existing_ids);
            $tile_data = ['id'=>$id,'tytul'=>$tytul,'ikona'=>$ikona,'url'=>$url];
            if ($typ === 'naglowek') $tile_data['typ'] = 'naglowek';
            elseif ($typ === 'separator') { $tile_data['typ'] = 'separator'; $tile_data['url'] = ''; $tile_data['ikona'] = ''; }
            $kafelki[] = $tile_data;
            save_json(KAFELKI, $kafelki);
            $menu[] = ['id'=>$id,'rzad'=>$rzad,'kolumna'=>$kol];
            save_json(MENU, $menu);
            echo json_encode(['ok'=>true,'id'=>$id,'ikona'=>$ikona,'rzad'=>$rzad,'kolumna'=>$kol,'typ'=>$typ,'has_undo'=>true,'icons_v'=>filemtime(ICONS_DIR)]);
            break;

        // ── Edytuj ─────────────────────────────────────────────────────────
        case 'edit':
            $id    = trim($_POST['id']    ?? '');
            $tytul = trim($_POST['tytul'] ?? '');
            $typ   = trim($_POST['typ']   ?? '');
            $url   = trim($_POST['url']   ?? '');
            $ikona = trim($_POST['ikona'] ?? '');
            if (!$id || (!$tytul && $typ !== 'separator')) { echo json_encode(['ok'=>false,'msg'=>'Brak danych']); exit; }
            if ($typ !== 'naglowek' && $typ !== 'separator' && !$url) { echo json_encode(['ok'=>false,'msg'=>'Brak URL']); exit; }
            save_undo_snapshot();
            if ($typ !== 'naglowek' && $typ !== 'separator' && !empty($_FILES['ikona_file']['name'])) {
                $target = trim($_POST['ikona_target'] ?? '');
                $up = process_icon_upload($_FILES['ikona_file'], $target);
                if (!$up['ok']) { echo json_encode($up); exit; }
                $ikona = $up['filename'];
            }
            foreach ($kafelki as &$k) {
                if ($k['id'] === $id) {
                    $k['tytul'] = $tytul; $k['url'] = $url;
                    if ($ikona) $k['ikona'] = $ikona;
                    if ($typ === 'naglowek') $k['typ'] = 'naglowek';
                    elseif ($typ === 'separator') { $k['typ'] = 'separator'; $k['url'] = ''; $k['ikona'] = ''; }
                    else unset($k['typ']);
                    break;
                }
            }
            save_json(KAFELKI, $kafelki);
            $updated = null;
            foreach ($kafelki as $k) { if ($k['id']===$id) { $updated=$k; break; } }
            echo json_encode(['ok'=>true,'tile'=>$updated,'has_undo'=>true,'icons_v'=>filemtime(ICONS_DIR)]);
            break;

        // ── Usuń ───────────────────────────────────────────────────────────
        case 'delete':
            $id = trim($_POST['id'] ?? '');
            if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Brak ID']); exit; }
            save_undo_snapshot();
            $kafelki = array_values(array_filter($kafelki, fn($k)=>$k['id']!==$id));
            $menu    = array_values(array_filter($menu,    fn($p)=>$p['id']!==$id));
            save_json(KAFELKI, $kafelki); save_json(MENU, $menu);
            echo json_encode(['ok'=>true,'has_undo'=>true]);
            break;

        // ── Usuń rząd ──────────────────────────────────────────────────────
        case 'delete_row':
            $rzad = (int)($_POST['rzad'] ?? 0);
            if ($rzad < 1) { echo json_encode(['ok'=>false,'msg'=>'Brak rzędu']); exit; }
            save_undo_snapshot();
            $row_ids = [];
            foreach ($menu as $p) {
                if ((int)$p['rzad'] === $rzad) $row_ids[] = $p['id'];
            }
            $menu = array_values(array_filter($menu, fn($p) => (int)$p['rzad'] !== $rzad));
            foreach ($menu as &$p) {
                if ((int)$p['rzad'] > $rzad) $p['rzad']--;
            }
            unset($p);
            $kafelki = array_values(array_filter($kafelki, fn($k) => !in_array($k['id'], $row_ids)));
            save_json(KAFELKI, $kafelki); save_json(MENU, $menu);
            echo json_encode(['ok'=>true,'deleted_ids'=>$row_ids,'menu'=>$menu,'has_undo'=>true]);
            break;

        // ── Przesuń rząd ───────────────────────────────────────────────────
        case 'move_row':
            $rzad = (int)($_POST['rzad'] ?? 0);
            $dir  = trim($_POST['dir']  ?? '');
            if ($rzad < 1 || !in_array($dir, ['up','down'])) {
                echo json_encode(['ok'=>false,'msg'=>'Brak parametrów']); exit;
            }
            save_undo_snapshot();
            $rows_used = array_values(array_unique(array_map(fn($p) => (int)$p['rzad'], $menu)));
            sort($rows_used);
            $pos = array_search($rzad, $rows_used);
            if ($pos === false) { echo json_encode(['ok'=>false,'msg'=>'Rząd nie istnieje']); exit; }
            if ($dir === 'up') {
                if ($pos === 0) { echo json_encode(['ok'=>false,'msg'=>'Już na górze']); exit; }
                $other = $rows_used[$pos - 1];
            } else {
                if ($pos === count($rows_used) - 1) { echo json_encode(['ok'=>false,'msg'=>'Już na dole']); exit; }
                $other = $rows_used[$pos + 1];
            }
            $tmp = -1;
            foreach ($menu as &$p) {
                $r = (int)$p['rzad'];
                if ($r === $rzad)  $p['rzad'] = $tmp;
                elseif ($r === $other) $p['rzad'] = $rzad;
            }
            foreach ($menu as &$p) {
                if ((int)$p['rzad'] === $tmp) $p['rzad'] = $other;
            }
            unset($p);
            save_json(MENU, $menu);
            echo json_encode(['ok'=>true,'menu'=>$menu,'has_undo'=>true]);
            break;

        // ── Przesuń ────────────────────────────────────────────────────────
        case 'move':
            $src_id   = trim($_POST['src_id']       ?? '');
            $dst_rzad = (int)($_POST['dst_rzad']    ?? 0);
            $dst_kol  = (int)($_POST['dst_kolumna'] ?? 0);
            if (!$src_id || $dst_rzad < 1 || $dst_kol < 1) {
                echo json_encode(['ok'=>false,'msg'=>'Brak parametrów']); exit;
            }
            save_undo_snapshot();

            // Wykonaj atomowo: odczyt MENU → modyfikacja → zapis (jeden LOCK_EX)
            $final_menu = update_json_atomic(MENU, function(array $menu) use ($src_id, $dst_rzad, $dst_kol): array {

                // Znajdź aktualną pozycję kafelka źródłowego (czytamy ze świeżego stanu)
                $src_rzad = 0;
                foreach ($menu as $p) {
                    if ($p['id'] === $src_id) { $src_rzad = (int)$p['rzad']; break; }
                }
                if ($src_rzad < 1) return $menu; // kafelek nie istnieje — nie zmieniaj nic

                // Jeśli przenoszono na tę samą pozycję — nic nie rób
                $src_kol = 0;
                foreach ($menu as $p) {
                    if ($p['id'] === $src_id) { $src_kol = (int)$p['kolumna']; break; }
                }
                if ($src_rzad === $dst_rzad && $src_kol === $dst_kol) return $menu;

                if ($src_rzad === $dst_rzad) {
                    // ── Reorder w tym samym rzędzie ──
                    $row = []; $rest = [];
                    foreach ($menu as $p) {
                        if ((int)$p['rzad'] === $src_rzad) $row[] = $p; else $rest[] = $p;
                    }
                    usort($row, fn($a,$b) => (int)$a['kolumna'] <=> (int)$b['kolumna']);

                    $item = null;
                    $row  = array_values(array_filter($row, function($p) use($src_id, &$item) {
                        if ($p['id'] === $src_id) { $item = $p; return false; }
                        return true;
                    }));
                    if ($item === null) return $menu; // null-guard: kafelek nie znaleziony w rzędzie

                    $new = []; $ins = false;
                    foreach ($row as $p) {
                        if (!$ins && (int)$p['kolumna'] >= $dst_kol) { $new[] = $item; $ins = true; }
                        $new[] = $p;
                    }
                    if (!$ins) $new[] = $item;
                    foreach ($new as $i => &$p) $p['kolumna'] = $i + 1;
                    unset($p);

                    return array_merge($rest, $new);

                } else {
                    // ── Przesuń między rzędami ──
                    $sr = []; $dr = []; $rest = [];
                    foreach ($menu as $p) {
                        $r = (int)$p['rzad'];
                        if ($r === $src_rzad)      $sr[]   = $p;
                        elseif ($r === $dst_rzad)  $dr[]   = $p;
                        else                       $rest[] = $p;
                    }
                    usort($sr, fn($a,$b) => (int)$a['kolumna'] <=> (int)$b['kolumna']);

                    $item = null;
                    $sr   = array_values(array_filter($sr, function($p) use($src_id, &$item) {
                        if ($p['id'] === $src_id) { $item = $p; return false; }
                        return true;
                    }));
                    if ($item === null) return $menu; // null-guard

                    foreach ($sr as $i => &$p) $p['kolumna'] = $i + 1;
                    unset($p);

                    usort($dr, fn($a,$b) => (int)$a['kolumna'] <=> (int)$b['kolumna']);
                    $item['rzad'] = $dst_rzad;

                    $new = []; $ins = false;
                    foreach ($dr as $p) {
                        if (!$ins && (int)$p['kolumna'] >= $dst_kol) { $new[] = $item; $ins = true; }
                        $new[] = $p;
                    }
                    if (!$ins) $new[] = $item;
                    foreach ($new as $i => &$p) $p['kolumna'] = $i + 1;
                    unset($p);

                    return array_merge($rest, $sr, $new);
                }
            });

            echo json_encode(['ok'=>true,'menu'=>$final_menu,'has_undo'=>true]);
            break;

        // ── Zapisz ustawienia (cols) ───────────────────────────────────────
        case 'save_settings':
            $cols = (int)($_POST['cols'] ?? 10);
            if ($cols < 1)  $cols = 1;
            if ($cols > 12) $cols = 12;
            $settings['cols'] = $cols;
            save_json(SETTINGS, $settings);
            echo json_encode(['ok'=>true,'cols'=>$cols]);
            break;

        // ── Lista ikon ─────────────────────────────────────────────────────
        case 'list_icons':
            $icons = [];
            if (is_dir(ICONS_DIR)) {
                $icons = array_values(array_filter(
                    array_map(fn($f)=>basename($f), glob(ICONS_DIR.'*.png') ?: [])
                ));
                sort($icons);
            }
            echo json_encode(['ok'=>true,'icons'=>$icons]);
            break;

        // ── Cofnij ─────────────────────────────────────────────────────────
        case 'undo':
            $snap = load_json(UNDO);
            if (empty($snap)) { echo json_encode(['ok'=>false,'msg'=>'Brak operacji do cofnięcia']); exit; }
            save_json(KAFELKI, $snap['kafelki'] ?? []);
            save_json(MENU,    $snap['menu']    ?? []);
            @unlink(UNDO);
            $tb = [];
            foreach ($snap['kafelki'] ?? [] as $k) $tb[$k['id']] = $k;
            echo json_encode(['ok'=>true,'tiles'=>$tb,'menu'=>$snap['menu']??[],'has_undo'=>false]);
            break;

        default:
            echo json_encode(['ok'=>false,'msg'=>'Nieznana akcja']);
    }
    exit;
}

// ─── Logowanie ────────────────────────────────────────────────────────────────

$auth_view = isset($_SESSION['admin']) ? null : auth_handle($_POST, $_GET); // loguje/resetuje hasło; przy sukcesie przekierowuje
if (isset($_POST['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

$logged_in   = isset($_SESSION['admin']);
$pin_msg     = $logged_in ? admin_pin_handle($_POST) : null; // [ok(bool), tekst] po zmianie PIN-u / zdjęciu blokady
$kafelki_arr = load_json(KAFELKI);
$menu_arr    = load_json(MENU);
$settings    = load_json(SETTINGS);
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
<title>Admin — Galaktyka</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="stylesheet" href="assets/cropper.min.css">
<link rel="stylesheet" href="assets/fonts.css">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #ffffff; font-family: 'Inter', 'Segoe UI', Arial, sans-serif; color: #333; min-height: 100vh; }

/* ── Header ── */
header {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;
  padding: 12px 20px; background: #f8f9fa; border-bottom: 2px solid #dee2e6;
}
header h1 { color: #1a73e8; font-size: 1.1rem; letter-spacing: 2px; }
.header-btns { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }

button, .btn {
  padding: 6px 12px; border: none; border-radius: 6px; cursor: pointer;
  font-size: 0.78rem; font-weight: 600; transition: opacity 0.15s;
}
button:hover { opacity: 0.85; }
.btn-primary   { background: #1a73e8; color: #fff; }
.btn-success   { background: #2e7d32; color: #fff; }
.btn-danger    { background: #b71c1c; color: #fff; }
.btn-secondary { background: #e0e0e0; color: #555; }
.btn-move      { background: #6a1b9a; color: #fff; }
.btn-move.active { background: #ab47bc; box-shadow: 0 0 0 2px #fff; }

/* Cols control */
.cols-ctrl { display: flex; align-items: center; gap: 4px; background:#e8f0fe; border-radius:6px; padding:3px 8px; }
.cols-ctrl span { color:#1a73e8; font-size:0.8rem; min-width:24px; text-align:center; font-weight:700; }
.cols-ctrl button { padding:2px 8px; font-size:0.9rem; background:#fff; color:#1a73e8; border-radius:4px; }

/* ── Login ── */
.login-box { max-width:340px; margin:80px auto; background:#f8f9fa; border-radius:12px; padding:32px; box-shadow:0 4px 24px rgba(0,0,0,.12); }
.login-box h2 { color:#1a73e8; margin-bottom:20px; text-align:center; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; margin-bottom:5px; color:#666; font-size:0.85rem; }
.form-group input, .form-group select { width:100%; padding:8px 12px; background:#fff; border:1px solid #ccc; border-radius:6px; color:#333; font-size:0.9rem; }
.form-group input:focus { outline:2px solid #1a73e8; }
.err-msg { color:#d32f2f; font-size:0.85rem; margin-bottom:12px; }

/* ── Grid ── */
.grid-wrap { padding: 14px 20px; }
#mainGrid  { display: flex; flex-direction: column; gap: 6px; width: 100%; }
.grid-row  { display: flex; flex-wrap: wrap; gap: 6px; }
.cell { width:80px; height:88px; position:relative; }
.tile {
  display:flex; flex-direction:column; align-items:center; justify-content:flex-start;
  padding-top:8px; width:80px; height:88px; background:#ffffff; border-radius:10px;
  color:#444; font-size:0.62rem; text-align:center; cursor:pointer; overflow:hidden;
  border:2px solid transparent; box-shadow:0 1px 4px rgba(0,0,0,.10);
  transition:border-color 0.15s,background 0.15s; user-select:none;
}
.tile:hover { background:#e8f0fe; }
.tile img { width:50px; height:50px; object-fit:contain; border-radius:6px; margin-bottom:4px; pointer-events:none; }
.tile .label { width:76px; overflow:hidden; white-space:normal; word-break:break-word; padding:0 3px; line-height:1.2; }
.tile-header {
  background:#dceefb; border-left:4px solid #1a73e8; color:#1a73e8;
  font-weight:700; font-size:0.72rem; cursor:default;
  justify-content:center; padding-top:0;
}
.tile-header:hover { background:#dceefb; }
.tile.selected { border-color:#ab47bc; background:#f3e5f5; }
.tile.move-mode { border-color:rgba(102,187,106,0.3); cursor:crosshair; }
.tile.move-mode:hover { border-color:#66bb6a; background:#e8f5e9; }
.empty-cell { width:80px; height:88px; background:rgba(0,0,0,.02); border:1px dashed rgba(0,0,0,.12); border-radius:10px; }
.empty-cell.move-mode { border-color:rgba(102,187,106,0.3); cursor:crosshair; }
.empty-cell.move-mode:hover { border-color:#66bb6a; background:rgba(102,187,106,0.1); }

/* Separator tile */
.tile-separator { background:repeating-linear-gradient(135deg,#f0f0f0 0,#f0f0f0 5px,#e0e0e0 5px,#e0e0e0 10px); border:1px dashed #bbb !important; cursor:default; }
.tile-separator:hover { background:repeating-linear-gradient(135deg,#f0f0f0 0,#f0f0f0 5px,#e0e0e0 5px,#e0e0e0 10px) !important; transform:none !important; box-shadow:0 1px 4px rgba(0,0,0,.10) !important; color:inherit !important; }

/* Row controls */
.row-ctrl { display:flex; flex-direction:column; justify-content:center; align-items:center; gap:3px; padding:0 5px; opacity:0; transition:opacity 0.15s; flex-shrink:0; }
.grid-row:hover .row-ctrl { opacity:1; }
.row-ctrl button { width:22px; height:22px; padding:0; font-size:0.78rem; border-radius:4px; line-height:1; border:1px solid transparent; }
.btn-del-row { background:#ffebee !important; color:#c62828 !important; border-color:#ef9a9a !important; }
.btn-del-row:hover { background:#ef9a9a !important; }
.btn-mv-row  { background:#e3f2fd !important; color:#1565c0 !important; border-color:#90caf9 !important; }
.btn-mv-row:hover  { background:#90caf9 !important; }

/* Przyciski na kafelku */
.tile-actions { position:absolute; top:2px; right:2px; display:none; gap:2px; z-index:10; }
.cell:hover .tile-actions { display:flex; }
.tile-actions button { width:18px; height:18px; padding:0; font-size:0.55rem; border-radius:3px; line-height:1; }

/* ── Pasek info (move) ── */
.info-bar { background:#e8f0fe; border-radius:6px; padding:7px 14px; margin:8px 20px 0;
  font-size:0.8rem; color:#1a73e8; display:none; }

/* ── Modal ── */
.overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; }
.overlay.show { display:flex; }
.modal { background:#ffffff; border-radius:12px; padding:28px; min-width:360px; max-width:92vw; box-shadow:0 8px 32px rgba(0,0,0,.2); position:relative; }
.modal h2 { color:#1a73e8; margin-bottom:20px; font-size:1.05rem; }
.modal-close { position:absolute; top:12px; right:14px; background:none; border:none; color:#999; font-size:1.3rem; cursor:pointer; }
.modal-close:hover { color:#333; }
.modal .actions { display:flex; gap:8px; margin-top:20px; justify-content:flex-end; }
.icon-preview { width:50px; height:50px; object-fit:contain; border-radius:6px; background:#f0f2f5; margin:6px 0; }
.icon-list-wrap { max-height:140px; overflow-y:auto; display:flex; flex-wrap:wrap; gap:4px; margin-top:6px; background:#f8f9fa; border-radius:6px; padding:6px; }
.icon-opt { width:36px; height:36px; object-fit:contain; border-radius:4px; border:2px solid transparent; cursor:pointer; background:#fff; }
.icon-opt:hover, .icon-opt.sel { border-color:#1a73e8; }
.ok-msg { background:#e6f4ea; color:#137333; border-radius:6px; padding:8px 12px; margin-bottom:12px; font-size:0.85rem; }
</style>
</head>
<body>

<?php if (!$logged_in): ?>
<div class="login-box">
  <h2>⚙ Admin</h2>
  <?php if ($auth_view['err']): ?><div class="err-msg"><?= htmlspecialchars($auth_view['err']) ?></div><?php endif; ?>
  <?php if ($auth_view['info']): ?><div class="ok-msg"><?= htmlspecialchars($auth_view['info']) ?></div><?php endif; ?>
  <?php $csrf = '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; ?>

  <?php if ($auth_view['view'] === 'login'): ?>
  <form method="post">
    <?= $csrf ?>
    <div class="form-group"><label>E-mail</label><input type="email" name="email" autofocus autocomplete="username" required></div>
    <div class="form-group"><label>Hasło</label><input type="password" name="password" autocomplete="current-password" required></div>
    <input type="hidden" name="login" value="1">
    <button type="submit" class="btn btn-primary" style="width:100%;padding:10px">Zaloguj</button>
  </form>
  <div style="margin-top:14px;text-align:center"><a href="admin.php?a=forgot" style="color:#555;font-size:0.8rem">Nie pamiętam hasła</a></div>

  <?php elseif ($auth_view['view'] === 'forgot'): ?>
  <form method="post">
    <?= $csrf ?>
    <div class="form-group"><label>E-mail konta</label><input type="email" name="email" autofocus autocomplete="username" required></div>
    <button type="submit" class="btn btn-primary" style="width:100%;padding:10px">Wyślij link</button>
  </form>
  <div style="margin-top:14px;text-align:center"><a href="admin.php" style="color:#555;font-size:0.8rem">← Wróć do logowania</a></div>

  <?php elseif ($auth_view['view'] === 'reset'): ?>
  <form method="post" action="admin.php?a=reset">
    <?= $csrf ?>
    <input type="hidden" name="t" value="<?= htmlspecialchars($auth_view['token']) ?>">
    <div class="form-group"><label>Nowe hasło</label><input type="password" name="password" minlength="8" required autofocus autocomplete="new-password"></div>
    <div class="form-group"><label>Powtórz hasło</label><input type="password" name="password2" minlength="8" required autocomplete="new-password"></div>
    <button type="submit" class="btn btn-primary" style="width:100%;padding:10px">Zapisz hasło</button>
  </form>

  <?php else: /* reset_bad */ ?>
  <div class="err-msg">Link jest nieprawidłowy lub wygasł.</div>
  <div style="margin-top:14px;text-align:center"><a href="admin.php?a=forgot" style="color:#555;font-size:0.8rem">Poproś o nowy link</a></div>
  <?php endif; ?>

  <?php if ($auth_view['view'] === 'login'): ?><div style="margin-top:8px;text-align:center"><a href="index.php" style="color:#555;font-size:0.8rem">← Powrót</a></div><?php endif; ?>
</div>

<?php else: ?>
<header>
  <h1>★ GALAKTYKA — Admin</h1>
  <div class="header-btns">
    <!-- Kontrola kolumn -->
    <div class="cols-ctrl" title="Liczba kolumn siatki">
      <button onclick="changeCols(-1)">−</button>
      <span id="colsDisplay"><?= $init_cols ?></span>
      <button onclick="changeCols(+1)">+</button>
    </div>
    <button class="btn-success"   onclick="openAdd()">+ Dodaj</button>
    <button class="btn-move"      id="btnMove" onclick="toggleMove()">↔ Przesuń</button>
    <button class="btn-secondary" id="btnUndo" onclick="doUndo()" style="opacity:0.4" disabled>↩ Cofnij</button>
    <button class="btn-secondary" onclick="openModal('modalPin')">🔑 PIN</button>
    <a href="index.php" class="btn btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center">Widok</a>
    <form method="post" style="margin:0"><input type="hidden" name="logout" value="1"><button type="submit" class="btn-danger">Wyloguj</button></form>
  </div>
</header>

<div class="info-bar" id="moveInfo">
  Tryb przenoszenia: <strong id="moveStep">Kliknij kafelek źródłowy</strong>
  <button onclick="cancelMove()" style="margin-left:10px;background:#b71c1c;color:#fff;padding:2px 8px;border-radius:4px;border:none;cursor:pointer">Anuluj (Esc)</button>
</div>

<div class="grid-wrap">
  <div id="mainGrid"></div>
</div>

<!-- Modal: PIN dostępu -->
<div class="overlay<?= $pin_msg ? ' show' : '' ?>" id="modalPin">
<div class="modal">
  <button class="modal-close" onclick="closeModal('modalPin')">×</button>
  <h2>PIN dostępu do tablicy</h2>
  <?php if ($pin_msg): ?><div class="<?= $pin_msg[0] ? 'ok-msg' : 'err-msg' ?>"><?= htmlspecialchars($pin_msg[1]) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="pin_action" value="change">
    <div class="form-group"><label>Nowy PIN (4 cyfry)</label><input type="password" name="new_pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required></div>
    <div class="form-group"><label>Powtórz PIN</label><input type="password" name="new_pin2" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required></div>
    <div class="actions"><button type="submit" class="btn-primary">Zmień PIN</button></div>
  </form>
  <?php if ($blocked_min = pin_global_blocked_minutes()): ?>
  <form method="post" style="margin-top:16px">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="pin_action" value="unblock">
    <div class="err-msg">PIN jest zablokowany dla wszystkich jeszcze ok. <?= (int)$blocked_min ?> min (zbyt wiele błędnych prób).</div>
    <button type="submit" class="btn-danger">Zdejmij blokadę</button>
  </form>
  <?php endif; ?>
</div>
</div>

<!-- Modal: Dodaj/Edytuj -->
<div class="overlay" id="modalTile">
<div class="modal">
  <button class="modal-close" onclick="closeModal('modalTile')">×</button>
  <h2 id="modalTitle">Dodaj kafelek</h2>
  <div class="form-group">
    <label>Typ kafelka</label>
    <select id="fTileType" onchange="onTileTypeChange(this.value)" style="width:100%;padding:8px 12px;background:#fff;border:1px solid #ccc;border-radius:6px;color:#333;font-size:0.9rem">
      <option value="">Zwykły kafelek</option>
      <option value="naglowek">Nagłówek sekcji (bez ikony)</option>
      <option value="separator">Separator (wizualny odstęp)</option>
    </select>
  </div>
  <div class="form-group" id="grpTytul"><label>Tytuł *</label><input type="text" id="fTytul" placeholder="np. Gmail"></div>
  <div id="grpUrl"><div class="form-group"><label>URL *</label><input type="text" id="fUrl" placeholder="https://..."></div></div>
  <div id="grpPosition">
  <div class="form-group">
    <label>Pozycja — Rząd</label><input type="number" id="fRzad" min="1" value="1">
  </div>
  <div class="form-group">
    <label>Pozycja — Kolumna</label><input type="number" id="fKolumna" min="1" value="1">
  </div>
  </div>
  <div id="grpIcon">
  <div class="form-group">
    <label>Wgraj ikonę (JPG/PNG → auto-kadrowanie 50×50)</label>
    <input type="file" id="fIkonaFile" accept="image/*" onchange="previewUpload(this)">
    <img id="ikonaPreview" class="icon-preview" src="" alt="" style="display:none">
    <button type="button" id="btnCropExisting" class="btn-secondary" onclick="cropExistingIcon()" style="display:none;margin-top:4px;font-size:0.75rem">✂ Kadruj ikonę</button>
  </div>
  <div class="form-group">
    <label>…lub wybierz istniejącą:</label>
    <input type="text" id="fIkonaName" placeholder="np. gmail.png" oninput="filterIcons(this.value)">
    <div class="icon-list-wrap" id="iconList"></div>
  </div>
  </div>
  <div class="actions">
    <button class="btn-secondary" onclick="closeModal('modalTile')">Anuluj</button>
    <button class="btn-success" onclick="saveTile()" id="btnSave">Zapisz</button>
  </div>
  <input type="hidden" id="fEditId">
</div>
</div>

<!-- Modal: Crop -->
<div class="overlay" id="modalCrop">
<div class="modal" style="max-width:460px">
  <button class="modal-close" onclick="closeCrop()">×</button>
  <h2>Przytnij ikonę</h2>
  <div style="max-height:340px;overflow:hidden;background:#f0f2f5;border-radius:6px;margin-bottom:12px">
    <img id="cropImg" style="max-width:100%;display:block">
  </div>
  <div class="actions">
    <button class="btn-secondary" onclick="closeCrop()">Anuluj</button>
    <button class="btn-success" onclick="applyCrop()">Zastosuj kadr</button>
  </div>
</div>
</div>

<!-- Modal: Usuń -->
<div class="overlay" id="modalDelete">
<div class="modal" style="max-width:380px">
  <h2>Usuń kafelek</h2>
  <p style="margin-bottom:16px;color:#555">Usunąć: <strong id="deleteTytul" style="color:#333"></strong>?</p>
  <div class="actions">
    <button class="btn-secondary" onclick="closeModal('modalDelete')">Anuluj</button>
    <button class="btn-danger" onclick="doDelete()">Usuń</button>
  </div>
  <input type="hidden" id="deleteId">
</div>
</div>

<script src="assets/cropper.min.js"></script>
<script>
// ── Dane z PHP ─────────────────────────────────────────────────────────────
var TILES   = <?= json_encode($tiles_by_id, JSON_UNESCAPED_UNICODE) ?>;
var MENU    = <?= json_encode($menu_arr,    JSON_UNESCAPED_UNICODE) ?>;
var COLS    = <?= $init_cols ?>;
var hasUndo = <?= file_exists(UNDO) ? 'true' : 'false' ?>;
var ICONS_V = <?= $icons_v ?>;

// ── Stan ───────────────────────────────────────────────────────────────────
var moveMode    = false;
var moveSource  = null;
var movePending = false;
var allIcons    = [];
var croppedBlob        = null;
var cropperInst        = null;
var cropTargetFilename = null;

// ── Kolumny ────────────────────────────────────────────────────────────────
function changeCols(delta) {
  COLS = Math.max(1, Math.min(12, COLS + delta));
  document.getElementById('colsDisplay').textContent = COLS;
  renderGrid();
  // Zapisz w tle
  var fd = new FormData();
  fd.append('ajax','1'); fd.append('action','save_settings'); fd.append('cols', COLS);
  fetch('admin.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
}

// ── Rendering siatki ───────────────────────────────────────────────────────
function buildDataGrid() {
  var g = {}, maxRow = 0;
  MENU.forEach(function(p) {
    if (!g[p.rzad]) g[p.rzad] = {};
    g[p.rzad][p.kolumna] = p.id;
    if (p.rzad > maxRow) maxRow = p.rzad;
  });
  return { g: g, maxRow: maxRow };
}

function renderGrid() {
  var data = buildDataGrid();
  var maxRow = data.maxRow + 1; // +1 pusty rząd na nowe kafelki
  var container = document.getElementById('mainGrid');
  container.style.maxWidth = (COLS * 86 - 6) + 'px';
  container.innerHTML = '';

  for (var r = 1; r <= maxRow; r++) {
    var isExtraRow = (r === maxRow);
    // znajdź pierwszą kolumnę z kafelkiem
    var rowDiv = document.createElement('div');
    rowDiv.className = 'grid-row';
    var hasContent = false;
    for (var c = 1; c <= COLS; c++) {
      var id   = data.g[r] && data.g[r][c];
      var tile = id ? TILES[id] : null;
      // pomiń puste komórki poza trybem move (i poza dodatkowym rzędem)
      if (!tile && !moveMode && !isExtraRow) continue;
      rowDiv.appendChild(makeCell(r, c, id, tile));
      hasContent = true;
    }
    if (hasContent && !isExtraRow && !moveMode) rowDiv.appendChild(makeRowCtrl(r));
    if (hasContent) container.appendChild(rowDiv);
  }

  if (moveMode) applyMoveClasses();
  if (moveSource) {
    var sel = container.querySelector('.tile[data-id="' + moveSource.id + '"]');
    if (sel) sel.classList.add('selected');
  }
}

function makeCell(r, c, id, tile) {
  var cell = document.createElement('div');
  cell.className = 'cell';
  cell.dataset.row = r; cell.dataset.col = c;

  if (tile) {
    var d = document.createElement('div');
    d.className = 'tile' + (tile.typ === 'naglowek' ? ' tile-header' : tile.typ === 'separator' ? ' tile-separator' : '');
    d.dataset.id    = id;
    d.dataset.tytul = tile.tytul;
    d.dataset.url   = tile.url   || '';
    d.dataset.ikona = tile.ikona || '';
    d.onclick = (function(rr,cc){ return function(){ tileClick(this,rr,cc); }; })(r,c);
    if (tile.typ === 'naglowek') {
      d.innerHTML = '<span class="label">' + esc(tile.tytul) + '</span>';
    } else if (tile.typ === 'separator') {
      d.innerHTML = '';
    } else {
      d.innerHTML = '<img src="icon.php?f=' + encodeURIComponent(tile.ikona) + '&v=' + ICONS_V + '" alt="" onerror="this.style.opacity=\'0.15\'">'
                  + '<span class="label">' + esc(tile.tytul) + '</span>';
    }
    cell.appendChild(d);

    // Przyciski akcji
    var acts = document.createElement('div');
    acts.className = 'tile-actions';
    acts.innerHTML = '<button class="btn-secondary" title="Edytuj">✎</button>'
                   + '<button class="btn-danger" title="Usuń">✕</button>';
    acts.children[0].onclick = (function(tid){ return function(e){e.stopPropagation();openEdit(tid);}; })(id);
    acts.children[1].onclick = (function(tid,ttytul){ return function(e){e.stopPropagation();confirmDelete(tid,ttytul);}; })(id,tile.tytul);
    cell.appendChild(acts);
  } else {
    var em = document.createElement('div');
    em.className = 'empty-cell';
    em.onclick = (function(rr,cc){ return function(){ emptyCellClick(rr,cc); }; })(r,c);
    cell.appendChild(em);
  }
  return cell;
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Kontrolki wiersza ─────────────────────────────────────────────────────
function makeRowCtrl(rzad) {
  var ctrl = document.createElement('div');
  ctrl.className = 'row-ctrl';
  var btnUp  = document.createElement('button');
  btnUp.className  = 'btn-mv-row'; btnUp.title = 'Przesuń rząd w górę'; btnUp.textContent = '↑';
  btnUp.onclick = (function(r){ return function(e){ e.stopPropagation(); doMoveRow(r,'up'); }; })(rzad);
  var btnDel = document.createElement('button');
  btnDel.className = 'btn-del-row'; btnDel.title = 'Usuń cały rząd'; btnDel.textContent = '✕';
  btnDel.onclick = (function(r){ return function(e){ e.stopPropagation(); confirmDeleteRow(r); }; })(rzad);
  var btnDn  = document.createElement('button');
  btnDn.className  = 'btn-mv-row'; btnDn.title = 'Przesuń rząd w dół'; btnDn.textContent = '↓';
  btnDn.onclick = (function(r){ return function(e){ e.stopPropagation(); doMoveRow(r,'down'); }; })(rzad);
  ctrl.appendChild(btnUp);
  ctrl.appendChild(btnDel);
  ctrl.appendChild(btnDn);
  return ctrl;
}
function confirmDeleteRow(rzad) {
  if (!confirm('Usunąć cały rząd nr ' + rzad + '? Wszystkie kafelki w tym rzędzie zostaną usunięte.')) return;
  var fd = new FormData();
  fd.append('action','delete_row'); fd.append('rzad', rzad);
  api(fd).then(function(r) {
    if (!r.ok) { alert('Błąd: ' + (r.msg||'nieznany')); return; }
    r.deleted_ids.forEach(function(id){ delete TILES[id]; });
    MENU = r.menu;
    updateUndoBtn(r.has_undo);
    renderGrid();
  });
}
function doMoveRow(rzad, dir) {
  var fd = new FormData();
  fd.append('action','move_row'); fd.append('rzad', rzad); fd.append('dir', dir);
  api(fd).then(function(r) {
    if (!r.ok) { alert('Błąd: ' + (r.msg||'nieznany')); return; }
    MENU = r.menu;
    updateUndoBtn(r.has_undo);
    renderGrid();
  });
}

// ── AJAX helper ────────────────────────────────────────────────────────────
function api(fd) {
  fd.append('ajax','1');
  return fetch('admin.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json());
}

// ── Undo ───────────────────────────────────────────────────────────────────
function updateUndoBtn(state) {
  hasUndo = !!state;
  var btn = document.getElementById('btnUndo');
  if (!btn) return;
  btn.disabled = !hasUndo;
  btn.style.opacity = hasUndo ? '1' : '0.4';
}
function doUndo() {
  if (!hasUndo) return;
  var fd = new FormData(); fd.append('action','undo');
  api(fd).then(function(r) {
    if (!r.ok) { alert('Błąd cofania: ' + (r.msg||'nieznany')); return; }
    TILES = r.tiles;
    MENU  = r.menu;
    updateUndoBtn(r.has_undo);
    renderGrid();
  });
}

// ── Modalne ────────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.overlay').forEach(function(o) {
  o.addEventListener('click', function(e){ if(e.target===o) o.classList.remove('show'); });
});

// ── Ikony ──────────────────────────────────────────────────────────────────
function loadIconList(selected) {
  var fd = new FormData(); fd.append('action','list_icons');
  api(fd).then(function(r) {
    if (r.ok) { allIcons = r.icons; renderIconList('', selected); }
  });
}
function renderIconList(filter, selected) {
  var el = document.getElementById('iconList'); el.innerHTML = '';
  var f = filter.toLowerCase();
  allIcons.filter(function(n){ return !f || n.includes(f); }).forEach(function(name) {
    var img = document.createElement('img');
    img.src = 'icon.php?f=' + encodeURIComponent(name); img.title = name; img.className = 'icon-opt';
    if (name === (selected || document.getElementById('fIkonaName').value)) img.classList.add('sel');
    img.onclick = function() {
      document.getElementById('fIkonaName').value = name;
      document.getElementById('ikonaPreview').src = 'icon.php?f=' + encodeURIComponent(name);
      document.getElementById('ikonaPreview').style.display = 'block';
      document.querySelectorAll('.icon-opt').forEach(i=>i.classList.remove('sel'));
      img.classList.add('sel');
    };
    el.appendChild(img);
  });
}
function filterIcons(val) { renderIconList(val); }
function previewUpload(input) {
  if (!input.files[0]) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var img = document.getElementById('cropImg');
    img.src = e.target.result;
    openModal('modalCrop');
    if (cropperInst) { cropperInst.destroy(); cropperInst = null; }
    img.onload = function() {
      cropperInst = new Cropper(img, {
        aspectRatio: 1,
        viewMode: 1,
        dragMode: 'move',
        autoCropArea: 0.85,
        movable: true,
        zoomable: true,
        rotatable: false,
      });
    };
  };
  reader.readAsDataURL(input.files[0]);
}
function cropExistingIcon() {
  var id = document.getElementById('fEditId').value;
  var tile = TILES[id]; if (!tile || !tile.ikona) return;
  cropTargetFilename = tile.ikona;
  var img = document.getElementById('cropImg');
  img.src = 'icon.php?f=' + encodeURIComponent(tile.ikona) + '&t=' + Date.now();
  openModal('modalCrop');
  if (cropperInst) { cropperInst.destroy(); cropperInst = null; }
  img.onload = function() {
    cropperInst = new Cropper(img, {
      aspectRatio: 1, viewMode: 1, dragMode: 'move', autoCropArea: 0.85,
      movable: true, zoomable: true, rotatable: false,
    });
  };
}
function closeCrop() {
  closeModal('modalCrop');
  if (cropperInst) { cropperInst.destroy(); cropperInst = null; }
  document.getElementById('fIkonaFile').value = '';
  cropTargetFilename = null;
  croppedBlob = null;
}
function applyCrop() {
  if (!cropperInst) return;
  var btn = document.querySelector('#modalCrop .btn-success');
  btn.disabled = true; btn.textContent = '⏳';
  cropperInst.getCroppedCanvas({ width: 50, height: 50 }).toBlob(function(blob) {
    btn.disabled = false; btn.textContent = 'Zastosuj kadr';
    croppedBlob = blob;
    var url = URL.createObjectURL(blob);
    var p = document.getElementById('ikonaPreview');
    p.src = url; p.style.display = 'block';
    document.querySelectorAll('.icon-opt').forEach(function(i){ i.classList.remove('sel'); });
    closeModal('modalCrop');
    if (cropperInst) { cropperInst.destroy(); cropperInst = null; }
  }, 'image/png');
}

// ── Dodaj/Edytuj ───────────────────────────────────────────────────────────
function onTileTypeChange(val) {
  var hideUrlIcon = (val === 'naglowek' || val === 'separator');
  document.getElementById('grpUrl').style.display   = hideUrlIcon ? 'none' : '';
  document.getElementById('grpIcon').style.display  = hideUrlIcon ? 'none' : '';
  document.getElementById('grpTytul').style.display = (val === 'separator') ? 'none' : '';
}
function openAdd(rzad, kol) {
  document.getElementById('modalTitle').textContent = 'Dodaj kafelek';
  document.getElementById('fEditId').value    = '';
  document.getElementById('fTytul').value     = '';
  document.getElementById('fUrl').value       = '';
  document.getElementById('fRzad').value      = rzad || (buildDataGrid().maxRow + 1);
  document.getElementById('fKolumna').value   = kol  || 1;
  document.getElementById('fIkonaFile').value = '';
  document.getElementById('fIkonaName').value = '';
  document.getElementById('ikonaPreview').style.display = 'none';
  document.getElementById('fTileType').value = '';
  document.getElementById('grpPosition').style.display = 'none';
  document.getElementById('btnCropExisting').style.display = 'none';
  croppedBlob = null; cropTargetFilename = null;
  onTileTypeChange('');
  loadIconList('');
  openModal('modalTile');
  setTimeout(function(){ document.getElementById('fTytul').focus(); }, 100);
}
function openEdit(id) {
  var tile = TILES[id]; if (!tile) return;
  var pos  = null; MENU.forEach(function(p){ if(p.id===id) pos=p; });
  var tileType = tile.typ || '';
  document.getElementById('modalTitle').textContent  = 'Edytuj kafelek';
  document.getElementById('fEditId').value            = id;
  document.getElementById('fTytul').value             = tile.tytul;
  document.getElementById('fUrl').value               = tile.url   || '';
  document.getElementById('fIkonaName').value         = tile.ikona || '';
  document.getElementById('fIkonaFile').value         = '';
  document.getElementById('fRzad').value              = pos ? pos.rzad    : 1;
  document.getElementById('fKolumna').value           = pos ? pos.kolumna : 1;
  document.getElementById('fTileType').value          = tileType;
  document.getElementById('grpPosition').style.display = 'none';
  document.getElementById('btnCropExisting').style.display = (tileType === '' && tile.ikona) ? 'inline-block' : 'none';
  croppedBlob = null; cropTargetFilename = null;
  onTileTypeChange(tileType);
  var p = document.getElementById('ikonaPreview');
  if (tileType === '' && tile.ikona) { p.src = 'icon.php?f=' + encodeURIComponent(tile.ikona); p.style.display = 'block'; }
  else p.style.display = 'none';
  if (tileType === '') loadIconList(tile.ikona);
  openModal('modalTile');
}
function saveTile() {
  var id       = document.getElementById('fEditId').value;
  var tytul    = document.getElementById('fTytul').value.trim();
  var url      = document.getElementById('fUrl').value.trim();
  var ikona    = document.getElementById('fIkonaName').value.trim();
  var tileType = document.getElementById('fTileType').value;
  var isSpecial = (tileType === 'naglowek' || tileType === 'separator');
  if (!tytul && !isSpecial) { alert('Wypełnij tytuł'); return; }
  if (!isSpecial && !url) { alert('Wypełnij URL'); return; }
  var fd = new FormData();
  fd.append('action',  id ? 'edit' : 'add');
  if (id) fd.append('id', id);
  fd.append('tytul',   tytul);
  fd.append('url',     url);
  fd.append('ikona',   ikona);
  fd.append('typ',     tileType);
  fd.append('rzad',    document.getElementById('fRzad').value);
  fd.append('kolumna', document.getElementById('fKolumna').value);
  var fi = document.getElementById('fIkonaFile');
  if (!isSpecial && croppedBlob) {
    fd.append('ikona_file', croppedBlob, 'icon.png');
    if (cropTargetFilename) fd.append('ikona_target', cropTargetFilename);
  } else if (!isSpecial && fi.files[0]) {
    fd.append('ikona_file', fi.files[0]);
  }
  document.getElementById('btnSave').disabled = true;
  api(fd).then(function(r) {
    document.getElementById('btnSave').disabled = false;
    if (!r.ok) { alert('Błąd: ' + (r.msg||'nieznany')); return; }
    closeModal('modalTile');
    if (id) {
      if (r.tile) TILES[id] = r.tile;
    } else {
      TILES[r.id] = { id:r.id, tytul:tytul, ikona:(r.ikona||ikona||''), url:url };
      if (tileType) TILES[r.id].typ = tileType;
      MENU.push({ id:r.id, rzad:r.rzad, kolumna:r.kolumna });
    }
    if (r.icons_v) ICONS_V = r.icons_v;
    updateUndoBtn(r.has_undo);
    renderGrid();
  });
}

// ── Usuń ───────────────────────────────────────────────────────────────────
function confirmDelete(id, tytul) {
  document.getElementById('deleteId').value = id;
  document.getElementById('deleteTytul').textContent = tytul;
  openModal('modalDelete');
}
function doDelete() {
  var id = document.getElementById('deleteId').value;
  var fd = new FormData(); fd.append('action','delete'); fd.append('id',id);
  api(fd).then(function(r) {
    if (!r.ok) { alert('Błąd: '+(r.msg||'nieznany')); return; }
    closeModal('modalDelete');
    delete TILES[id];
    MENU = MENU.filter(function(p){ return p.id !== id; });
    updateUndoBtn(r.has_undo);
    renderGrid();
  });
}

// ── Tryb przenoszenia ──────────────────────────────────────────────────────
function applyMoveClasses() {
  document.querySelectorAll('.tile').forEach(function(t){ t.classList.add('move-mode'); });
  document.querySelectorAll('.empty-cell').forEach(function(e){ e.classList.add('move-mode'); });
}
function removeMoveClasses() {
  document.querySelectorAll('.tile').forEach(function(t){ t.classList.remove('move-mode','selected'); });
  document.querySelectorAll('.empty-cell').forEach(function(e){ e.classList.remove('move-mode'); });
}

function toggleMove() {
  moveMode = !moveMode;
  document.getElementById('btnMove').classList.toggle('active', moveMode);
  document.getElementById('moveInfo').style.display = moveMode ? 'block' : 'none';
  // przebuduj siatkę — tryb move pokazuje leading empty, poza trybem je ukrywa
  renderGrid();
  document.getElementById('moveStep').textContent = 'Kliknij kafelek źródłowy';
}

function cancelMove(fromToggle) {
  moveSource  = null;
  movePending = false;
  if (!fromToggle) {
    moveMode = false;
    document.getElementById('btnMove').classList.remove('active');
    document.getElementById('moveInfo').style.display = 'none';
  }
  renderGrid();
  document.getElementById('moveStep').textContent = 'Kliknij kafelek źródłowy';
}

function tileClick(el, rzad, kol) {
  if (!moveMode || movePending) return; // zablokuj podczas oczekiwania na odpowiedź
  if (!moveSource) {
    document.querySelectorAll('.tile.selected').forEach(function(t){ t.classList.remove('selected'); });
    el.classList.add('selected');
    moveSource = { id: el.dataset.id, rzad: rzad, kolumna: kol };
    document.getElementById('moveStep').textContent = '→ Wybrano: "' + el.dataset.tytul + '" — wskaż cel';
  } else {
    if (el.dataset.id === moveSource.id) { cancelMove(true); return; }
    doMove(rzad, kol);
  }
}
function emptyCellClick(rzad, kol) {
  if (!moveMode || !moveSource || movePending) return; // zablokuj podczas oczekiwania
  doMove(rzad, kol);
}
function doMove(dst_rzad, dst_kol) {
  if (movePending) return; // blokuj podwójne kliknięcia
  movePending = true;

  // Zapamiętaj i wyczyść źródło PRZED wysłaniem requesta
  // — zapobiega ponownemu wybraniu tego samego kafelka jako celu
  var srcId = moveSource.id;
  moveSource = null;
  document.getElementById('moveStep').textContent = '⏳ Przenoszę...';

  var fd = new FormData();
  fd.append('action','move'); fd.append('src_id', srcId);
  fd.append('dst_rzad', dst_rzad); fd.append('dst_kolumna', dst_kol);

  api(fd).then(function(r) {
    movePending = false;
    if (!r.ok) {
      alert('Błąd przesuwania: ' + (r.msg || 'nieznany'));
      document.getElementById('moveStep').textContent = 'Kliknij kafelek źródłowy';
      return;
    }
    MENU = r.menu;
    updateUndoBtn(r.has_undo);
    renderGrid();
    if (moveMode) {
      applyMoveClasses();
      document.getElementById('moveStep').textContent = 'Kliknij kafelek źródłowy';
    }
  }).catch(function() {
    movePending = false;
    moveSource = null;
    document.getElementById('moveStep').textContent = 'Kliknij kafelek źródłowy';
    alert('Błąd sieci podczas przenoszenia');
  });
}

// ── Klawiatura ─────────────────────────────────────────────────────────────
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    if (moveMode) cancelMove();
    document.querySelectorAll('.overlay.show').forEach(function(o){ o.classList.remove('show'); });
  }
});

// ── Start ──────────────────────────────────────────────────────────────────
updateUndoBtn(hasUndo);
renderGrid();
</script>
<?php endif; ?>
</body>
</html>
