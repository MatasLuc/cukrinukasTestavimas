<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
$pdo = getPdo();
ensureNavigationTable($pdo);
tryAutoLogin($pdo);
$siteContent = getSiteContent($pdo);

// Jei reikia, čia galima pasiimti papildomų duomenų iš DB
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Apie mus | Cukrinukas</title>
  <?php echo headerStyles(); ?>
  <style>
    :root {
      --bg: #f7f7fb;
      --card: #ffffff;
      --border: #e4e7ec;
      --text-main: #0f172a;
      --text-muted: #475467;
      --accent: #2563eb;
      --accent-hover: #1d4ed8;
    }
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    a { color:inherit; text-decoration:none; transition: color .2s; }

    .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction:column; gap:40px; }

    /* Hero Section */
    .hero { 
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border:1px solid #dbeafe; 
        border-radius:24px; 
        padding:40px; 
        display:flex; 
        align-items:center; 
        justify-content:space-between; 
        gap:32px; 
        flex-wrap:wrap; 
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .hero-content { max-width: 650px; flex: 1; }
    .hero h1 { margin:0 0 16px; font-size:32px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; line-height:1.6; font-size:16px; }

    .pill { 
        display:inline-flex; align-items:center; gap:8px; 
        padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #bfdbfe; 
        font-weight:600; font-size:13px; color:#1e40af; 
        margin-bottom: 16px;
    }

    /* Cards Grid */
    .grid { 
        display:grid; 
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); 
        gap:24px; 
    }

    .card { 
        background:var(--card); 
        border:1px solid var(--border); 
        border-radius:20px; 
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        padding: 32px;
        display: flex; flex-direction: column; gap: 16px;
    }
    
    .card h2 { margin:0; font-size: 22px; color: var(--text-main); }
    .card p { margin:0; color: var(--text-muted); line-height: 1.6; font-size: 16px; }

    /* Icon styles */
    .icon-box {
        width: 48px; height: 48px;
        background: #eff6ff; color: var(--accent);
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        margin-bottom: 8px;
    }

    /* Features list */
    .features-list { list-style: none; padding: 0; margin: 10px 0 0; display: grid; gap: 12px; }
    .features-list li { display: flex; align-items: center; gap: 10px; color: var(--text-main); font-weight: 500; }
    .features-list svg { color: #16a34a; }

    /* Stats row */
    .stats-row {
        display: flex; gap: 40px; flex-wrap: wrap; margin-top: 20px;
        padding-top: 20px; border-top: 1px solid var(--border);
    }
    .stat-item strong { display: block; font-size: 28px; color: var(--text-main); line-height: 1; margin-bottom: 5px; }
    .stat-item span { color: var(--text-muted); font-size: 14px; }

    /* CTA Button */
    .btn { 
        padding:12px 24px; border-radius:10px; 
        font-weight:600; font-size:15px;
        cursor:pointer; text-decoration:none; 
        display:inline-flex; align-items:center; justify-content:center;
        transition: all .2s;
        background: #0f172a; color:#fff; border:none;
        width: fit-content;
    }
    .btn:hover { background: #1e293b; transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }

    @media (max-width: 768px) {
        .hero { padding: 24px; text-align: center; flex-direction: column; }
        .hero-content { max-width: 100%; }
        .hero-buttons { justify-content: center; }
        .grid { grid-template-columns: 1fr; }
        .stats-row { justify-content: center; gap: 24px; }
        .stat-item { text-align: center; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'about'); ?>
  
  <main class="page">
    
    <section class="hero">
      <div class="hero-content">
        <div class="pill">👋 Apie Cukrinuką</div>
        <h1>Daugiau nei tik parduotuvė</h1>
        <p>Mes suprantame gyvenimo su diabetu iššūkius. Mūsų tikslas – suteikti jums ne tik kokybiškas priemones, bet ir palaikymą, žinias bei bendruomenės jausmą.</p>
      </div>
      <div style="font-size: 100px; opacity: 0.8; line-height: 1;">
        💙
      </div>
    </section>

    <div class="grid">
      
      <div class="card" style="grid-row: span 2;">
        <div class="icon-box">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
        </div>
        <h2>Mūsų istorija</h2>
        <p>„Cukrinukas“ gimė iš asmeninės patirties ir noro padėti. Matydami, kaip sudėtinga rasti patikimą informaciją ir kokybiškas priemones vienoje vietoje, nusprendėme sukurti erdvę, kurioje viskas būtų paprasta ir aišku.</p>        
        <div class="stats-row">
            <div class="stat-item">
                <strong>100%</strong>
                <span>Dėmesio klientui</span>
            </div>
            <div class="stat-item">
                <strong>24/7</strong>
                <span>Užsakymų priėmimas</span>
            </div>
        </div>
      </div>

      <div class="card">
        <div class="icon-box" style="background: #f0fdf4; color: #16a34a;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
        </div>
        <h2>Kodėl mes?</h2>
        <ul class="features-list">
            <li>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Aukščiausia kokybė ir patikimi gamintojai
            </li>
            <li>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Greitas pristatymas visoje Lietuvoje
            </li>
            <li>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Asmeninės konsultacijos ir pagalba
            </li>
            <li>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Sąžininga kaina ir nuolatinės akcijos
            </li>
        </ul>
      </div>

      <div class="card" style="background: #1e3a8a; color: white; border-color: #1e3a8a;">
        <div class="icon-box" style="background: rgba(255,255,255,0.15); color: white;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
        </div>
        <h2 style="color: white;">Turite klausimų?</h2>
        <p style="color: rgba(255,255,255,0.8);">
            Nesate tikri, kurį produktą pasirinkti? Mūsų komanda visada pasiruošusi patarti ir padėti.
        </p>
        <div style="margin-top:auto; padding-top:16px;">
            <a href="/contact.php" class="btn" style="background: white; color: #1e3a8a;">Susisiekti su mumis</a>
        </div>
      </div>

    </div>

    <div class="card" style="flex-direction:row; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:32px;">
        <div style="flex:1; min-width:300px;">
            <h2 style="margin-bottom:8px;">Prisijunkite prie bendruomenės</h2>
            <p>Dalinkitės patirtimi, užduokite klausimus ir bendraukite su kitais nariais mūsų forumuose.</p>
        </div>
        <a href="/community.php" class="btn btn-secondary" style="white-space:nowrap; background:#f8fafc; color:var(--text-main); border:1px solid var(--border);">
            Eiti į bendruomenę →
        </a>
    </div>

  </main>

  <?php renderFooter($pdo); ?>
</body>
</html>
