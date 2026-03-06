<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SportsPulse — Live Scores, Match Analysis &amp; Predictions</title>
<meta name="description" content="Latest football, basketball and tennis scores, in-depth match analysis, expert predictions and league standings updated in real time.">
<meta name="robots" content="index,follow">
<meta property="og:title"       content="SportsPulse — Live Scores &amp; Expert Match Analysis">
<meta property="og:description" content="Real-time sports scores, match previews and expert betting tips from professional analysts.">
<meta property="og:type"        content="website">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;color:#1a1a1a;line-height:1.6}
header{background:#0d1b2a;color:#fff;padding:0 20px;display:flex;align-items:center;height:56px;gap:12px}
header .logo{font-size:1.3rem;font-weight:800;letter-spacing:-.5px}
header .logo span{color:#00b4d8}
header .live-badge{background:#e63946;color:#fff;font-size:.7rem;font-weight:700;padding:2px 7px;border-radius:3px;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}
nav{background:#1b2838;padding:0 20px;display:flex;gap:4px;overflow-x:auto}
nav a{color:#ccc;text-decoration:none;font-size:.88rem;padding:12px 14px;white-space:nowrap;border-bottom:3px solid transparent;transition:.15s}
nav a:hover,nav a.active{color:#fff;border-bottom-color:#00b4d8}
.ticker{background:#00b4d8;color:#fff;padding:7px 20px;font-size:.82rem;white-space:nowrap;overflow:hidden}
.ticker span{display:inline-block;animation:scroll 30s linear infinite}
@keyframes scroll{0%{transform:translateX(100vw)}100%{transform:translateX(-100%)}}
.container{max-width:960px;margin:0 auto;padding:20px 16px}
.layout{display:grid;grid-template-columns:1fr 280px;gap:20px}
@media(max-width:640px){.layout{grid-template-columns:1fr}}
.section-title{font-size:1rem;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid #00b4d8;text-transform:uppercase;letter-spacing:.5px}
.match-card{background:#fff;border-radius:8px;padding:14px 16px;margin-bottom:10px;box-shadow:0 1px 4px rgba(0,0,0,.07)}
.match-header{display:flex;justify-content:space-between;align-items:center;font-size:.78rem;color:#888;margin-bottom:10px}
.match-header .league{font-weight:600;color:#00b4d8}
.match-body{display:flex;align-items:center;justify-content:space-between;gap:8px}
.team{flex:1;text-align:center}
.team .name{font-weight:700;font-size:.92rem}
.team .form{font-size:.7rem;color:#888;margin-top:2px}
.score-block{text-align:center;min-width:60px}
.score{font-size:1.5rem;font-weight:800;color:#0d1b2a}
.status{font-size:.72rem;color:#e63946;font-weight:600}
.odds-row{display:flex;gap:8px;margin-top:12px}
.odd{flex:1;text-align:center;background:#f5f7fa;border-radius:6px;padding:7px 4px}
.odd .label{font-size:.7rem;color:#888}
.odd .val{font-weight:700;font-size:.95rem;color:#0d1b2a}
.article{background:#fff;border-radius:8px;padding:16px;margin-bottom:10px;box-shadow:0 1px 4px rgba(0,0,0,.07)}
.article h3{font-size:.95rem;font-weight:700;margin-bottom:6px;line-height:1.3}
.article p{font-size:.85rem;color:#555}
.article .meta{font-size:.75rem;color:#aaa;margin-top:8px}
.sidebar-widget{background:#fff;border-radius:8px;padding:16px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.07)}
.sidebar-widget .section-title{font-size:.85rem}
.stand-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f0f0;font-size:.83rem}
.stand-row:last-child{border:none}
.stand-row .pos{color:#888;width:20px}
.stand-row .team-name{flex:1;font-weight:600}
.stand-row .pts{font-weight:700;color:#0d1b2a}
.prediction-box{background:#e8f4fd;border-left:4px solid #00b4d8;padding:12px;border-radius:6px;margin-bottom:10px}
.prediction-box .tip-label{font-size:.75rem;font-weight:700;color:#00b4d8;text-transform:uppercase;margin-bottom:4px}
.prediction-box p{font-size:.85rem;color:#333}
footer{background:#0d1b2a;color:#777;padding:24px 20px;font-size:.78rem;text-align:center;margin-top:32px}
footer a{color:#999;text-decoration:none}
</style>
</head>
<body>

<header>
  <div class="logo">Sports<span>Pulse</span></div>
  <div class="live-badge">● LIVE</div>
  <span style="margin-left:auto;font-size:.8rem;color:#888">Expert Analysis &amp; Predictions</span>
</header>

<nav>
  <a href="#" class="active">Football</a>
  <a href="#">Basketball</a>
  <a href="#">Tennis</a>
  <a href="#">Hockey</a>
  <a href="#">UFC/MMA</a>
  <a href="#">Cricket</a>
  <a href="#">Predictions</a>
</nav>

<div class="ticker">
  <span>⚽ Man City 2-1 Arsenal (75') &nbsp;|&nbsp; Real Madrid 0-0 Barcelona (HT) &nbsp;|&nbsp; Liverpool 3-0 Chelsea (FT) &nbsp;|&nbsp; 🏀 Lakers 98-104 Celtics (Q3) &nbsp;|&nbsp; 🎾 Djokovic leads 6-4, 3-2 &nbsp;|&nbsp; ⚽ PSG 1-0 Marseille (58')</span>
</div>

<div class="container">
  <div class="layout">

    <div class="main">

      <div class="section-title">Live &amp; Today's Matches</div>

      <div class="match-card">
        <div class="match-header">
          <span class="league">Premier League · Matchday 29</span>
          <span>Today, 20:45</span>
        </div>
        <div class="match-body">
          <div class="team"><div class="name">Man City</div><div class="form">W W W D W</div></div>
          <div class="score-block"><div class="score">2 – 1</div><div class="status">● 75'</div></div>
          <div class="team"><div class="name">Arsenal</div><div class="form">W D W W L</div></div>
        </div>
        <div class="odds-row">
          <div class="odd"><div class="label">1</div><div class="val">1.65</div></div>
          <div class="odd"><div class="label">X</div><div class="val">3.90</div></div>
          <div class="odd"><div class="label">2</div><div class="val">4.50</div></div>
        </div>
      </div>

      <div class="match-card">
        <div class="match-header">
          <span class="league">La Liga · Matchday 27</span>
          <span>Today, 21:00</span>
        </div>
        <div class="match-body">
          <div class="team"><div class="name">Real Madrid</div><div class="form">W W W W W</div></div>
          <div class="score-block"><div class="score">0 – 0</div><div class="status">● HT</div></div>
          <div class="team"><div class="name">Barcelona</div><div class="form">W W L W D</div></div>
        </div>
        <div class="odds-row">
          <div class="odd"><div class="label">1</div><div class="val">2.10</div></div>
          <div class="odd"><div class="label">X</div><div class="val">3.30</div></div>
          <div class="odd"><div class="label">2</div><div class="val">3.20</div></div>
        </div>
      </div>

      <div class="match-card">
        <div class="match-header">
          <span class="league">Champions League · Round of 16</span>
          <span>Tomorrow, 21:00</span>
        </div>
        <div class="match-body">
          <div class="team"><div class="name">Bayern Munich</div><div class="form">W W W D W</div></div>
          <div class="score-block"><div class="score">vs</div><div class="status">Preview</div></div>
          <div class="team"><div class="name">Inter Milan</div><div class="form">D W W L W</div></div>
        </div>
        <div class="odds-row">
          <div class="odd"><div class="label">1</div><div class="val">1.90</div></div>
          <div class="odd"><div class="label">X</div><div class="val">3.50</div></div>
          <div class="odd"><div class="label">2</div><div class="val">3.80</div></div>
        </div>
      </div>

      <div class="section-title" style="margin-top:24px">Expert Analysis</div>

      <div class="prediction-box">
        <div class="tip-label">🔥 Today's Best Tip</div>
        <p><strong>Man City BTTS + Over 2.5</strong> — Both teams averaged 3.1 goals in their last 6 head-to-head meetings. City's attacking form is exceptional; Arsenal must score to stay in contention. Value odds at 1.85.</p>
      </div>

      <div class="article">
        <h3>Tactical Preview: Why Bayern's High Press Could Dismantle Inter's Defence</h3>
        <p>Inter's back four has been exceptionally solid in Serie A, conceding just 18 goals this season. However, their pressing trigger — advancing from deep blocks — leaves them vulnerable to Bayern's vertical transition. Expect 2-3 key chances within the first 20 minutes.</p>
        <div class="meta">By Marco Bellini, Tactical Analyst · 2 hours ago · 5 min read</div>
      </div>

      <div class="article">
        <h3>El Clásico HT: What Needs to Change in the Second Half</h3>
        <p>Real's defensive block has been impeccable, but Barça's positional play is creating dangerous half-spaces on the right channel. A second-half substitution bringing Yamal into the centre could be the key tactical switch.</p>
        <div class="meta">By David Romero, La Liga Correspondent · Live Update · 3 min read</div>
      </div>

    </div>

    <aside>

      <div class="sidebar-widget">
        <div class="section-title">Premier League Table</div>
        <div class="stand-row"><span class="pos">1</span><span class="team-name">Man City</span><span class="pts">68 pts</span></div>
        <div class="stand-row"><span class="pos">2</span><span class="team-name">Arsenal</span><span class="pts">64 pts</span></div>
        <div class="stand-row"><span class="pos">3</span><span class="team-name">Liverpool</span><span class="pts">63 pts</span></div>
        <div class="stand-row"><span class="pos">4</span><span class="team-name">Aston Villa</span><span class="pts">57 pts</span></div>
        <div class="stand-row"><span class="pos">5</span><span class="team-name">Tottenham</span><span class="pts">54 pts</span></div>
        <div class="stand-row"><span class="pos">6</span><span class="team-name">Chelsea</span><span class="pts">51 pts</span></div>
      </div>

      <div class="sidebar-widget">
        <div class="section-title">This Week's Record</div>
        <div class="stand-row"><span class="team-name">Tips Published</span><span class="pts">24</span></div>
        <div class="stand-row"><span class="team-name">Won</span><span class="pts" style="color:#2eb86e">17</span></div>
        <div class="stand-row"><span class="team-name">Lost</span><span class="pts" style="color:#e63946">5</span></div>
        <div class="stand-row"><span class="team-name">Pending</span><span class="pts" style="color:#f5a623">2</span></div>
        <div class="stand-row"><span class="team-name">Success Rate</span><span class="pts">77%</span></div>
      </div>

    </aside>

  </div>
</div>

<footer>
  <p>© 2025 SportsPulse · <a href="#">About</a> · <a href="#">Privacy</a> · <a href="#">Responsible Gambling</a></p>
  <p style="margin-top:8px">Odds are for informational purposes only. Gambling involves financial risk. 18+ only.</p>
</footer>

</body>
</html>
