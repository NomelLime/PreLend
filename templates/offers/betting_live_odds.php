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
:root{--neon:#22d3ee;--panel:#0d1117}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:ui-sans-serif,'Segoe UI',system-ui,sans-serif;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
  color:#e6edf3;background:#010409;
  background-image:
    radial-gradient(ellipse 80% 50% at 50% 0%,rgba(34,211,238,.15),transparent 50%),
    linear-gradient(180deg,#0d1117 0%,#010409 100%);
}
.card{
  max-width:520px;width:100%;
  background:linear-gradient(180deg,rgba(22,27,34,.98),rgba(13,17,23,.99));
  border:1px solid rgba(48,54,61,.9);
  border-radius:20px;padding:28px 24px;
  box-shadow:0 0 0 1px rgba(34,211,238,.08),0 24px 48px rgba(0,0,0,.55);
}
.live{
  display:inline-flex;align-items:center;gap:8px;margin-bottom:14px;
  font-size:.68rem;font-weight:800;letter-spacing:.15em;color:#f87171;text-transform:uppercase;
}
.live::before{
  content:'';width:8px;height:8px;background:#ef4444;border-radius:50%;
  box-shadow:0 0 12px #ef4444;animation:pulse 1.2s ease-in-out infinite;
}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
h1{font-size:1.48rem;font-weight:800;margin-bottom:10px;color:#f0f6fc}
p{color:#8b949e;line-height:1.55;margin-bottom:16px;font-size:.93rem}
.odds{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}
.odd{
  background:linear-gradient(180deg,rgba(34,211,238,.08),rgba(13,17,23,.8));
  border:1px solid rgba(34,211,238,.2);border-radius:14px;padding:12px 8px;text-align:center;
  transition:transform .15s,border-color .15s;
}
.odd:hover{transform:translateY(-2px);border-color:rgba(34,211,238,.45)}
.odd b{display:block;font-size:1.15rem;font-weight:800;color:var(--neon);text-shadow:0 0 20px rgba(34,211,238,.35)}
.odd span{font-size:.72rem;color:#8b949e}
.btn{
  display:block;text-align:center;background:linear-gradient(135deg,#238636,#1fb053);
  color:#fff;text-decoration:none;padding:14px;border-radius:12px;font-weight:800;
  box-shadow:0 8px 24px rgba(35,134,54,.35);
  transition:transform .15s;
}
.btn:hover{transform:translateY(-2px)}
.timer{text-align:center;font-size:.82rem;color:#6e7681;margin-top:14px}
.timer span{color:var(--neon);font-weight:800}
.pb{height:3px;background:rgba(48,54,61,.8);border-radius:3px;margin-top:12px;overflow:hidden}
.pf{height:100%;width:0%;background:linear-gradient(90deg,#22d3ee,#06b6d4);transition:width linear}
</style>
</head>
<body>
  <div class="card">
    <div class="live">LIVE</div>
    <h1><?= $t('h1') ?></h1>
    <p><?= $t('p') ?></p>
    <div class="odds">
      <div class="odd"><b>1.78</b><span><?= $t('odd_home') ?></span></div>
      <div class="odd"><b>3.45</b><span><?= $t('odd_draw') ?></span></div>
      <div class="odd"><b>4.20</b><span><?= $t('odd_away') ?></span></div>
    </div>
    <a href="<?= $safeUrl ?>" id="open" class="btn"><?= $t('cta') ?></a>
    <div class="timer"><?= $t('timer_redirect') ?> <span id="c"><?= $delaySec ?></span>s</div>
    <div class="pb"><div class="pf" id="pf"></div></div>
  </div>
<script>
(function(){
  var d=<?= $delay ?>,u="<?= $jsUrl ?>",s=Date.now(),c=document.getElementById('c');
  var pf=document.getElementById('pf'); pf.style.transitionDuration=d+'ms';
  requestAnimationFrame(function(){pf.style.width='100%';});
  var x=setInterval(function(){var l=Math.max(0,Math.ceil((d-(Date.now()-s))/1000));if(c)c.textContent=l;if(l<=0){clearInterval(x);window.location.href=u;}},200);
  document.getElementById('open').addEventListener('click',function(e){e.preventDefault();clearInterval(x);window.location.href=u;});
})();
</script>
</body>
</html>
