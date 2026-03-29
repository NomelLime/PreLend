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
:root{--gold:#eab308;--navy:#0c1222;--line:rgba(234,179,8,.25)}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:ui-sans-serif,system-ui,sans-serif;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
  color:#fefce8;background:#050810;
  background-image:
    radial-gradient(ellipse 80% 60% at 50% 0%,rgba(234,179,8,.12),transparent 50%),
    radial-gradient(ellipse 50% 40% at 0% 100%,rgba(59,130,246,.08),transparent);
}
.box{
  max-width:500px;width:100%;
  background:linear-gradient(180deg,rgba(17,24,39,.95),rgba(12,18,34,.98));
  border:1px solid var(--line);
  border-radius:24px;padding:32px 28px;
  box-shadow:0 28px 56px rgba(0,0,0,.45),inset 0 1px 0 rgba(255,255,255,.05);
}
.crown{
  text-align:center;font-size:2.5rem;margin-bottom:12px;
  filter:drop-shadow(0 6px 16px rgba(234,179,8,.4));
}
h1{
  font-size:1.55rem;font-weight:800;text-align:center;margin-bottom:12px;
  background:linear-gradient(90deg,#fef08a,#eab308,#fde047);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
p{color:#94a3b8;font-size:.94rem;line-height:1.6;margin-bottom:18px;text-align:center}
ul{list-style:none;margin:0 0 22px;padding:0}
li{
  position:relative;padding:12px 14px 12px 42px;margin-bottom:8px;
  background:rgba(234,179,8,.06);border:1px solid rgba(234,179,8,.15);
  border-radius:12px;color:#e2e8f0;font-size:.9rem;
}
li::before{
  content:'★';position:absolute;left:14px;top:50%;transform:translateY(-50%);
  color:var(--gold);font-size:1rem;
}
.cta{
  display:block;text-align:center;background:linear-gradient(135deg,#ca8a04,#eab308);
  color:#1c1917;text-decoration:none;padding:15px;border-radius:14px;font-weight:800;
  box-shadow:0 10px 28px rgba(234,179,8,.35);
  transition:transform .15s,filter .15s;
}
.cta:hover{transform:translateY(-2px);filter:brightness(1.05)}
.meta{margin-top:18px;text-align:center;font-size:.82rem;color:#64748b}
.meta span{color:var(--gold);font-weight:800}
.pb{height:4px;background:rgba(255,255,255,.08);border-radius:4px;margin-top:14px;overflow:hidden}
.pf{height:100%;width:0%;background:linear-gradient(90deg,#ca8a04,#fde047);transition:width linear}
</style>
</head>
<body>
  <div class="box">
    <div class="crown">👑</div>
    <h1><?= $t('h1') ?></h1>
    <p><?= $t('p') ?></p>
    <ul>
      <li><?= $t('li1') ?></li>
      <li><?= $t('li2') ?></li>
      <li><?= $t('li3') ?></li>
    </ul>
    <a href="<?= $safeUrl ?>" class="cta" id="cta"><?= $t('cta') ?></a>
    <div class="meta"><?= $t('meta_redirect') ?> <span id="clock"><?= $delaySec ?></span>s</div>
    <div class="pb"><div class="pf" id="pf"></div></div>
  </div>
<script>
(function(){
  var delay=<?= $delay ?>, url="<?= $jsUrl ?>", s=Date.now(), c=document.getElementById('clock');
  var pf=document.getElementById('pf'); pf.style.transitionDuration=delay+'ms';
  requestAnimationFrame(function(){pf.style.width='100%';});
  var tm=setInterval(function(){
    var l=Math.max(0,Math.ceil((delay-(Date.now()-s))/1000));
    if(c) c.textContent=l;
    if(l<=0){clearInterval(tm);window.location.href=url;}
  },250);
  document.getElementById('cta').addEventListener('click',function(e){e.preventDefault();clearInterval(tm);window.location.href=url;});
})();
</script>
</body>
</html>
