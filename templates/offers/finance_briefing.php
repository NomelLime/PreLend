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
body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#08111f;color:#e6edf7;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.panel{max-width:520px;width:100%;background:linear-gradient(180deg,#0f1d33,#0b1628);border:1px solid rgba(150,180,220,.2);border-radius:16px;padding:28px;box-shadow:0 20px 45px rgba(0,0,0,.35)}
.kicker{display:inline-block;background:#17395f;color:#8fd3ff;border:1px solid #24517f;padding:5px 10px;border-radius:999px;font-size:.75rem;margin-bottom:14px}
h1{font-size:1.45rem;line-height:1.25;margin-bottom:10px}
p{color:#b6c7dc;font-size:.95rem;line-height:1.55;margin-bottom:18px}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}
.stat{background:#0d2340;border:1px solid rgba(143,211,255,.15);border-radius:10px;padding:10px;text-align:center}
.stat b{display:block;color:#8fd3ff;font-size:1rem}
.stat span{font-size:.75rem;color:#8aa2be}
.btn{display:block;text-align:center;background:linear-gradient(90deg,#2d7fff,#3f9cff);color:#fff;text-decoration:none;padding:14px;border-radius:10px;font-weight:700}
.timer{margin-top:14px;font-size:.8rem;color:#90a8c4;text-align:center}
.timer span{color:#8fd3ff;font-weight:700}
</style>
</head>
<body>
  <div class="panel">
    <div class="kicker"><?= $t('kicker') ?></div>
    <h1><?= $t('h1') ?></h1>
    <p><?= $t('p') ?></p>
    <div class="stats">
      <div class="stat"><b><?= $t('stat1_b') ?></b><span><?= $t('stat1_s') ?></span></div>
      <div class="stat"><b><?= $t('stat2_b') ?></b><span><?= $t('stat2_s') ?></span></div>
      <div class="stat"><b><?= $t('stat3_b') ?></b><span><?= $t('stat3_s') ?></span></div>
    </div>
    <a href="<?= $safeUrl ?>" class="btn" id="goBtn"><?= $t('cta') ?></a>
    <div class="timer"><?= $t('timer_redirect') ?> <span id="t"><?= $delaySec ?></span>s</div>
  </div>
<script>
(function(){
  var delay = <?= $delay ?>;
  var url = "<?= $jsUrl ?>";
  var started = Date.now();
  var t = document.getElementById('t');
  var intv = setInterval(function(){
    var left = Math.max(0, Math.ceil((delay - (Date.now() - started)) / 1000));
    if(t) t.textContent = left;
    if(left <= 0){ clearInterval(intv); window.location.href = url; }
  }, 200);
  document.getElementById('goBtn').addEventListener('click', function(e){
    e.preventDefault();
    clearInterval(intv);
    window.location.href = url;
  });
})();
</script>
</body>
</html>
