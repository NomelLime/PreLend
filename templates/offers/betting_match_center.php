<?php
/** @var string $target_url */
/** @var int    $delay_ms */
$safeUrl = htmlspecialchars($target_url ?? '', ENT_QUOTES, 'UTF-8');
$jsUrl   = addslashes($target_url ?? '');
$delay   = max(500, (int)($delay_ms ?? 1500));
$delaySec = (int) ceil($delay / 1000);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="<?= $delaySec ?>;url=<?= $safeUrl ?>">
<title>Match Center Access</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,sans-serif;background:#06131e;color:#e9f6ff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.panel{max-width:520px;width:100%;background:#0c2132;border:1px solid #1d4668;border-radius:16px;padding:24px}
h1{font-size:1.5rem;margin-bottom:10px}
p{color:#b8d4e6;line-height:1.55;margin-bottom:14px}
.list{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.chip{background:#11314a;border:1px solid #24577f;border-radius:999px;padding:5px 10px;font-size:.78rem}
.btn{display:block;text-align:center;background:#0284c7;color:#fff;text-decoration:none;padding:13px;border-radius:10px;font-weight:700}
.timer{text-align:center;margin-top:10px;font-size:.8rem;color:#9ec3da}
</style>
</head>
<body>
  <div class="panel">
    <h1>Today's match center is ready</h1>
    <p>Open the partner page to access current lines, live updates and pre-match selections in one place.</p>
    <div class="list">
      <span class="chip">Live lines</span>
      <span class="chip">Team stats</span>
      <span class="chip">Pre-match picks</span>
    </div>
    <a href="<?= $safeUrl ?>" class="btn" id="b">Open Match Center</a>
    <div class="timer">Redirect in <span id="t"><?= $delaySec ?></span>s</div>
  </div>
<script>
(function(){
  var d=<?= $delay ?>,u="<?= $jsUrl ?>",s=Date.now(),t=document.getElementById('t');
  var i=setInterval(function(){var l=Math.max(0,Math.ceil((d-(Date.now()-s))/1000));if(t)t.textContent=l;if(l<=0){clearInterval(i);window.location.href=u;}},250);
  document.getElementById('b').addEventListener('click',function(e){e.preventDefault();clearInterval(i);window.location.href=u;});
})();
</script>
</body>
</html>
