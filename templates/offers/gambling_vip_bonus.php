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
body{font-family:Inter,system-ui,sans-serif;background:#0b0f1b;color:#eef2ff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.box{max-width:500px;background:#111827;border:1px solid #2a3551;border-radius:16px;padding:26px}
h1{font-size:1.5rem;margin-bottom:10px}
p{color:#b8c2dd;font-size:.93rem;line-height:1.55;margin-bottom:14px}
ul{margin:0 0 16px 18px;color:#c6d0ea}
li{margin:6px 0}
.cta{display:block;text-align:center;background:#2563eb;color:#fff;text-decoration:none;padding:13px;border-radius:10px;font-weight:700}
.meta{margin-top:10px;text-align:center;font-size:.8rem;color:#9ba8c9}
</style>
</head>
<body>
  <div class="box">
    <h1><?= $t('h1') ?></h1>
    <p><?= $t('p') ?></p>
    <ul>
      <li><?= $t('li1') ?></li>
      <li><?= $t('li2') ?></li>
      <li><?= $t('li3') ?></li>
    </ul>
    <a href="<?= $safeUrl ?>" class="cta" id="cta"><?= $t('cta') ?></a>
    <div class="meta"><?= $t('meta_redirect') ?> <span id="clock"><?= $delaySec ?></span>s</div>
  </div>
<script>
(function(){
  var delay=<?= $delay ?>, url="<?= $jsUrl ?>", s=Date.now(), c=document.getElementById('clock');
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
