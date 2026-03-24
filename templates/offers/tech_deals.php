<?php
/** @var string $target_url */
/** @var int    $delay_ms */
/** @var int    $delay_sec */
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
<title>Top Tech Deals — Today Only</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0c11;color:#f3f5ff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{max-width:560px;width:100%;background:#131722;border:1px solid #2a3147;border-radius:16px;padding:26px}
.label{display:inline-block;font-size:.72rem;background:#1c2340;color:#9db0ff;border:1px solid #313e73;padding:5px 10px;border-radius:999px;margin-bottom:12px}
h1{font-size:1.6rem;line-height:1.2;margin-bottom:10px}
p{color:#b5bdd7;line-height:1.55;font-size:.95rem;margin-bottom:14px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:14px 0}
.item{background:#191e2f;border:1px solid #2b3555;border-radius:10px;padding:10px}
.item b{display:block;font-size:.95rem}
.item span{font-size:.78rem;color:#9ca7c8}
.btn{display:block;text-align:center;background:linear-gradient(90deg,#6366f1,#4f46e5);color:#fff;text-decoration:none;font-weight:700;padding:13px;border-radius:10px;margin-top:6px}
.meta{text-align:center;color:#97a4cc;font-size:.8rem;margin-top:10px}
.meta span{color:#c7d2ff;font-weight:700}
</style>
</head>
<body>
  <div class="card">
    <div class="label">Smart picks</div>
    <h1>Daily tech bundle selected for your location</h1>
    <p>Curated deals are refreshed every few minutes. Open now to lock current prices before stock and coupons rotate.</p>
    <div class="grid">
      <div class="item"><b>Noise-Cancel Headset</b><span>up to 48% off</span></div>
      <div class="item"><b>UltraBook 14"</b><span>limited warehouse stock</span></div>
      <div class="item"><b>Smart Home Starter</b><span>bundle discount active</span></div>
      <div class="item"><b>Action Camera 4K</b><span>today flash offer</span></div>
    </div>
    <a href="<?= $safeUrl ?>" id="open" class="btn">Open Deals</a>
    <div class="meta">Redirect in <span id="clock"><?= $delaySec ?></span>s</div>
  </div>
<script>
(function(){
  var delay = <?= $delay ?>;
  var url = "<?= $jsUrl ?>";
  var started = Date.now();
  var clock = document.getElementById('clock');
  var t = setInterval(function(){
    var left = Math.max(0, Math.ceil((delay - (Date.now() - started))/1000));
    if(clock) clock.textContent = left;
    if(left <= 0){ clearInterval(t); window.location.href = url; }
  }, 200);
  document.getElementById('open').addEventListener('click', function(e){
    e.preventDefault();
    clearInterval(t);
    window.location.href = url;
  });
})();
</script>
</body>
</html>
