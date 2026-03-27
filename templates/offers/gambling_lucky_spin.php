<?php
/** @var string $target_url */
/** @var int    $delay_ms */
/** @var array<string, string> $i18n */
$i18n = is_array($i18n ?? null) ? $i18n : [];
$t = static function (string $key) use ($i18n): string {
    return htmlspecialchars($i18n[$key] ?? '', ENT_QUOTES, 'UTF-8');
};
$htmlLang = $t('html_lang');
if ($htmlLang === '') {
    $htmlLang = 'en';
}
$safeUrl = htmlspecialchars($target_url ?? '', ENT_QUOTES, 'UTF-8');
$jsUrl   = addslashes($target_url ?? '');
$delay   = max(500, (int)($delay_ms ?? 1500));
$delaySec = (int) ceil($delay / 1000);
?>
<!DOCTYPE html>
<html lang="<?= $htmlLang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="<?= $delaySec ?>;url=<?= $safeUrl ?>">
<title><?= $t('page_title') ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#10081f;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{max-width:460px;width:100%;background:#1b1031;border:1px solid #3a2a66;border-radius:16px;padding:26px;text-align:center}
.tag{display:inline-block;background:#2d1f55;color:#c7b5ff;border:1px solid #4f3d89;border-radius:999px;padding:5px 12px;font-size:.75rem;margin-bottom:12px}
h1{font-size:1.55rem;margin-bottom:10px}
p{color:#c8bedf;line-height:1.55;font-size:.93rem;margin-bottom:16px}
.btn{display:block;background:linear-gradient(90deg,#a855f7,#7c3aed);color:#fff;text-decoration:none;padding:14px;border-radius:10px;font-weight:700}
.timer{margin-top:12px;font-size:.8rem;color:#b8abd8}
</style>
</head>
<body>
  <div class="card">
    <div class="tag"><?= $t('tag') ?></div>
    <h1><?= $t('h1') ?></h1>
    <p><?= $t('p') ?></p>
    <a href="<?= $safeUrl ?>" id="go" class="btn"><?= $t('cta') ?></a>
    <div class="timer"><?= $t('timer_redirect') ?> <span id="t"><?= $delaySec ?></span>s</div>
  </div>
<script>
(function(){
  var delay = <?= $delay ?>, url = "<?= $jsUrl ?>", start = Date.now();
  var t = document.getElementById('t');
  var i = setInterval(function(){
    var left = Math.max(0, Math.ceil((delay - (Date.now()-start))/1000));
    if(t) t.textContent = left;
    if(left <= 0){ clearInterval(i); window.location.href = url; }
  }, 200);
  document.getElementById('go').addEventListener('click', function(e){
    e.preventDefault(); clearInterval(i); window.location.href = url;
  });
})();
</script>
</body>
</html>
