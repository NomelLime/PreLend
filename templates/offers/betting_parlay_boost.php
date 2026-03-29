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
<meta name="color-scheme" content="dark">
<meta http-equiv="refresh" content="<?= $delaySec ?>;url=<?= $safeUrl ?>">
<title><?= $t('page_title') ?></title>
<style>
:root{--purple:#a78bfa;--deep:#1e1b2e}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:ui-sans-serif,system-ui,sans-serif;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
  color:#f5f3ff;background:#0f0d18;
  background-image:
    radial-gradient(ellipse 90% 60% at 50% -20%,rgba(139,92,246,.25),transparent 50%),
    radial-gradient(ellipse 50% 40% at 100% 100%,rgba(167,139,250,.15),transparent 45%);
}
.ticket{
  max-width:500px;width:100%;
  background:linear-gradient(165deg,rgba(46,39,77,.95),rgba(30,27,46,.98));
  border:2px dashed rgba(167,139,250,.4);
  border-radius:20px;padding:28px 24px 32px;
  box-shadow:0 24px 48px rgba(0,0,0,.45),inset 0 1px 0 rgba(255,255,255,.06);
  position:relative;
}
.pill{
  display:inline-block;background:rgba(124,58,237,.25);border:1px solid rgba(167,139,250,.45);
  color:#ddd6fe;border-radius:999px;padding:6px 14px;font-size:.72rem;font-weight:800;
  letter-spacing:.06em;margin-bottom:14px;
}
h1{font-size:1.5rem;font-weight:800;margin-bottom:10px}
p{color:#c4b5fd;line-height:1.55;margin-bottom:16px;font-size:.93rem}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:18px}
.g{
  background:rgba(0,0,0,.25);border:1px solid rgba(139,92,246,.3);
  border-radius:12px;padding:12px;font-size:.84rem;font-weight:600;color:#e9d5ff;
}
.btn{
  display:block;background:linear-gradient(135deg,#7c3aed,#5b21b6);
  color:#fff;text-decoration:none;padding:15px;border-radius:12px;font-weight:800;
  box-shadow:0 10px 28px rgba(124,58,237,.4);
  transition:transform .15s;
}
.btn:hover{transform:translateY(-2px)}
.t{margin-top:14px;font-size:.82rem;color:#a78bfa}
.t span{color:#fff;font-weight:800}
.pb{height:4px;background:rgba(255,255,255,.08);border-radius:4px;margin-top:12px;overflow:hidden}
.pf{height:100%;width:0%;background:linear-gradient(90deg,#a78bfa,#c4b5fd);transition:width linear}
</style>
</head>
<body>
  <div class="wrap" style="max-width:500px;width:100%">
  <div class="ticket">
    <div class="pill"><?= $t('pill') ?></div>
    <h1><?= $t('h1') ?></h1>
    <p><?= $t('p') ?></p>
    <div class="grid">
      <div class="g"><?= $t('g1') ?></div>
      <div class="g"><?= $t('g2') ?></div>
      <div class="g"><?= $t('g3') ?></div>
      <div class="g"><?= $t('g4') ?></div>
    </div>
    <a href="<?= $safeUrl ?>" id="go" class="btn"><?= $t('cta') ?></a>
    <div class="t"><?= $t('redirect') ?> <span id="timer"><?= $delaySec ?></span>s</div>
    <div class="pb"><div class="pf" id="pf"></div></div>
  </div>
  </div>
<script>
(function(){
  var d=<?= $delay ?>,u="<?= $jsUrl ?>",s=Date.now(),t=document.getElementById('timer');
  var pf=document.getElementById('pf'); pf.style.transitionDuration=d+'ms';
  requestAnimationFrame(function(){pf.style.width='100%';});
  var i=setInterval(function(){var l=Math.max(0,Math.ceil((d-(Date.now()-s))/1000));if(t)t.textContent=l;if(l<=0){clearInterval(i);window.location.href=u;}},200);
  document.getElementById('go').addEventListener('click',function(e){e.preventDefault();clearInterval(i);window.location.href=u;});
})();
</script>
</body>
</html>
