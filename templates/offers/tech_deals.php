<?php
/** @var string $target_url */
/** @var int    $delay_ms */
/** @var int    $delay_sec */
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
body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0c11;color:#f3f5ff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{max-width:560px;width:100%;background:#131722;border:1px solid #2a3147;border-radius:16px;padding:26px}
.label{display:inline-block;font-size:.72rem;background:#1c2340;color:#9db0ff;border:1px solid #313e73;padding:5px 10px;border-radius:999px;margin-bottom:12px}
h1{font-size:1.6rem;line-height:1.2;margin-bottom:10px}
p{color:#b5bdd7;line-height:1.55;font-size:.95rem;margin-bottom:14px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:14px 0}
.item{background:#191e2f;border:1px solid #2b3555;border-radius:10px;padding:10px}
.item b{display:block;font-size:.95rem}
.item span{font-size:.78rem;color:#9ca7c8}
.btn{display:block;text-align:center;background:linear-gradient(90deg,#6366f1,#4f46e5);color:#fff;text-decoration:none;font-weight:700;padding:13px;border-radius:10px;margin-top:6px}
.meta{text-align:center;color:#97a4cc;font-size:.8rem;margin-top:10px}
.meta span{color:#c7d2ff;font-weight:700}
</style>
</head>
<body>
  <div class="card">
    <div class="label"><?= $t('label') ?></div>
    <h1><?= $t('h1') ?></h1>
    <p><?= $t('p') ?></p>
    <div class="grid">
      <div class="item"><b><?= $t('item1_title') ?></b><span><?= $t('item1_sub') ?></span></div>
      <div class="item"><b><?= $t('item2_title') ?></b><span><?= $t('item2_sub') ?></span></div>
      <div class="item"><b><?= $t('item3_title') ?></b><span><?= $t('item3_sub') ?></span></div>
      <div class="item"><b><?= $t('item4_title') ?></b><span><?= $t('item4_sub') ?></span></div>
    </div>
    <a href="<?= $safeUrl ?>" id="open" class="btn"><?= $t('cta') ?></a>
    <div class="meta"><?= $t('meta_redirect') ?> <span id="clock"><?= $delaySec ?></span>s</div>
  </div>
<script>
(function(){
  var delay = <?= $delay ?>;
  var url = "<?= $jsUrl ?>";
  var started = Date.now();
  var clock = document.getElementById('clock');
  var t = setInterval(function(){
    var left = Math.max(0, Math.ceil((delay - (Date.now() - started))/1000));
    if(clock) clock.textContent = left;
    if(left <= 0){ clearInterval(t); window.location.href = url; }
  }, 200);
  document.getElementById('open').addEventListener('click', function(e){
    e.preventDefault();
    clearInterval(t);
    window.location.href = url;
  });
})();
</script>
</body>
</html>
