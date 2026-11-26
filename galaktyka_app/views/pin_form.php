<?php /** Formularz PIN. Zmienne: $err (string). Wszystko escapowane. */ ?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Galaktyka</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #fff; font-family: 'Segoe UI', Arial, sans-serif; color: #333; }
.box { max-width: 300px; margin: 18vh auto 0; background: #f8f9fa; border-radius: 12px; padding: 28px; box-shadow: 0 4px 24px rgba(0,0,0,.12); text-align: center; }
h1 { color: #1a73e8; font-size: 1.1rem; letter-spacing: 2px; margin-bottom: 18px; }
label { display: block; margin-bottom: 8px; color: #666; font-size: .85rem; }
input[name=pin] { width: 100%; padding: 10px; font-size: 1.6rem; letter-spacing: .5em; text-align: center; border: 1px solid #767676; border-radius: 6px; }
input:focus { outline: 2px solid #1a73e8; }
button { margin-top: 14px; width: 100%; padding: 10px; border: 0; border-radius: 6px; background: #1a73e8; color: #fff; font-size: 1rem; font-weight: 600; cursor: pointer; }
.err { color: #b71c1c; font-size: .9rem; margin-top: 12px; }
</style>
</head>
<body>
<form class="box" method="post" autocomplete="off">
  <h1>★ GALAKTYKA</h1>
  <label for="pin">Podaj PIN</label>
  <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off" autofocus required>
  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
  <button type="submit">Wejdź</button>
  <?php if ($err !== ''): ?><div class="err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>
</form>
</body>
</html>
