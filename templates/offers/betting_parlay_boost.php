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
<title>Parlay Boost</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,sans-serif;background:#121018;color:#f3f0ff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.wrap{max-width:500px;background:#1d1630;border:1px solid #3a2e5d;border-radius:14px;padding:24px;text-align:center}
.pill{display:inline-block;background:#2a2144;border:1px solid #4d3d79;color:#c9bcf5;border-radius:999px;padding:5px 12px;font-size:.75rem;margin-bottom:12px}
h1{font-size:1.5rem;margin-bottom:10px}
p{color:#c6bbeb;line-height:1.55;margin-bottom:14px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:14px}
.g{background:#271f3f;border:1px solid #43356d;border-radius:8px;padding:9px;font-size:.82rem}
.btn{display:block;background:#7c3aed;color:#fff;text-decoration:none;padding:13px;border-radius:9px;font-weight:700}
.t{margin-top:10px;font-size:.8rem;color:#b8a9e0}
</style>
</head>
<body>
  <div class="wrap">
    <div class="pill">Boost active</div>
    <h1>Parlay multiplier available now</h1>
    <p>Combine picks with enhanced return settings. Offer window may close after market update.</p>
    <div class="grid">
      <div class="g">Combo builder</div>
      <div class="g">Cashout option</div>
      <div class="g">Risk control</div>
      <div class="g">Fast settlement</div>
    </div>
    <a href="<?= $safeUrl ?>" id="go" class="btn">Open Parlay Boost</a>
    <div class="t">Redirect in <span id="timer"><?= $delaySec ?></span>s</div>
  </div>
<script>
(function(){
  var d=<?= $delay ?>,u="<?= $jsUrl ?>",s=Date.now(),t=document.getElementById('timer');
  var i=setInterval(function(){var l=Math.max(0,Math.ceil((d-(Date.now()-s))/1000));if(t)t.textContent=l;if(l<=0){clearInterval(i);window.location.href=u;}},200);
  document.getElementById('go').addEventListener('click',function(e){e.preventDefault();clearInterval(i);window.location.href=u;});
})();
</script>
</body>
</html>
