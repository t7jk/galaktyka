<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // narzędzie tylko z linii poleceń (nigdy przez WWW)
/**
 * SETUP.PHP — jednorazowy skrypt pobierający ikony z galaktyka.edu.pl
 * Konwertuje do PNG 50x50 (crop + resize) przez PHP GD.
 * Po uruchomieniu: usuń lub zabezpiecz ten plik!
 */

$icons_dir = dirname(__DIR__) . '/data/icons/';
if (!is_dir($icons_dir)) mkdir($icons_dir, 0755, true);

$icon_sources = [
    'gmail.png'        => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/gmail-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'planner.png'      => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/Planner-e1689014385232-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'office365.png'    => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/office365-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'outlook.png'      => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/transparent-outlook-icon-2-e1689014403888-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'smsapi.png'       => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/smsapi-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpeg',
    'justsend.png'     => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/justsend-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'text.png'         => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/text-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'ksef.png'         => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/ksef-rk4equd8bcx2nvvxbhkxj2ihyqmeczq154xrna1dv8.png',
    'tpay.png'         => 'https://galaktyka.edu.pl/wp-content/uploads/2021/05/2019-09-11-17_00_15-Trusted-Online-Payments-_-Tpay-the-way-to-pay-Opera-e1584907077929.png',
    'pko.png'          => 'https://galaktyka.edu.pl/wp-content/uploads/2021/05/pko-nwq7v4ltovtmcl0bpk9i0gdq89qp108j9zu0bjae3s-e1584906989428.png',
    'psota5.png'       => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/psota5-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'sroka.png'        => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/sroka-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'ans.png'          => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/cropped-an_logo_cmyk-1-e1616853732176-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'fb.png'           => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/fb-2-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'yt.png'           => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/yt-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'inst.png'         => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/inst-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'canva.png'        => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/canva-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'safetax.png'      => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/safetax-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'mbank.png'        => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/mbank-1-1-e1584907000438-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'psota5mcs.png'    => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/psota5mcs50x50-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'mindcloud.png'    => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/admin-ajax.php_-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpeg',
    'galaktyka.png'    => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/admin-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'warsaw.png'       => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/warsaw-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'asustor.png'      => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/asustor-e1718302156656-rj1xero5hft32buw7icgnm40vpqbyq1jda92khuzxw.png',
    'sslforweb.png'    => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/sslforweb-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'cpanel.png'       => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/cpanel-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'trustpilot.png'   => 'https://galaktyka.edu.pl/wp-content/uploads/2025/06/Trustpilot-Logo-672041447.png',
    'obecnosc.png'     => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/obecnosc-logo-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'brevo.png'        => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/Brevo-logo-e1689014396503-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'bitly.png'        => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/bitly-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'sumup.png'        => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/sumup-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'przelewy24.png'   => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/2019-09-11-16_55_10-Przelewy24-Opera-e1584907083710-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'insert.png'       => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/insert-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'smtp2go.png'      => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/smtp2go-e1718302141360-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'hostido.png'      => 'https://galaktyka.edu.pl/wp-content/uploads/2025/06/Screenshot-2025-06-03-at-11-01-21-Hostido.pl-Bezkompromisowy-ultraszybki-hosting-dla-Ciebie-Hostido-e1758735518643.png',
    'webd.png'         => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/webd-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'pep.png'          => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/2019-09-11-17_02_35-PeP-Strefa-Opera-e1584907072219-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'fcn.png'          => 'https://galaktyka.edu.pl/wp-content/uploads/2024/08/FCN-e1758735474583.png',
    'grandstream.png'  => 'https://galaktyka.edu.pl/wp-content/uploads/2023/10/grandstream.jpg',
    'segregatory.png'  => 'https://galaktyka.edu.pl/wp-content/uploads/2024/10/printhub-e1758735424876.png',
    'altmedia.png'     => 'https://galaktyka.edu.pl/wp-content/uploads/2024/10/altmedia.png',
    'pinmania.png'     => 'https://galaktyka.edu.pl/wp-content/uploads/2024/10/pinmania.png',
    'segate.png'       => 'https://galaktyka.edu.pl/wp-content/uploads/2024/10/segate.png',
    'tag.png'          => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/tag-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'ads.png'          => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/ads-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'gmb.png'          => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/gmb-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'bing.png'         => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/bing-e1718302167795-rj1xero5hft32buw7icgnm40vpqbyq1jda92khuzxw.png',
    'gsc.png'          => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/gsc-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.jpg',
    'freepik.png'      => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/freepik-logo-244528161-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'claude.png'       => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/claude-rk0loziqxqu9gyl7ksuvm40eg8z33wnist9q8himb8.png',
    'chatgpt.png'      => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/gpt-rj1xfbergyk3u52808vmlz4pct11gd7wfzy9nb1qb8.png',
    'grok.png'         => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/grok-e1758734506387-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'copilot.png'      => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/copilot-e1758736807808-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'gemini.png'       => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/gemini-ai-e1758736474261-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'github.png'       => 'https://galaktyka.edu.pl/wp-content/uploads/2025/12/github-e1765534799324.jpeg',
    'ai-studio.png'    => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/google-ai-studio-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'notebooklm.png'   => 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/NotebookLM-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
    'lmarena.png'      => 'https://galaktyka.edu.pl/wp-content/uploads/2025/09/LMArena-e1758736793871.png',
    'adobe-express.png'=> 'https://galaktyka.edu.pl/wp-content/uploads/elementor/thumbs/adobe-exp-rj09e31xgfzt38de31imkcsektgt639zyw53pjj0xg.png',
];

