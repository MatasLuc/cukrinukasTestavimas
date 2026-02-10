<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
$pdo = getPdo();
ensureNavigationTable($pdo);
tryAutoLogin($pdo);

// Struktūruoti D.U.K. duomenys
$faqCategories = [
    '🛒 Užsakymai ir apmokėjimas' => [
        [
            'q' => 'Kaip galiu apmokėti už prekes?',
            'a' => 'Siūlome patogiausius atsiskaitymo būdus: elektronine bankininkyste (per Paysera ar banko nuorodą), mokėjimo kortelėmis arba paprastu bankiniu pavedimu. Visi mokėjimai yra saugūs.'
        ],
        [
            'q' => 'Ar galiu atsiskaityti kurjeriui pristatymo metu (COD)?',
            'a' => 'Šiuo metu tokios galimybės neturime. Visus užsakymus prašome apmokėti užsakymo pateikimo metu internetu.'
        ],
        [
            'q' => 'Ar išrašote sąskaitas faktūras?',
            'a' => 'Taip, sąskaitą faktūrą automatiškai sugeneruojame ir atsiunčiame el. paštu iškart po apmokėjimo patvirtinimo. Taip pat ją galite rasti savo paskyroje.'
        ],
        [
            'q' => 'Ar galiu atšaukti arba pakoreguoti užsakymą?',
            'a' => 'Jei užsakymas dar neišsiųstas, susisiekite su mumis kuo skubiau el. paštu arba telefonu. Jei prekės jau perduotos kurjeriui, korekcijos nebegalimos, tačiau galėsite pasinaudoti grąžinimo teise.'
        ],
    ],
    '📦 Pristatymas' => [
        [
            'q' => 'Kiek laiko trunka pristatymas?',
            'a' => 'Dažniausiai prekes pristatome per 1–3 darbo dienas. Jei užsakymą pateikiate iki 12:00 val., stengiamės jį išsiųsti tą pačią dieną.'
        ],
        [
            'q' => 'Kokius pristatymo būdus siūlote?',
            'a' => 'Prekes pristatome į LP Express, Omniva ir DPD paštomatus bei per DPD kurjerį tiesiai į namus.'
        ],
        [
            'q' => 'Kiek kainuoja pristatymas?',
            'a' => 'Pristatymas į paštomatus kainuoja 2.99 €, kurjeriu – 4.99 €. Užsakymams virš 50 € taikome nemokamą pristatymą.'
        ],
        [
            'q' => 'Ar siunčiate prekes į užsienį?',
            'a' => 'Taip, siunčiame į daugumą Europos šalių. Pristatymo kaina ir terminas priklauso nuo konkrečios šalies ir yra paskaičiuojami krepšelyje.'
        ],
    ],
    '↩️ Grąžinimas ir garantija' => [
        [
            'q' => 'Ar galiu grąžinti netikusią prekę?',
            'a' => 'Taip, kokybiškas prekes galite grąžinti per 14 dienų, jei pakuotė nepažeista ir prekė nenaudota. Svarbu: higienos prekės (pvz., atidarytos lancetų pakuotės) nėra grąžinamos.'
        ],
        [
            'q' => 'Ką daryti, jei gavau brokuotą prekę?',
            'a' => 'Labai atsiprašome! Nedelsiant susisiekite su mumis el. paštu ir atsiųskite broko nuotrauką. Mes nemokamai pakeisime prekę arba grąžinsime pinigus.'
        ],
        [
            'q' => 'Ar prekėms taikoma garantija?',
            'a' => 'Taip, visiems elektroniniams prietaisams (pvz., gliukomačiams) taikoma 24 mėn. gamintojo garantija.'
        ],
    ],
    '👤 Paskyra ir kita' => [
        [
            'q' => 'Ar būtina registruotis norint pirkti?',
            'a' => 'Ne, pirkti galite ir kaip svečias. Tačiau registruoti vartotojai gali matyti užsakymų istoriją, kaupti lojalumo taškus ir greičiau apsipirkti kitą kartą.'
        ],
        [
            'q' => 'Pamiršau slaptažodį, ką daryti?',
            'a' => 'Spauskite „Prisijungti“ ir pasirinkite „Pamiršau slaptažodį“. Įveskite savo el. pašto adresą ir gausite nuorodą slaptažodžio atkūrimui.'
        ],
        [
            'q' => 'Kaip sužinoti apie naujas akcijas?',
            'a' => 'Geriausias būdas – užsiprenumeruoti mūsų naujienlaiškį puslapio apačioje arba sekti mus Facebook ir Instagram tinkluose.'
        ],
    ]
];

