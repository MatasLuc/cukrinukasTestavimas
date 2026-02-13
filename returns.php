<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
ensureNavigationTable($pdo);
tryAutoLogin($pdo);

// Grąžinimo taisyklės
$returnRules = [
    [
        'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>',
        'title' => '14 dienų grąžinimo garantija',
        'desc' => 'Netikusias kokybiškas prekes galite grąžinti per 14 kalendorinių dienų nuo pristatymo dienos. Prekė turi būti nenaudota, nepraradusi prekinės išvaizdos ir originalioje pakuotėje.',
        'highlight' => false
    ],
    [
        'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
        'title' => 'Higienos prekių išimtis',
        'desc' => 'Dėmesio: vadovaujantis teisės aktais, kokybiškos medicininės paskirties prekės, kurios buvo išpakuotos, nėra grąžinamos dėl higienos ir sveikatos apsaugos priežasčių.',
        'highlight' => true
    ],
    [
        'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>',
        'title' => 'Kaip inicijuoti grąžinimą?',
        'desc' => 'Norėdami grąžinti prekę, parašykite mums el. paštu <a href="mailto:labas@cukrinukas.lt" style="text-decoration:underline; color:inherit;">labas@cukrinukas.lt</a> nurodydami užsakymo numerį ir grąžinimo priežastį. Mes atsiųsime jums instrukciją.'
    ],
    [
        'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path></svg>',
        'title' => 'Brokuotos prekės',
        'desc' => 'Jei gavote nekokybišką prekę ar ji neveikia, nedelsiant susisiekite. Pakeisime prekę nauja arba grąžinsime pinigus, taip pat padengsime siuntimo išlaidas.'
    ],
    [
        'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>',
        'title' => 'Pinigų grąžinimo terminas',
        'desc' => 'Pinigai už grąžintas prekes pervedami į jūsų nurodytą banko sąskaitą per 5–10 darbo dienų nuo prekės grįžimo į mūsų sandėlį ir jos patikrinimo.'
    ]
];
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Grąžinimas ir garantija | Cukrinukas</title>
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

    /* Pagrindinis plotis 1200px */
    .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction:column; gap:40px; }

    /* Hero Section - Identiska contact.php */
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
    .hero h1 { margin:0 0 16px; font-size:32px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; line-height:1.6; font-size:16px; }

    .pill { 
        display:inline-flex; align-items:center; gap:8px; 
        padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #bfdbfe; 
        font-weight:600; font-size:13px; color:#1e40af; 
        margin-bottom: 16px;
    }

    /* Grid Layout */
    .rules-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 24px;
    }

    /* Card Styles */
    .card { 
        background:var(--card); 
        border:1px solid var(--border); 
        border-radius:20px; 
        padding: 32px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        transition: transform .2s, box-shadow .2s;
        display: flex; flex-direction: column; gap: 16px;
    }
    .card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border-color: #cbd5e1; }

    .card h3 { margin:0; font-size: 20px; color: var(--text-main); }
    .card p { margin:0; color: var(--text-muted); line-height: 1.6; font-size: 15px; }

    /* Icons */
    .icon-box {
        width: 48px; height: 48px;
        background: #eff6ff; color: var(--accent);
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
    }

    /* Highlight Card (Higiena) */
    .card.highlight {
        background: #ffffff;
        border-color: #fecaca;
    }
    .card.highlight .icon-box {
        background: #fef2f2;
        color: #dc2626;
    }
    .card.highlight h3 { color: #991b1b; }

    /* CTA Section */
    .cta-card {
        background: #1e3a8a; color: white;
        border-radius: 20px; padding: 32px;
        display: flex; align-items: center; justify-content: space-between;
        gap: 20px; flex-wrap: wrap;
    }
    .cta-btn {
        background: #fff; color: #1e3a8a;
        padding: 12px 24px; border-radius: 10px;
        font-weight: 600; font-size: 15px;
        transition: all 0.2s;
    }
    .cta-btn:hover { background: #eff6ff; transform: translateY(-1px); }

    @media (max-width: 768px) {
        .hero { padding: 24px; flex-direction: column; align-items: stretch; text-align: center; }
        .hero-content { max-width: 100%; }
        .hero-icon { display: none; }
        .rules-grid { grid-template-columns: 1fr; }
        .cta-card { text-align: center; justify-content: center; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'returns'); ?>
  
  <main class="page">
    
    <section class="hero">
      <div class="hero-content">
        <div class="pill">🛡️ Garantija</div>
        <h1>Grąžinimas ir taisyklės</h1>
        <p>Siekiame, kad apsipirkimas būtų be rūpesčių. Čia rasite visą informaciją apie prekių grąžinimą, keitimą ir pinigų susigrąžinimą.</p>
      </div>
      <div class="hero-icon" style="font-size: 100px; opacity: 0.8; line-height: 1;">
          📦
      </div>
    </section>

    <div class="rules-grid">
        <?php foreach ($returnRules as $item): ?>
            <article class="card <?php echo !empty($item['highlight']) ? 'highlight' : ''; ?>">
                <div class="icon-box">
                    <?php echo $item['icon']; ?>
                </div>
                <div>
                    <h3><?php echo htmlspecialchars($item['title']); ?></h3>
                    <p style="margin-top:8px;"><?php echo $item['desc']; ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="cta-card">
        <div>
            <h2 style="margin:0 0 8px; font-size:20px; color:#fff;">Kilo neaiškumų?</h2>
            <p style="margin:0; opacity:0.8;">Mūsų komanda pasiruošusi atsakyti į visus jūsų klausimus.</p>
        </div>
        <a href="/contact.php" class="cta-btn">Susisiekti su mumis</a>
    </div>

  </main>

  <?php renderFooter($pdo); ?>
</body>
</html>
