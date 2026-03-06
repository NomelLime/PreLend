<?php
/** @var string $target_url */
/** @var int    $delay_ms   */
/** @var int    $delay_sec  */
$safeUrl  = htmlspecialchars($target_url ?? '', ENT_QUOTES, 'UTF-8');
$jsUrl    = addslashes($target_url ?? '');
$delay    = max(500, (int)($delay_ms ?? 1500));
$delaySec = (int) ceil($delay / 1000);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="<?= $delaySec ?>;url=<?= $safeUrl ?>">
<title>Today's Best Odds — SportsPulse</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0d1b2a;color:#fff;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px}
.card{background:linear-gradient(145deg,#1b2838,#0d1b2a);border:1px solid rgba(0,180,216,.15);border-radius:20px;padding:36px 28px;max-width:420px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.live-dot{display:inline-flex;align-items:center;gap:6px;background:rgba(230,57,70,.15);color:#e63946;border:1px solid rgba(230,57,70,.3);padding:5px 14px;border-radius:20px;font-size:.78rem;font-weight:700;letter-spacing:.5px;margin-bottom:16px;text-transform:uppercase}
.live-dot::before{content:'';width:7px;height:7px;background:#e63946;border-radius:50%;animation:blink 1s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}
.icon{font-size:2.6rem;margin-bottom:12px}
h1{font-size:1.6rem;font-weight:800;line-height:1.2;margin-bottom:10px}
h1 span{color:#00b4d8}
.subtitle{color:#aaa;font-size:.9rem;margin-bottom:22px;line-height:1.5}
.match-preview{background:rgba(0,180,216,.07);border:1px solid rgba(0,180,216,.15);border-radius:12px;padding:14px;margin-bottom:20px}
.match-teams{display:flex;align-items:center;justify-content:space-between;font-weight:700;font-size:.95rem;margin-bottom:10px}
.vs{color:#aaa;font-size:.8rem;font-weight:400}
.odds-row{display:flex;gap:8px}
.odd-chip{flex:1;background:rgba(255,255,255,.06);border-radius:8px;padding:8px 4px;text-align:center}
.odd-chip .label{font-size:.68rem;color:#888;margin-bottom:2px}
.odd-chip .val{font-weight:800;font-size:1rem;color:#00b4d8}
.perks{display:flex;flex-direction:column;gap:7px;margin-bottom:24px;text-align:left}
.perk{display:flex;align-items:center;gap:10px;font-size:.87rem;color:#ccc}
.perk .check{color:#00b4d8;font-size:1rem;flex-shrink:0}
.cta-btn{display:block;background:linear-gradient(135deg,#00b4d8,#0077a8);color:#fff;text-decoration:none;font-size:1.05rem;font-weight:700;padding:16px 24px;border-radius:12px;width:100%;cursor:pointer;border:none;letter-spacing:.3px;box-shadow:0 6px 20px rgba(0,180,216,.35);transition:transform .15s,box-shadow .15s}
.cta-btn:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(0,180,216,.45)}
.timer{margin-top:16px;color:#777;font-size:.8rem}
.timer span{color:#00b4d8;font-weight:700}
.progress-bar{width:100%;height:3px;background:rgba(255,255,255,.07);border-radius:2px;margin-top:12px;overflow:hidden}
.progress-fill{height:100%;background:#00b4d8;border-radius:2px;width:0%;transition:width linear}
.disclaimer{margin-top:18px;font-size:.7rem;color:#555;line-height:1.4}
</style>
</head>
<body>

<div class="card">
  <div class="icon">⚽</div>
  <div class="live-dot">Live Odds Available</div>

  <h1>Get the <span>Best Odds</span><br>on Today's Matches</h1>
  <p class="subtitle">Our analysts identified the highest-value bet of the day. Join thousands of winners — offer expires at midnight.</p>

  <div class="match-preview">
    <div class="match-teams">
      <span>Man City</span>
      <span class="vs">vs</span>
      <span>Arsenal</span>
    </div>
    <div class="odds-row">
      <div class="odd-chip"><div class="label">1</div><div class="val">1.65</div></div>
      <div class="odd-chip"><div class="label">X</div><div class="val">3.90</div></div>
      <div class="odd-chip"><div class="label">2</div><div class="val">4.50</div></div>
    </div>
  </div>

  <div class="perks">
    <div class="perk"><span class="check">✓</span> Up to $100 free bet for new users</div>
    <div class="perk"><span class="check">✓</span> Live in-play betting on 1000+ events</div>
    <div class="perk"><span class="check">✓</span> Cash out available on all pre-match bets</div>
    <div class="perk"><span class="check">✓</span> Instant withdrawals via bank & crypto</div>
  </div>

  <a href="<?= $safeUrl ?>" class="cta-btn" id="ctaBtn">
    Bet Now — Best Odds →
  </a>

  <div class="timer">Redirecting in <span id="countdown"><?= $delaySec ?></span>s</div>
  <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>

  <p class="disclaimer">18+ only. Gambling can be addictive. Please bet responsibly. T&Cs apply.</p>
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