?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>D.U.K. | Cukrinukas</title>
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

    /* Pagrindinis puslapio konteineris - 1200px kaip contact.php */
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
    .hero h1 { margin:0 0 12px; font-size:32px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; line-height:1.6; font-size:16px; }

    .pill { 
        display:inline-flex; align-items:center; gap:8px; 
        padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #bfdbfe; 
        font-weight:600; font-size:13px; color:#1e40af; 
        margin-bottom: 16px;
    }

    /* FAQ Layout */
    .faq-wrapper { max-width: 900px; margin: 0 auto; width: 100%; }

    .faq-section { margin-bottom: 40px; }
    .category-title { 
        font-size: 20px; font-weight: 700; color: var(--text-main); 
        margin-bottom: 16px; padding-left: 8px;
        display: flex; align-items: center; gap: 10px;
    }

    .faq-item {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 12px;
        margin-bottom: 12px;
        overflow: hidden;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .faq-item:hover { border-color: #cbd5e1; }
    .faq-item[open] { border-color: var(--accent); box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08); }

    summary {
        padding: 20px;
        font-weight: 600;
        cursor: pointer;
        list-style: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        color: var(--text-main);
        transition: color 0.2s;
    }
    summary::-webkit-details-marker { display: none; }
    
    summary:hover { color: var(--accent); }

    .faq-content {
        padding: 0 20px 24px 20px;
        color: var(--text-muted);
        line-height: 1.6;
        border-top: 1px solid transparent;
    }
    .faq-item[open] .faq-content { border-top-color: #f1f5f9; padding-top: 20px; }

    .icon-plus { 
        width: 20px; height: 20px; 
        flex-shrink: 0; 
        transition: transform 0.2s; 
        color: var(--text-muted);
    }
    .faq-item[open] .icon-plus { transform: rotate(45deg); color: var(--accent); }

    /* Contact Box */
    .contact-box {
        background: #fff; border: 1px solid var(--border);
        border-radius: 20px; padding: 32px;
        text-align: center;
        max-width: 900px; margin: 0 auto; width: 100%;
    }

    .btn { 
        padding:12px 24px; border-radius:10px; 
        font-weight:600; font-size:15px;
        cursor:pointer; text-decoration:none; 
        display:inline-flex; align-items:center; justify-content:center;
        transition: all .2s;
        background: #0f172a; color:#fff; border:none;
        margin-top: 16px;
    }
    .btn:hover { background: #1e293b; transform: translateY(-1px); }

    @media (max-width: 768px) {
        .hero { padding: 24px; flex-direction: column; align-items: stretch; text-align: center; }
        .hero-content { max-width: 100%; }
        .hero-icon { display: none; } /* Paslepiam ikoną mobile, jei trukdo */
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'faq'); ?>
  
  <main class="page">
    
    <section class="hero">
        <div class="hero-content">
            <div class="pill">🤔 Pagalbos centras</div>
            <h1>Dažniausiai užduodami klausimai</h1>
            <p>Čia rasite atsakymus į populiariausius klausimus apie užsakymus, pristatymą ir prekes. Taupykite savo laiką!</p>
        </div>
        <div class="hero-icon" style="font-size: 100px; opacity: 0.8; line-height: 1;">
            ❔
        </div>
    </section>

    <div class="faq-wrapper">
        <?php foreach ($faqCategories as $categoryName => $questions): ?>
            <div class="faq-section">
                <h2 class="category-title"><?php echo htmlspecialchars($categoryName); ?></h2>
                
                <?php foreach ($questions as $item): ?>
                    <details class="faq-item">
                        <summary>
                            <?php echo htmlspecialchars($item['q']); ?>
                            <svg class="icon-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                        </summary>
                        <div class="faq-content">
                            <?php echo htmlspecialchars($item['a']); ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="contact-box">
        <h2 style="margin-top:0;">Neradote atsakymo?</h2>
        <p style="color:var(--text-muted);">Mūsų klientų aptarnavimo komanda mielai jums padės asmeniškai.</p>
        <a href="/contact.php" class="btn">Susisiekti su mumis</a>
    </div>

  </main>

  <?php renderFooter($pdo); ?>
</body>
</html>
