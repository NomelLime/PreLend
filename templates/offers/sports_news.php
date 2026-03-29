<?php
/** @var string $target_url */
/** @var int    $delay_ms   */
/** @var int    $delay_sec  */
/** @var array<string, string> $i18n */
$i18n = is_array($i18n ?? null) ? $i18n : [];
$t = static function (string $key) use ($i18n): string {
    return htmlspecialchars($i18n[$key] ?? '', ENT_QUOTES, 'UTF-8');
};
$htmlLang = $t('html_lang');
if ($htmlLang === '') {
    $htmlLang = 'en';
}
$safeUrl  = htmlspecialchars($target_url ?? '', ENT_QUOTES, 'UTF-8');
$jsUrl    = addslashes($target_url ?? '');
$delay    = max(500, (int)($delay_ms ?? 1500));
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
:root{--cyan:#22d3ee;--red:#e63946;--pitch:#0d1b2a}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
  background:#050d14;
  color:#fff;
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
  content:'';position:fixed;inset:0;z-index:0;
  background:
    radial-gradient(ellipse 90% 60% at 50% -25%,rgba(0,180,216,.22),transparent 55%),
    radial-gradient(ellipse 50% 40% at 100% 80%,rgba(230,57,70,.12),transparent 50%);
  pointer-events:none;
}
.card{
  position:relative;z-index:1;
  background:linear-gradient(155deg,rgba(27,40,56,.88),rgba(13,27,42,.94));
  border:1px solid rgba(0,180,216,.2);
  border-radius:24px;
  padding:clamp(28px,5vw,38px) clamp(22px,4vw,30px);
  max-width:440px;width:100%;
  text-align:center;
  box-shadow:0 24px 56px rgba(0,0,0,.5),0 0 60px -20px rgba(0,180,216,.2);
  backdrop-filter:blur(12px);
}
.icon-wrap{
  width:76px;height:76px;margin:0 auto 14px;border-radius:22px;
  display:grid;place-items:center;font-size:2.4rem;
  background:linear-gradient(145deg,rgba(0,180,216,.2),rgba(230,57,70,.12));
  border:1px solid rgba(255,255,255,.08);
  box-shadow:0 12px 28px rgba(0,180,216,.2);
}
.live-dot{
  display:inline-flex;align-items:center;gap:8px;
  background:rgba(230,57,70,.14);color:#fca5a5;
  border:1px solid rgba(230,57,70,.35);
  padding:6px 16px;border-radius:999px;
  font-size:.72rem;font-weight:800;letter-spacing:.12em;
  margin-bottom:16px;text-transform:uppercase;
}
.live-dot::before{
  content:'';width:8px;height:8px;background:#e63946;border-radius:50%;
  box-shadow:0 0 12px #e63946;animation:blink 1s infinite;
}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.35}}
h1{font-size:clamp(1.32rem,4vw,1.65rem);font-weight:800;line-height:1.2;margin-bottom:10px;letter-spacing:-.02em}
h1 span{color:var(--cyan);text-shadow:0 0 24px rgba(0,180,216,.35)}
.subtitle{color:#94a3b8;font-size:.92rem;margin-bottom:22px;line-height:1.55}
.match-preview{
  background:rgba(0,180,216,.08);border:1px solid rgba(0,180,216,.18);
  border-radius:16px;padding:16px;margin-bottom:22px;
}
.match-teams{display:flex;align-items:center;justify-content:space-between;font-weight:800;font-size:.95rem;margin-bottom:12px;color:#f1f5f9}
.vs{color:#64748b;font-size:.78rem;font-weight:600}
.odds-row{display:flex;gap:10px}
.odd-chip{
  flex:1;background:rgba(0,0,0,.2);border:1px solid rgba(0,180,216,.2);
  border-radius:12px;padding:10px 6px;text-align:center;
}
.odd-chip .label{font-size:.65rem;color:#94a3b8;margin-bottom:4px}
.odd-chip .val{font-weight:800;font-size:1.05rem;color:var(--cyan)}
.perks{display:flex;flex-direction:column;gap:9px;margin-bottom:26px;text-align:left}
.perk{display:flex;align-items:center;gap:11px;font-size:.88rem;color:#cbd5e1}
.perk .check{color:var(--cyan);font-size:1.05rem;flex-shrink:0}
.cta-btn{
  display:block;background:linear-gradient(135deg,#00b4d8,#0891b2);
  color:#fff;text-decoration:none;font-size:1.05rem;font-weight:800;
  padding:17px 24px;border-radius:14px;width:100%;cursor:pointer;border:none;
  letter-spacing:.02em;
  box-shadow:0 8px 28px rgba(0,180,216,.38),inset 0 1px 0 rgba(255,255,255,.12);
  transition:transform .15s,filter .15s;
}
.cta-btn:hover{transform:translateY(-2px);filter:brightness(1.06)}
.timer{margin-top:16px;color:#64748b;font-size:.82rem}
.timer span{color:var(--cyan);font-weight:800}
.progress-bar{width:100%;height:4px;background:rgba(255,255,255,.08);border-radius:4px;margin-top:14px;overflow:hidden}
.progress-fill{height:100%;background:linear-gradient(90deg,#00b4d8,#e63946);border-radius:4px;width:0%;transition:width linear}
.disclaimer{margin-top:18px;font-size:.68rem;color:#475569;line-height:1.45}
</style>
</head>
<body>

<div class="card">
  <div class="icon-wrap">⚽</div>
  <div class="live-dot"><?= $t('badge_live') ?></div>

  <h1><?= $t('h1_line1') ?> <span><?= $t('h1_span') ?></span><br><?= $t('h1_line2') ?></h1>
  <p class="subtitle"><?= $t('subtitle') ?></p>

  <div class="match-preview">
    <div class="match-teams">
      <span><?= $t('team_home') ?></span>
      <span class="vs"><?= $t('vs') ?></span>
      <span><?= $t('team_away') ?></span>
    </div>
    <div class="odds-row">
      <div class="odd-chip"><div class="label"><?= $t('odd_l1') ?></div><div class="val">1.65</div></div>
      <div class="odd-chip"><div class="label"><?= $t('odd_lx') ?></div><div class="val">3.90</div></div>
      <div class="odd-chip"><div class="label"><?= $t('odd_l2') ?></div><div class="val">4.50</div></div>
    </div>
  </div>

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
  requestAnimationFrame(function(){ fill.style.width = '100%'; });

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
