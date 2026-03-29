<?php
/** @var string $target_url  URL для редиректа */
/** @var int    $delay_ms    Задержка перед редиректом */
/** @var int    $delay_sec   Задержка в секундах */
/** @var array<string, string> $i18n  Строки локали (TemplateI18n) */
$i18n = is_array($i18n ?? null) ? $i18n : [];
$t = static function (string $key) use ($i18n): string {
    return htmlspecialchars($i18n[$key] ?? '', ENT_QUOTES, 'UTF-8');
};
$safeUrl = htmlspecialchars($target_url ?? '', ENT_QUOTES, 'UTF-8');
$jsUrl   = addslashes($target_url ?? '');
$delay   = max(500, (int)($delay_ms ?? 1500));
$delaySec = (int) ceil($delay / 1000);
$htmlLang = $t('html_lang');
if ($htmlLang === '') {
    $htmlLang = 'en';
}
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
:root{--accent:#e94560;--accent2:#6366f1;--surface:rgba(22,22,46,.72);--stroke:rgba(255,255,255,.1)}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
  background:#06060d;
  color:#f8fafc;
  min-height:100vh;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  padding:clamp(16px,4vw,28px);
  position:relative;
  overflow-x:hidden;
}
body::before{
  content:'';
  position:fixed;inset:0;
  background:
    radial-gradient(ellipse 90% 60% at 15% 10%,rgba(233,69,96,.18),transparent 55%),
    radial-gradient(ellipse 70% 50% at 90% 20%,rgba(99,102,241,.14),transparent 50%),
    radial-gradient(ellipse 50% 40% at 50% 100%,rgba(15,23,42,.9),transparent);
  pointer-events:none;z-index:0;
}
body::after{
  content:'';
  position:fixed;inset:0;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
  opacity:.35;pointer-events:none;z-index:0;
}
.card{
  position:relative;z-index:1;
  background:linear-gradient(155deg,var(--surface),rgba(15,23,42,.85));
  border:1px solid var(--stroke);
  border-radius:24px;
  padding:clamp(28px,5vw,40px) clamp(22px,4vw,32px);
  max-width:440px;width:100%;
  text-align:center;
  box-shadow:0 4px 0 rgba(255,255,255,.04) inset,0 32px 64px -12px rgba(0,0,0,.55),0 0 0 1px rgba(0,0,0,.2);
  backdrop-filter:blur(14px);
}
.icon{
  width:72px;height:72px;margin:0 auto 18px;
  display:grid;place-items:center;
  font-size:2.25rem;
  background:linear-gradient(145deg,rgba(233,69,96,.2),rgba(99,102,241,.12));
  border-radius:20px;
  border:1px solid rgba(255,255,255,.08);
  box-shadow:0 12px 28px rgba(233,69,96,.15);
}
.badge{
  display:inline-block;
  background:linear-gradient(90deg,rgba(233,69,96,.2),rgba(99,102,241,.15));
  color:#fda4af;
  border:1px solid rgba(233,69,96,.35);
  padding:6px 16px;border-radius:999px;
  font-size:.72rem;font-weight:800;letter-spacing:.12em;
  margin-bottom:18px;text-transform:uppercase;
}
h1{font-size:clamp(1.35rem,4vw,1.7rem);font-weight:800;line-height:1.22;margin-bottom:12px;letter-spacing:-.02em}
h1 span{background:linear-gradient(90deg,var(--accent),#f97316);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.subtitle{color:#94a3b8;font-size:.92rem;margin-bottom:28px;line-height:1.55}
.perks{display:flex;flex-direction:column;gap:10px;margin-bottom:28px;text-align:left}
.perk{
  display:flex;align-items:center;gap:12px;
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.06);
  border-radius:12px;padding:12px 14px;font-size:.88rem;color:#e2e8f0;
}
.perk .check{color:#34d399;font-size:1.1rem;flex-shrink:0}
.cta-btn{
  display:block;
  background:linear-gradient(135deg,var(--accent),#be123c);
  color:#fff;text-decoration:none;font-size:1.05rem;font-weight:800;
  padding:17px 24px;border-radius:14px;width:100%;cursor:pointer;border:none;
  letter-spacing:.02em;
  box-shadow:0 8px 28px rgba(233,69,96,.42),0 2px 0 rgba(255,255,255,.12) inset;
  transition:transform .18s,box-shadow .18s,filter .18s;
}
.cta-btn:hover{transform:translateY(-2px);filter:brightness(1.06);box-shadow:0 14px 36px rgba(233,69,96,.48)}
.cta-btn:active{transform:translateY(0)}
.timer{margin-top:18px;color:#64748b;font-size:.82rem}
.timer span{color:var(--accent);font-weight:800}
.disclaimer{margin-top:22px;font-size:.68rem;color:#475569;line-height:1.45}
.progress-bar{width:100%;height:4px;background:rgba(255,255,255,.08);border-radius:4px;margin-top:16px;overflow:hidden}
.progress-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:4px;width:0%;transition:width linear}
</style>
</head>
<body>

<div class="card">
  <div class="icon">🎰</div>
  <div class="badge"><?= $t('badge') ?></div>

  <h1><?= $t('h1_line1') ?> <span><?= $t('h1_span') ?></span><br><?= $t('h1_line2') ?></h1>
  <p class="subtitle"><?= $t('subtitle') ?></p>

  <div class="perks">
    <div class="perk"><span class="check">✓</span> <?= $t('perk1') ?></div>
    <div class="perk"><span class="check">✓</span> <?= $t('perk2') ?></div>
    <div class="perk"><span class="check">✓</span> <?= $t('perk3') ?></div>
    <div class="perk"><span class="check">✓</span> <?= $t('perk4') ?></div>
  </div>

  <a href="<?= $safeUrl ?>" class="cta-btn" id="ctaBtn">
    <?= $t('cta') ?>
  </a>

  <div class="timer"><?= $t('redirecting') ?> <span id="countdown"><?= $delaySec ?></span>s</div>
  <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>

  <p class="disclaimer"><?= $t('disclaimer') ?></p>
</div>

<script>
(function(){
  var delay = <?= $delay ?>;
  var url   = "<?= $jsUrl ?>";
  var start = Date.now();

  var fill    = document.getElementById('progressFill');
  var counter = document.getElementById('countdown');

  fill.style.transitionDuration = delay + 'ms';

  requestAnimationFrame(function(){
    fill.style.width = '100%';
  });

  var interval = setInterval(function(){
    var elapsed = Date.now() - start;
    var left = Math.max(0, Math.ceil((delay - elapsed) / 1000));
    if(counter) counter.textContent = left;
    if(elapsed >= delay){
      clearInterval(interval);
      window.location.href = url;
    }
  }, 200);

  document.getElementById('ctaBtn').addEventListener('click', function(e){
    e.preventDefault();
    clearInterval(interval);
    window.location.href = url;
  });
})();
</script>
</body>
</html>