/**
 * Pobiera obraz przez cURL i konwertuje do PNG 50x50 (smart crop + resize) przez GD
 */
function download_and_convert(string $url, string $dest_path): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; setup/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$data || $http_code !== 200) {
        return "BŁĄD HTTP $http_code";
    }

    $src = @imagecreatefromstring($data);
    if (!$src) return "BŁĄD: nie można odczytać obrazu";

    $sw = imagesx($src);
    $sh = imagesy($src);

    // Smart crop: wytnij kwadrat ze środka
    $size = min($sw, $sh);
    $sx = (int)(($sw - $size) / 2);
    $sy = (int)(($sh - $size) / 2);

    $dst = imagecreatetruecolor(50, 50);
    // Obsługa przezroczystości
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, 50, 50, $transparent);
    imagealphablending($dst, true);

    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, 50, 50, $size, $size);
    imagedestroy($src);

    // Zapisz jako PNG z przezroczystością
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagepng($dst, $dest_path, 9);
    imagedestroy($dst);

    return "OK";
}

set_time_limit(300);
$results = [];
$ok = 0;
$err = 0;

foreach ($icon_sources as $filename => $url) {
    $dest = $icons_dir . $filename;
    if (file_exists($dest)) {
        $results[] = ['file' => $filename, 'status' => 'POMINIĘTO (już istnieje)', 'class' => 'skip'];
        $ok++;
        continue;
    }
    $status = download_and_convert($url, $dest);
    $is_ok = ($status === 'OK');
    if ($is_ok) $ok++; else $err++;
    $results[] = ['file' => $filename, 'status' => $status, 'class' => $is_ok ? 'ok' : 'err'];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Setup — Galaktyka</title>
<style>
body { font-family: monospace; background: #1a1a2e; color: #eee; padding: 20px; }
h1 { color: #e94560; }
table { border-collapse: collapse; width: 100%; max-width: 700px; }
td, th { padding: 4px 10px; border-bottom: 1px solid #333; }
th { background: #16213e; color: #0f3460; color: #7ec8e3; }
.ok { color: #4caf50; }
.skip { color: #888; }
.err { color: #f44336; font-weight: bold; }
.summary { margin-top: 20px; padding: 10px; background: #16213e; border-radius: 5px; }
.warn { color: #ff9800; margin-top: 20px; padding: 10px; background: #2d1a00; border-radius: 5px; }
</style>
</head>
<body>
<h1>Setup — pobieranie ikon</h1>
<table>
<tr><th>Plik</th><th>Status</th></tr>
<?php foreach ($results as $r): ?>
<tr>
  <td><?= htmlspecialchars($r['file']) ?></td>
  <td class="<?= $r['class'] ?>"><?= htmlspecialchars($r['status']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<div class="summary">
  Pobrano/OK: <strong><?= $ok ?></strong> | Błędy: <strong><?= $err ?></strong>
</div>
<?php if ($err === 0): ?>
<div class="warn">
  ✅ Wszystkie ikony gotowe.<br>
  ⚠️ <strong>Usuń lub zabezpiecz plik setup.php przed udostępnieniem!</strong><br>
  <a href="index.php" style="color:#7ec8e3">→ Przejdź do aplikacji</a>
</div>
<?php endif; ?>
</body>
</html>
