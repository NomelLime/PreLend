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
<meta name="color-scheme" content="dark">
<meta http-equiv="refresh" content="<?= $delaySec ?>;url=<?= $safeUrl ?>">
<title><?= $t('page_title') ?></title>
<style>
:root{--cyan:#22d3ee;--violet:#818cf8}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:ui-monospace,'Cascadia Code','SF Mono',Consolas,system-ui,sans-serif;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
  color:#e2e8f0;background:#030712;
  background-image:
    linear-gradient(rgba(34,211,238,.04) 1px,transparent 1px),
    linear-gradient(90deg,rgba(34,211,238,.04) 1px,transparent 1px);
  background-size:32px 32px;
  background-position:center top;
}
body::before{
  content:'';position:fixed;inset:0;
  background:radial-gradient(ellipse 80% 50% at 50% 0%,rgba(129,140,248,.12),transparent 55%);
  pointer-events:none;
}
.card{
  position:relative;z-index:1;max-width:560px;width:100%;
  background:linear-gradient(175deg,rgba(17,24,39,.92),rgba(3,7,18,.96));
  border:1px solid rgba(129,140,248,.25);
  border-radius:16px;padding:28px 26px;
  box-shadow:0 0 0 1px rgba(34,211,238,.08),0 28px 56px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.04);
}
.label{
  display:inline-block;font-size:.68rem;background:rgba(34,211,238,.12);
  color:#67e8f9;border:1px solid rgba(34,211,238,.35);
  padding:6px 11px;border-radius:6px;font-weight:700;letter-spacing:.08em;
  margin-bottom:14px;font-family:ui-sans-serif,sans-serif;
}
h1{
  font-family:ui-sans-serif,system-ui,sans-serif;
  font-size:clamp(1.45rem,4vw,1.65rem);line-height:1.2;margin-bottom:10px;font-weight:800;
  background:linear-gradient(90deg,#e2e8f0,#a5b4fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
p{color:#94a3b8;line-height:1.55;font-size:.93rem;margin-bottom:16px;font-family:ui-sans-serif,sans-serif}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:18px 0}
.item{
  background:rgba(15,23,42,.8);border:1px solid rgba(100,116,139,.35);
  border-radius:12px;padding:14px 12px;
  transition:border-color .15s,transform .15s;
}
.item:hover{border-color:rgba(34,211,238,.4);transform:translateY(-2px)}
.item b{display:block;font-size:.95rem;color:#f1f5f9;font-family:ui-sans-serif,sans-serif}
.item span{font-size:.76rem;color:#64748b}
.btn{
  display:block;text-align:center;font-family:ui-sans-serif,sans-serif;
  background:linear-gradient(135deg,#6366f1,#4f46e5);
  color:#fff;text-decoration:none;font-weight:800;padding:15px;border-radius:12px;margin-top:8px;
  box-shadow:0 10px 28px rgba(99,102,241,.4);
  transition:transform .15s;
}
.btn:hover{transform:translateY(-2px)}
.meta{text-align:center;color:#64748b;font-size:.82rem;margin-top:14px;font-family:ui-sans-serif,sans-serif}
.meta span{color:#a5b4fc;font-weight:800}
.pb{height:3px;background:rgba(51,65,85,.6);border-radius:3px;margin-top:12px;overflow:hidden}
.pf{height:100%;width:0%;background:linear-gradient(90deg,var(--cyan),var(--violet));transition:width linear}
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
    <div class="pb"><div class="pf" id="pf"></div></div>
  </div>
<script>
(function(){
  var delay = <?= $delay ?>;
  var url = "<?= $jsUrl ?>";
  var started = Date.now();
  var clock = document.getElementById('clock');
  var pf = document.getElementById('pf');
  pf.style.transitionDuration = delay + 'ms';
  requestAnimationFrame(function(){ pf.style.width = '100%'; });
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
