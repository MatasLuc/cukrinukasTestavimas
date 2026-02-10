<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
ensureNavigationTable($pdo);
tryAutoLogin($pdo);

// Apmokėjimo būdų informacija su ikonėlėmis
$paymentMethods = [
    [
        'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4 8 4v14"/><path d="M19 10a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2"/><path d="M12 21a5 5 0 0 0 5-5H7a5 5 0 0 0 5 5z"/></svg>',
        'title' => 'Elektroninė bankininkystė (Paysera)',
        'desc' => 'Saugus ir greitas atsiskaitymas per jūsų banką (Swedbank, SEB, Luminor, Šiaulių bankas ir kt.). Mokėjimas įskaitomas akimirksniu, todėl užsakymą pradedame vykdyti iš karto.'
    ],
    [
        'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
        'title' => 'Banko kortelės (VISA / MasterCard)',
        'desc' => 'Galite atsiskaityti bet kuria galiojančia debetine ar kreditine kortele. Duomenų saugumą užtikrina sertifikuoti mokėjimų partneriai.'
    ],
    [
        'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
        'title' => 'Bankinis pavedimas',
        'desc' => 'Pasirinkus šį būdą, gausite sąskaitą faktūrą su rekvizitais el. paštu. Užsakymas pradedamas vykdyti tik gavus lėšas į mūsų banko sąskaitą (gali užtrukti 1 d.d.).'
    ]
];
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Apmokėjimas | Cukrinukas</title>
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
    body { margin:0; background: var(--bg); color: var(--text-main); font-family:'Inter', system-ui, -apple-system, sans-serif; }
    
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
    .methods-grid {
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

    @media (max-width: 768px) {
        .hero { padding: 24px; flex-direction: column; align-items: stretch; text-align: center; }
        .hero-content { max-width: 100%; }
        .hero-icon { display: none; }
        .methods-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'payment'); ?>
  
  <main class="page">
    <section class="hero">
      <div class="hero-content">
        <div class="pill">💳 Saugūs atsiskaitymai</div>
        <h1>Apmokėjimo būdai</h1>
        <p>Mes užtikriname maksimalų duomenų saugumą. Pasirinkite jums patogiausią atsiskaitymo būdą už prekes.</p>
      </div>
      <div class="hero-icon" style="font-size: 100px; opacity: 0.8; line-height: 1;">
          💰
      </div>
    </section>

    <div class="methods-grid">
      <?php foreach ($paymentMethods as $item): ?>
        <article class="card">
          <div class="icon-box">
             <?php echo $item['icon']; ?>
          </div>
          <div>
            <h3><?php echo htmlspecialchars($item['title']); ?></h3>
            <p style="margin-top:8px;"><?php echo htmlspecialchars($item['desc']); ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </main>

  <?php renderFooter($pdo); ?>
</body>
</html>
