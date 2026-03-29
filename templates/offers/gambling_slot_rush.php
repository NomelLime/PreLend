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
:root{--fire:#f97316;--ember:#ea580c;--bg:#1a0a04}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:ui-sans-serif,system-ui,sans-serif;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
  color:#fff7ed;background:var(--bg);
  background-image:
    radial-gradient(ellipse 100% 70% at 50% -20%,rgba(249,115,22,.35),transparent 55%),
    radial-gradient(circle at 80% 80%,rgba(234,88,12,.2),transparent 40%);
}
.card{
  max-width:480px;width:100%;text-align:center;
  background:linear-gradient(175deg,rgba(67,20,7,.95),rgba(28,10,4,.98));
  border:1px solid rgba(249,115,22,.35);
  border-radius:24px;padding:32px 26px;
  box-shadow:0 24px 48px rgba(0,0,0,.5),0 0 60px -10px rgba(249,115,22,.25);
}
.slot-top{font-size:2.75rem;margin-bottom:8px;filter:drop-shadow(0 4px 12px rgba(249,115,22,.5))}
h1{font-size:clamp(1.45rem,4vw,1.7rem);font-weight:800;margin-bottom:12px;color:#ffedd5}
p{color:#fdba74;line-height:1.6;margin-bottom:20px;font-size:.95rem}
.features{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:22px}
.f{
  background:linear-gradient(180deg,rgba(249,115,22,.15),rgba(0,0,0,.2));
  border:1px solid rgba(251,146,60,.3);border-radius:14px;padding:14px 8px;
  font-size:.78rem;font-weight:700;color:#fed7aa;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.06);
}
.btn{
  display:block;background:linear-gradient(180deg,var(--fire),var(--ember));
  color:#fff;text-decoration:none;padding:16px;border-radius:14px;font-weight:800;
  box-shadow:0 10px 28px rgba(234,88,12,.45);
  transition:transform .15s;
}
.btn:hover{transform:translateY(-2px)}
.timer{margin-top:16px;color:#fdba74;font-size:.82rem}
.timer span{color:#fff;font-weight:800}
.pb{height:4px;background:rgba(255,255,255,.1);border-radius:4px;margin-top:14px;overflow:hidden}
.pf{height:100%;width:0%;background:linear-gradient(90deg,#fb923c,#f97316);transition:width linear}
</style>
</head>
<body>
  <div class="card">
    <div class="slot-top">🎰</div>
    <h1><?= $t('h1') ?></h1>
    <p><?= $t('p') ?></p>
    <div class="features">
      <div class="f"><?= $t('f1') ?></div>
      <div class="f"><?= $t('f2') ?></div>
      <div class="f"><?= $t('f3') ?></div>
    </div>
    <a href="<?= $safeUrl ?>" class="btn" id="open"><?= $t('cta') ?></a>
    <div class="timer"><?= $t('timer_redirect') ?> <span id="n"><?= $delaySec ?></span>s</div>
    <div class="pb"><div class="pf" id="pf"></div></div>
  </div>
<script>
(function(){
  var delay=<?= $delay ?>, url="<?= $jsUrl ?>", st=Date.now(), n=document.getElementById('n');
  var pf=document.getElementById('pf'); pf.style.transitionDuration=delay+'ms';
  requestAnimationFrame(function(){pf.style.width='100%';});
  var id=setInterval(function(){
    var left=Math.max(0,Math.ceil((delay-(Date.now()-st))/1000));
    if(n) n.textContent=left;
    if(left<=0){clearInterval(id);window.location.href=url;}
  },200);
  document.getElementById('open').addEventListener('click',function(e){e.preventDefault();clearInterval(id);window.location.href=url;});
})();
</script>
</body>
</html>
