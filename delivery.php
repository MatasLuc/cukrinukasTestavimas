<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
ensureNavigationTable($pdo);
tryAutoLogin($pdo);

// Pristatymo būdų informacija su ikonėlėmis
$deliveryMethods = [
    [
        'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'title' => 'LP Express / Omniva / DPD paštomatai',
        'desc' => 'Patogiausias būdas atsiimti prekes. Pristatymas per 1–3 darbo dienas į jūsų pasirinktą paštomatą visoje Lietuvoje. Kaina: 2.99 € (nemokamai nuo 50 €).'
    ],
    [
        'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
        'title' => 'DPD Kurjeris į namus',
        'desc' => 'Siuntą kurjeris pristatys tiesiai jūsų nurodytu adresu (į namus ar darbovietę). Prieš atvykdamas kurjeris informuos SMS žinute. Pristatymas per 1–2 darbo dienas. Kaina: 4.99 €.'
    ],
    [
        'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="16" rx="2"/><path d="M4 11h16"/><path d="M8 21v-2"/><path d="M16 21v-2"/></svg>',
        'title' => 'Autobusų siuntos',
        'desc' => 'Skubus pristatymas tą pačią arba kitą dieną į didžiųjų miestų autobusų stotis. Siuntą reikia atsiimti siuntų skyriuje. Kaina: 5.50 €.'
    ]
];
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pristatymas | Cukrinukas</title>
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
  <?php renderHeader($pdo, 'delivery'); ?>
  
  <main class="page">
    <section class="hero">
      <div class="hero-content">
        <div class="pill">🚚 Greitas ir patogus</div>
        <h1>Pristatymo informacija</h1>
        <p>Pasirūpiname, kad diabeto priežiūros priemonės jus pasiektų saugiai ir greitai. Žemiau rasite visus galimus pristatymo būdus.</p>
      </div>
      <div class="hero-icon" style="font-size: 100px; opacity: 0.8; line-height: 1;">
          📦
      </div>
    </section>

    <div class="methods-grid">
      <?php foreach ($deliveryMethods as $item): ?>
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
