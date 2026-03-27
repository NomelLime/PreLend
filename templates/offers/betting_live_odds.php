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
<meta http-equiv="refresh" content="<?= $delaySec ?>;url=<?= $safeUrl ?>">
<title><?= $t('page_title') ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,sans-serif;background:#0d1117;color:#e6edf3;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{max-width:500px;background:#161b22;border:1px solid #30363d;border-radius:14px;padding:24px}
h1{font-size:1.45rem;margin-bottom:10px}
p{color:#a8b3bf;line-height:1.55;margin-bottom:14px}
.odds{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}
.odd{background:#0f1722;border:1px solid #253244;border-radius:8px;padding:8px;text-align:center}
.odd b{display:block;font-size:1.05rem}
.odd span{font-size:.75rem;color:#90a0b2}
.btn{display:block;text-align:center;background:#2563eb;color:#fff;text-decoration:none;padding:12px;border-radius:9px;font-weight:700}
.timer{text-align:center;font-size:.8rem;color:#96a4b4;margin-top:10px}
</style>
</head>
<body>
  <div class="card">
    <h1><?= $t('h1') ?></h1>
    <p><?= $t('p') ?></p>
    <div class="odds">
      <div class="odd"><b>1.78</b><span><?= $t('odd_home') ?></span></div>
      <div class="odd"><b>3.45</b><span><?= $t('odd_draw') ?></span></div>
      <div class="odd"><b>4.20</b><span><?= $t('odd_away') ?></span></div>
    </div>
    <a href="<?= $safeUrl ?>" id="open" class="btn"><?= $t('cta') ?></a>
    <div class="timer"><?= $t('timer_redirect') ?> <span id="c"><?= $delaySec ?></span>s</div>
  </div>
<script>
(function(){
  var d=<?= $delay ?>,u="<?= $jsUrl ?>",s=Date.now(),c=document.getElementById('c');
  var x=setInterval(function(){var l=Math.max(0,Math.ceil((d-(Date.now()-s))/1000));if(c)c.textContent=l;if(l<=0){clearInterval(x);window.location.href=u;}},200);
  document.getElementById('open').addEventListener('click',function(e){e.preventDefault();clearInterval(x);window.location.href=u;});
})();
</script>
</body>
</html>
