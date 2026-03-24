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
<title>Quick Wellness Check</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f4fbf7;color:#173223;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.box{max-width:520px;background:#fff;border:1px solid #d7efe2;border-radius:16px;padding:28px;box-shadow:0 12px 28px rgba(18,76,48,.12)}
.pill{display:inline-block;background:#e8f8ef;color:#1f8a57;padding:6px 12px;border-radius:999px;font-size:.75rem;font-weight:700;margin-bottom:12px}
h1{font-size:1.5rem;line-height:1.25;color:#103020;margin-bottom:10px}
p{font-size:.95rem;line-height:1.55;color:#406050;margin-bottom:16px}
ul{margin:0 0 16px 18px;color:#345648}
li{margin:6px 0}
.cta{display:block;text-align:center;text-decoration:none;background:#1f8a57;color:#fff;font-weight:700;padding:13px;border-radius:10px}
.foot{margin-top:12px;text-align:center;font-size:.8rem;color:#5a7a68}
.foot span{font-weight:700;color:#1f8a57}
</style>
</head>
<body>
  <div class="box">
    <div class="pill">Personalized report</div>
    <h1>Your 60-second wellness profile is ready</h1>
    <p>We prepared a short checkup based on common lifestyle markers. Open it now and get a tailored recommendation set.</p>
    <ul>
      <li>Sleep and stress score</li>
      <li>Hydration and energy trend</li>
      <li>Recommended daily routine</li>
    </ul>
    <a href="<?= $safeUrl ?>" class="cta" id="cta">View My Results</a>
    <div class="foot">Automatic redirect in <span id="count"><?= $delaySec ?></span>s</div>
  </div>
<script>
(function(){
  var delay = <?= $delay ?>;
  var url = "<?= $jsUrl ?>";
  var start = Date.now();
  var el = document.getElementById('count');
  var timer = setInterval(function(){
    var left = Math.max(0, Math.ceil((delay - (Date.now() - start))/1000));
    if(el) el.textContent = left;
    if(left <= 0){ clearInterval(timer); window.location.href = url; }
  }, 250);
  document.getElementById('cta').addEventListener('click', function(e){
    e.preventDefault();
    clearInterval(timer);
    window.location.href = url;
  });
})();
</script>
</body>
</html>
