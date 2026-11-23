<?php
declare(strict_types=1);
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob:; media-src 'self' blob:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
$assetVersion=(string)filemtime(__FILE__);
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="sv">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Din egen plats för att skapa, planera och publicera i sociala medier.">
  <title>Socialen — din innehållskalender</title>
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/app.css?v=<?= $assetVersion ?>"><script src="assets/app.js?v=<?= $assetVersion ?>" defer></script>
</head>
<body>
  <div id="app"><div class="boot"><div class="brand-mark">S</div><p>Öppnar din kalender…</p></div></div>
  <div id="toast" role="status" aria-live="polite"></div>
  <dialog id="editor" aria-labelledby="editor-title"></dialog>
  <dialog id="confirm-dialog" aria-labelledby="confirm-title"></dialog>
</body>
</html>
