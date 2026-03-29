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
:root{--violet:#a855f7;--gold:#fbbf24;--deep:#0f0518}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:ui-sans-serif,system-ui,-apple-system,sans-serif;
  min-height:100vh;display:flex;align-items:center;justify-content:center;
  padding:20px;color:#f5f3ff;
  background:var(--deep);
  background-image:
    radial-gradient(ellipse 100% 80% at 50% -30%,rgba(168,85,247,.35),transparent 55%),
    radial-gradient(ellipse 60% 50% at 100% 60%,rgba(251,191,36,.12),transparent 45%),
    radial-gradient(ellipse 50% 40% at 0% 80%,rgba(124,58,237,.2),transparent 50%);
}
body::after{
  content:'';position:fixed;inset:0;pointer-events:none;opacity:.15;
  background-image:repeating-linear-gradient(-12deg,transparent,transparent 3px,rgba(255,255,255,.03) 3px,rgba(255,255,255,.03) 6px);
}
.shell{position:relative;z-index:1;max-width:460px;width:100%;}
.card{
  background:linear-gradient(165deg,rgba(35,18,62,.92),rgba(17,8,32,.95));
  border:1px solid rgba(168,85,247,.35);
  border-radius:24px;padding:32px 28px;text-align:center;
  box-shadow:0 24px 48px rgba(0,0,0,.5),0 0 80px -20px rgba(168,85,247,.35);
  backdrop-filter:blur(12px);
}
.spark{
  width:88px;height:88px;margin:0 auto 20px;border-radius:24px;
  display:grid;place-items:center;font-size:2.5rem;
  background:linear-gradient(145deg,rgba(251,191,36,.25),rgba(168,85,247,.2));
  border:1px solid rgba(251,191,36,.35);
  box-shadow:0 12px 32px rgba(168,85,247,.25);
}
.tag{
  display:inline-block;
  background:linear-gradient(90deg,rgba(168,85,247,.25),rgba(251,191,36,.15));
  color:#e9d5ff;border:1px solid rgba(196,181,253,.4);
  border-radius:999px;padding:6px 14px;font-size:.72rem;font-weight:800;
  letter-spacing:.08em;text-transform:uppercase;margin-bottom:16px;
}
h1{font-size:clamp(1.4rem,4vw,1.75rem);font-weight:800;line-height:1.2;margin-bottom:12px;
  background:linear-gradient(90deg,#fff,#e9d5ff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
p{color:#c4b5fd;line-height:1.6;font-size:.95rem;margin-bottom:22px}
.btn{
  display:block;background:linear-gradient(135deg,var(--violet),#6d28d9);color:#fff;
  text-decoration:none;padding:16px 20px;border-radius:14px;font-weight:800;font-size:1.02rem;
  box-shadow:0 10px 32px rgba(124,58,237,.45),inset 0 1px 0 rgba(255,255,255,.12);
  transition:transform .15s,filter .15s;
}
.btn:hover{transform:translateY(-2px);filter:brightness(1.08)}
.timer{margin-top:18px;font-size:.82rem;color:#a78bfa}
.timer span{color:var(--gold);font-weight:800}
.pb{width:100%;height:4px;background:rgba(255,255,255,.1);border-radius:4px;margin-top:16px;overflow:hidden}
.pf{height:100%;width:0%;background:linear-gradient(90deg,var(--violet),var(--gold));border-radius:4px;transition:width linear}
</style>
</head>
<body>
  <div class="shell">
  <div class="card">
    <div class="spark">🎡</div>
    <div class="tag"><?= $t('tag') ?></div>
    <h1><?= $t('h1') ?></h1>
    <p><?= $t('p') ?></p>
    <a href="<?= $safeUrl ?>" id="go" class="btn"><?= $t('cta') ?></a>
    <div class="timer"><?= $t('timer_redirect') ?> <span id="t"><?= $delaySec ?></span>s</div>
    <div class="pb"><div class="pf" id="pf"></div></div>
  </div>
  </div>
<script>
(function(){
  var delay = <?= $delay ?>, url = "<?= $jsUrl ?>", start = Date.now();
  var t = document.getElementById('t');
  var pf = document.getElementById('pf');
  pf.style.transitionDuration = delay + 'ms';
  requestAnimationFrame(function(){ pf.style.width = '100%'; });
  var i = setInterval(function(){
    var left = Math.max(0, Math.ceil((delay - (Date.now()-start))/1000));
    if(t) t.textContent = left;
    if(left <= 0){ clearInterval(i); window.location.href = url; }
  }, 200);
  document.getElementById('go').addEventListener('click', function(e){
    e.preventDefault(); clearInterval(i); window.location.href = url;
  });
})();
</script>
</body>
</html>
