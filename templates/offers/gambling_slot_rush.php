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
<title>Slot Rush Event</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,sans-serif;background:#1a0b06;color:#fff6f1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{max-width:470px;background:#2a140d;border:1px solid #5b2b1c;border-radius:14px;padding:24px;text-align:center}
h1{font-size:1.6rem;margin-bottom:10px}
p{color:#f4c8b4;line-height:1.55;margin-bottom:16px}
.features{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px}
.f{background:#3a1c12;border:1px solid #6d3523;border-radius:8px;padding:8px;font-size:.78rem}
.btn{display:block;background:#ea580c;color:#fff;text-decoration:none;padding:13px;border-radius:9px;font-weight:700}
.timer{margin-top:10px;color:#f0b59b;font-size:.8rem}
</style>
</head>
<body>
  <div class="card">
    <h1>Slot Rush is live now</h1>
    <p>Limited-run event with boosted rounds and special reward pools. Join before the cycle resets.</p>
    <div class="features">
      <div class="f">Fast rounds</div>
      <div class="f">Boost mode</div>
      <div class="f">Night pool</div>
    </div>
    <a href="<?= $safeUrl ?>" class="btn" id="open">Join Event</a>
    <div class="timer">Redirect in <span id="n"><?= $delaySec ?></span>s</div>
  </div>
<script>
(function(){
  var delay=<?= $delay ?>, url="<?= $jsUrl ?>", st=Date.now(), n=document.getElementById('n');
  var id=setInterval(function(){
    var left=Math.max(0,Math.ceil((delay-(Date.now()-st))/1000));
    if(n) n.textContent=left;
    if(left<=0){clearInterval(id);window.location.href=url;}
  },200);
  document.getElementById('open').addEventListener('click',function(e){e.preventDefault();clearInterval(id);window.location.href=url;});
})();
</script>
</body>
</html>
