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
:root{--accent:#38bdf8;--gold:#fbbf24}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:ui-sans-serif,system-ui,sans-serif;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
  color:#e2e8f0;background:#020617;
  background-image:
    radial-gradient(ellipse 100% 70% at 50% -30%,rgba(56,189,248,.18),transparent 55%),
    radial-gradient(ellipse 40% 30% at 90% 90%,rgba(251,191,36,.08),transparent);
}
.panel{
  max-width:520px;width:100%;
  background:linear-gradient(165deg,rgba(15,23,42,.94),rgba(2,6,23,.97));
  border:1px solid rgba(56,189,248,.22);
  border-radius:24px;padding:32px 28px;
  box-shadow:0 28px 56px rgba(0,0,0,.5),inset 0 1px 0 rgba(255,255,255,.04);
}
.kicker{
  display:inline-flex;align-items:center;gap:8px;
  background:rgba(251,191,36,.1);color:#fde68a;border:1px solid rgba(251,191,36,.35);
  padding:6px 12px;border-radius:999px;font-size:.72rem;font-weight:800;
  letter-spacing:.06em;margin-bottom:16px;
}
.kicker::before{content:'📊';font-size:.85rem}
h1{font-size:clamp(1.35rem,4vw,1.55rem);line-height:1.25;margin-bottom:12px;font-weight:800;color:#f8fafc}
p{color:#94a3b8;font-size:.94rem;line-height:1.6;margin-bottom:20px}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:22px}
.stat{
  background:linear-gradient(180deg,rgba(56,189,248,.1),rgba(15,23,42,.6));
  border:1px solid rgba(56,189,248,.2);border-radius:14px;padding:14px 10px;text-align:center;
}
.stat b{display:block;color:var(--accent);font-size:1.05rem;font-weight:800}
.stat span{font-size:.72rem;color:#64748b}
.btn{
  display:block;text-align:center;background:linear-gradient(135deg,#0ea5e9,#0284c7);
  color:#fff;text-decoration:none;padding:16px;border-radius:14px;font-weight:800;
  box-shadow:0 10px 28px rgba(14,165,233,.35);
  transition:transform .15s;
}
.btn:hover{transform:translateY(-2px)}
.timer{margin-top:16px;font-size:.82rem;color:#64748b;text-align:center}
.timer span{color:var(--accent);font-weight:800}
.pb{height:4px;background:rgba(255,255,255,.08);border-radius:4px;margin-top:14px;overflow:hidden}
.pf{height:100%;width:0%;background:linear-gradient(90deg,#38bdf8,#fbbf24);transition:width linear}
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
    <div class="pb"><div class="pf" id="pf"></div></div>
  </div>
<script>
(function(){
  var delay = <?= $delay ?>;
  var url = "<?= $jsUrl ?>";
  var started = Date.now();
  var t = document.getElementById('t');
  var pf = document.getElementById('pf');
  pf.style.transitionDuration = delay + 'ms';
  requestAnimationFrame(function(){ pf.style.width = '100%'; });
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
