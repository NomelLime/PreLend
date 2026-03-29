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
<meta name="color-scheme" content="light">
<meta http-equiv="refresh" content="<?= $delaySec ?>;url=<?= $safeUrl ?>">
<title><?= $t('page_title') ?></title>
<style>
:root{--leaf:#16a34a;--mint:#ecfdf5;--paper:#fff}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:ui-sans-serif,system-ui,sans-serif;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
  color:#14532d;
  background:#f0fdf4;
  background-image:
    radial-gradient(ellipse 80% 60% at 20% 20%,rgba(187,247,208,.8),transparent 50%),
    radial-gradient(ellipse 60% 50% at 90% 80%,rgba(167,243,208,.6),transparent 45%);
}
.box{
  max-width:520px;width:100%;
  background:var(--paper);
  border:1px solid rgba(22,163,74,.2);
  border-radius:28px;padding:36px 32px;
  box-shadow:0 4px 0 rgba(22,163,74,.06),0 24px 48px rgba(20,83,45,.1);
}
.pill{
  display:inline-block;background:linear-gradient(90deg,#dcfce7,#bbf7d0);
  color:#166534;padding:8px 14px;border-radius:999px;
  font-size:.74rem;font-weight:800;letter-spacing:.04em;margin-bottom:16px;
  border:1px solid rgba(34,197,94,.25);
}
h1{font-size:clamp(1.45rem,4vw,1.7rem);line-height:1.2;color:#14532d;margin-bottom:12px;font-weight:800}
p{font-size:.96rem;line-height:1.6;color:#3f6212;margin-bottom:18px}
ul{list-style:none;margin:0 0 22px;padding:0}
li{
  position:relative;padding:12px 14px 12px 44px;margin-bottom:10px;
  background:#f7fee7;border:1px solid rgba(132,204,22,.35);
  border-radius:14px;color:#365314;font-size:.92rem;
}
li::before{
  content:'✓';position:absolute;left:14px;top:50%;transform:translateY(-50%);
  width:24px;height:24px;display:grid;place-items:center;
  background:#22c55e;color:#fff;font-size:.75rem;font-weight:800;border-radius:8px;
}
.cta{
  display:block;text-align:center;text-decoration:none;
  background:linear-gradient(135deg,#22c55e,#16a34a);
  color:#fff;font-weight:800;padding:16px;border-radius:14px;
  box-shadow:0 10px 28px rgba(22,163,74,.35);
  transition:transform .15s;
}
.cta:hover{transform:translateY(-2px)}
.foot{margin-top:16px;text-align:center;font-size:.82rem;color:#65a30d}
.foot span{font-weight:800;color:var(--leaf)}
.pb{height:4px;background:rgba(22,163,74,.15);border-radius:4px;margin-top:14px;overflow:hidden}
.pf{height:100%;width:0%;background:linear-gradient(90deg,#4ade80,#22c55e);transition:width linear}
</style>
</head>
<body>
  <div class="box">
    <div class="pill"><?= $t('pill') ?></div>
    <h1><?= $t('h1') ?></h1>
    <p><?= $t('p') ?></p>
    <ul>
      <li><?= $t('li1') ?></li>
      <li><?= $t('li2') ?></li>
      <li><?= $t('li3') ?></li>
    </ul>
    <a href="<?= $safeUrl ?>" class="cta" id="cta"><?= $t('cta') ?></a>
    <div class="foot"><?= $t('foot_redirect') ?> <span id="count"><?= $delaySec ?></span>s</div>
    <div class="pb"><div class="pf" id="pf"></div></div>
  </div>
<script>
(function(){
  var delay = <?= $delay ?>;
  var url = "<?= $jsUrl ?>";
  var start = Date.now();
  var el = document.getElementById('count');
  var pf = document.getElementById('pf');
  pf.style.transitionDuration = delay + 'ms';
  requestAnimationFrame(function(){ pf.style.width = '100%'; });
  var timer = setInterval(function(){
    var left = Math.max(0, Math.ceil((delay - (Date.now() - start))/1000));
    if(el) el.textContent = left;
    if(left <= 0){ clearInterval(timer); window.location.href = url; }
  }, 250);
  document.getElementById('cta').addEventListener('click', function(e){
    e.preventDefault();
    clearInterval(timer);
    window.location.href = url;
  });
})();
</script>
</body>
</html>
