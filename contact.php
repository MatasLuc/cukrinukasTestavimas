<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
$pdo = getPdo();
ensureNavigationTable($pdo);
tryAutoLogin($pdo);
$siteContent = getSiteContent($pdo);

// Jei ateityje norėsite pridėti kontaktų formą, čia galėsite apdoroti pranešimus
$messages = [];
$errors = [];
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kontaktai | Cukrinukas</title>
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
    .hero-content { max-width: 600px; flex: 1; }
    .hero h1 { margin:0 0 12px; font-size:32px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; line-height:1.6; font-size:16px; }

    .pill { 
        display:inline-flex; align-items:center; gap:8px; 
        padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #bfdbfe; 
        font-weight:600; font-size:13px; color:#1e40af; 
        margin-bottom: 16px;
    }

    /* Cards */
    .cards-grid { 
        display:grid; 
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); 
        gap:24px; 
    }
    
    .card { 
        background:var(--card); 
        border:1px solid var(--border); 
        border-radius:20px; 
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        transition: transform .2s, box-shadow .2s;
        height: 100%;
        display: flex; flex-direction: column;
    }
    .card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border-color: #cbd5e1; }
    
    .card-body { padding: 32px; flex-grow: 1; display: flex; flex-direction: column; gap: 16px; }

    .card h2 { margin:0 0 8px; font-size: 22px; color: var(--text-main); }
    .card p { margin:0; color: var(--text-muted); line-height: 1.6; }

    /* Contact List Styles */
    .contact-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 16px; }
    .contact-item { display: flex; align-items: center; gap: 12px; font-size: 16px; color: var(--text-main); }
    .contact-icon { 
        width: 40px; height: 40px; 
        background: #eff6ff; color: var(--accent);
        border-radius: 10px; 
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }

    /* Social Buttons */
    .social-links { display: flex; gap: 12px; margin-top: 10px; }
    .social-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 48px; height: 48px;
        border-radius: 12px;
        background: #f8fafc; border: 1px solid var(--border);
        color: var(--text-muted);
        font-size: 20px;
        transition: all 0.2s;
    }
    .social-btn:hover {
        background: var(--accent); color: #fff; border-color: var(--accent);
        transform: translateY(-2px);
    }

    /* Buttons */
    .btn { 
        padding:12px 24px; border-radius:10px; 
        font-weight:600; font-size:15px;
        cursor:pointer; text-decoration:none; 
        display:inline-flex; align-items:center; justify-content:center;
        transition: all .2s;
        background: #0f172a; color:#fff; border:none;
        margin-top: 10px;
        align-self: flex-start;
    }
    .btn:hover { background: #1e293b; transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }

    .btn-secondary {
        background: #fff; color: var(--text-main); border: 1px solid var(--border);
    }
    .btn-secondary:hover { border-color: var(--accent); color: var(--accent); background: #f8fafc; }

    /* List with checkmarks for 3rd card */
    .check-list { list-style: none; padding: 0; margin: 20px 0 0; display: flex; flex-direction: column; gap: 12px; }
    .check-list li { display: flex; gap: 10px; align-items: flex-start; color: var(--text-main); font-size: 15px; }
    .check-list svg { color: #16a34a; flex-shrink: 0; margin-top: 3px; }

    @media (max-width: 768px) {
        .hero { padding: 24px; flex-direction: column; align-items: stretch; text-align: center; }
        .hero-content { max-width: 100%; }
        .hero-buttons { justify-content: center; }
        .cards-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'contact'); ?>
  
  <main class="page">
    
    <section class="hero">
      <div class="hero-content">
        <div class="pill">📬 Pagalba ir kontaktai</div>
        <h1>Susisiekite su mumis</h1>
        <p>Turite klausimų dėl prekių, savo užsakymo ar kitų Cukrinuko funkcijų? Esame čia, kad padėtume jums rasti geriausius sprendimus.</p>
        <div style="display:flex; gap:12px; margin-top:24px; flex-wrap:wrap;" class="hero-buttons">
            <a href="mailto:labas@cukrinukas.lt" class="btn">Rašyti laišką</a>
        </div>
      </div>
      
      <div style="font-size: 100px; opacity: 0.8; line-height: 1;">
        💌
      </div>
    </section>

    <div class="cards-grid">
      
      <div class="card">
        <div class="card-body">
            <h2 style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:24px;">📞</span> Kontaktai
            </h2>
            <p>Atsakome į užklausas darbo dienomis, stengiamės kuo operatyviau.</p>
            
            <ul class="contact-list" style="margin-top:24px;">
                <li class="contact-item">
                    <div class="contact-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </div>
                    <div>
                        <div style="font-size:13px; color:var(--text-muted);">El. paštas</div>
                        <strong>labas@cukrinukas.lt</strong>
                    </div>
                </li>
                <li class="contact-item">
                    <div class="contact-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                    </div>
                    <div>
                        <div style="font-size:13px; color:var(--text-muted);">Telefonas</div>
                        <strong>+37064477724</strong>
                    </div>
                </li>
            </ul>

            <div style="margin-top:auto; padding-top: 24px;">
                <p style="margin-bottom:12px; font-weight:600;">Sekite mus:</p>
                <div class="social-links">
                    <a href="https://instagram.com/jusu_nuoroda" target="_blank" class="social-btn" title="Instagram">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
                    </a>
                    <a href="https://facebook.com/jusu_nuoroda" target="_blank" class="social-btn" title="Facebook">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg>
                    </a>
                </div>
            </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
            <h2 style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:24px;">⏰</span> Darbo laikas
            </h2>
            <p>Užsakymus internetu priimame 24/7. Klientų aptarnavimo darbo laikas:</p>
            
            <div style="background: #f8fafc; border-radius:12px; padding:20px; margin-top:20px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                    <span>Pirmadienis – Penktadienis</span>
                    <strong>09:00 – 20:00</strong>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                    <span>Šeštadienis</span>
                    <strong>10:00 – 15:00</strong>
                </div>
                <div style="display:flex; justify-content:space-between; color:var(--text-muted);">
                    <span>Sekmadienis</span>
                    <span>Nedirbame</span>
                </div>
            </div>

            <div style="margin-top:auto; padding-top:20px;">
                <div class="pill" style="background:#f0fdf4; color:#166534; border-color:#bbf7d0; width: fit-content;">
                    🌍 Prekiaujame tik internetu
                </div>
            </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
            <h2 style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:24px;">🤝</span> Mūsų pažadas
            </h2>
            <p>Esame daugiau nei parduotuvė – esame bendruomenė, kuri rūpinasi.</p>
            
            <ul class="check-list">
                <li>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <span><strong>Asmeninis dėmesys.</strong> Visada patarsime ir padėsime išsirinkti tinkamiausią prekę.</span>
                </li>
                <li>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <span><strong>Greitas pristatymas.</strong> Suprantame, kaip svarbu gauti priemones laiku.</span>
                </li>
                <li>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <span><strong>Patikimumas.</strong> Siūlome tik patikrintus ir kokybiškus produktus.</span>
                </li>
            </ul>

            <div style="margin-top:auto; padding-top:20px;">
                 <a href="/about.php" style="color:var(--accent); font-weight:600; font-size:15px; text-decoration:underline;">Skaityti daugiau apie mus</a>
            </div>
        </div>
      </div>

      <div class="card" style="grid-column: 1 / -1;">
        <div class="card-body" style="flex-direction:row; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:20px;">
            <div style="max-width: 600px;">
                <h2 style="font-size:20px; margin-bottom:4px;">Turite papildomų klausimų?</h2>
                <p style="margin:0;">Jei neradote atsakymo ar reikia konsultacijos, drąsiai kreipkitės. Mūsų komanda pasiruošusi padėti.</p>
            </div>
            <a href="/faq.php" class="btn btn-secondary" style="margin-top:0; white-space:nowrap;">Peržiūrėti D.U.K</a>
        </div>
      </div>

    </div>
  </main>

  <?php renderFooter($pdo); ?>
</body>
</html>
