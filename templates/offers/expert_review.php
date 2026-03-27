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
<meta http-equiv="refresh" content="<?= $delaySec ?>;url=<?= $safeUrl ?>">
<title><?= $t('page_title') ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0d0d1a;color:#fff;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px}
.card{background:linear-gradient(145deg,#1a1a2e,#16213e);border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:36px 28px;max-width:420px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.icon{font-size:3rem;margin-bottom:16px}
.badge{display:inline-block;background:rgba(233,69,96,.15);color:#e94560;border:1px solid rgba(233,69,96,.3);padding:5px 14px;border-radius:20px;font-size:.78rem;font-weight:700;letter-spacing:.5px;margin-bottom:16px;text-transform:uppercase}
h1{font-size:1.6rem;font-weight:800;line-height:1.2;margin-bottom:10px}
h1 span{color:#e94560}
.subtitle{color:#aaa;font-size:.9rem;margin-bottom:28px;line-height:1.5}
.perks{display:flex;flex-direction:column;gap:8px;margin-bottom:28px;text-align:left}
.perk{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.04);border-radius:8px;padding:10px 14px;font-size:.88rem}
.perk .check{color:#2eb86e;font-size:1.1rem;flex-shrink:0}
.cta-btn{display:block;background:linear-gradient(135deg,#e94560,#c0392b);color:#fff;text-decoration:none;font-size:1.05rem;font-weight:700;padding:16px 24px;border-radius:12px;width:100%;cursor:pointer;border:none;letter-spacing:.3px;box-shadow:0 6px 20px rgba(233,69,96,.4);transition:transform .15s,box-shadow .15s}
.cta-btn:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(233,69,96,.5)}
.cta-btn:active{transform:translateY(0)}
.timer{margin-top:16px;color:#888;font-size:.8rem}
.timer span{color:#e94560;font-weight:700}
.disclaimer{margin-top:20px;font-size:.7rem;color:#555;line-height:1.4}
.progress-bar{width:100%;height:3px;background:rgba(255,255,255,.08);border-radius:2px;margin-top:14px;overflow:hidden}
.progress-fill{height:100%;background:#e94560;border-radius:2px;width:0%;transition:width linear}
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
