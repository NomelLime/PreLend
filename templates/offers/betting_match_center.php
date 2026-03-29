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
:root{--pitch:#14532d;--glow:#22c55e;--sky:#0c4a6e}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:ui-sans-serif,system-ui,sans-serif;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
  color:#ecfdf5;
  background:#031a0f;
  background-image:
    radial-gradient(ellipse 120% 80% at 50% 100%,rgba(20,83,45,.6),transparent 55%),
    repeating-linear-gradient(90deg,rgba(255,255,255,.02) 0,rgba(255,255,255,.02) 1px,transparent 1px,transparent 24px);
}
.panel{
  max-width:540px;width:100%;
  background:linear-gradient(165deg,rgba(6,78,59,.92),rgba(6,45,34,.96));
  border:1px solid rgba(34,197,94,.35);
  border-radius:24px;padding:32px 28px;
  box-shadow:0 28px 56px rgba(0,0,0,.4),0 0 80px -24px rgba(34,197,94,.25);
  position:relative;overflow:hidden;
}
.panel::before{
  content:'';position:absolute;top:-40%;left:-20%;width:80%;height:80%;
  background:radial-gradient(circle,rgba(34,197,94,.12),transparent 70%);
  pointer-events:none;
}
.stadium{
  text-align:center;font-size:2.5rem;margin-bottom:12px;
  filter:drop-shadow(0 4px 12px rgba(34,197,94,.35));
}
h1{position:relative;font-size:clamp(1.4rem,4vw,1.65rem);font-weight:800;margin-bottom:12px;line-height:1.2}
p{color:#a7f3d0;line-height:1.6;margin-bottom:18px;font-size:.94rem;position:relative}
.list{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;position:relative}
.chip{
  flex:1;min-width:100px;text-align:center;
  background:rgba(6,78,59,.6);border:1px solid rgba(52,211,153,.35);
  border-radius:999px;padding:8px 12px;font-size:.76rem;font-weight:700;color:#d1fae5;
}
.btn{
  position:relative;display:block;text-align:center;
  background:linear-gradient(135deg,#16a34a,#15803d);
  color:#fff;text-decoration:none;padding:16px;border-radius:14px;font-weight:800;
  box-shadow:0 10px 28px rgba(22,163,74,.4);
  transition:transform .15s;
}
.btn:hover{transform:translateY(-2px)}
.timer{text-align:center;margin-top:16px;font-size:.82rem;color:#6ee7b7}
.timer span{color:#fff;font-weight:800}
.pb{height:4px;background:rgba(255,255,255,.1);border-radius:4px;margin-top:14px;overflow:hidden}
.pf{height:100%;width:0%;background:linear-gradient(90deg,#22c55e,#4ade80);transition:width linear}
</style>
</head>
<body>
  <div class="panel">
    <div class="stadium">⚽</div>
    <h1><?= $t('h1') ?></h1>
    <p><?= $t('p') ?></p>
    <div class="list">
      <span class="chip"><?= $t('chip1') ?></span>
      <span class="chip"><?= $t('chip2') ?></span>
      <span class="chip"><?= $t('chip3') ?></span>
    </div>
    <a href="<?= $safeUrl ?>" class="btn" id="b"><?= $t('cta') ?></a>
    <div class="timer"><?= $t('timer_redirect') ?> <span id="t"><?= $delaySec ?></span>s</div>
    <div class="pb"><div class="pf" id="pf"></div></div>
  </div>
<script>
(function(){
  var d=<?= $delay ?>,u="<?= $jsUrl ?>",s=Date.now(),t=document.getElementById('t');
  var pf=document.getElementById('pf'); pf.style.transitionDuration=d+'ms';
  requestAnimationFrame(function(){pf.style.width='100%';});
  var i=setInterval(function(){var l=Math.max(0,Math.ceil((d-(Date.now()-s))/1000));if(t)t.textContent=l;if(l<=0){clearInterval(i);window.location.href=u;}},250);
  document.getElementById('b').addEventListener('click',function(e){e.preventDefault();clearInterval(i);window.location.href=u;});
})();
</script>
</body>
</html>
