<?php
// community_help.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
$user = currentUser();

$message_success = '';
$message_error = '';

// Skundo priėmimo logika
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {
    if (!$user) {
        $message_error = 'Turite būti prisijungęs, kad pateiktumėte skundą.';
    } else {
        $order_id = !empty($_POST['order_id']) ? (int)$_POST['order_id'] : null;
        $type = $_POST['type'] ?? 'general';
        $message = trim($_POST['message'] ?? '');

        if (empty($message)) {
            $message_error = 'Prašome įvesti skundo tekstą.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO community_tickets (user_id, order_id, type, message, status, created_at)
                VALUES (:user_id, :order_id, :type, :message, 'open', NOW())
            ");
            $stmt->execute([
                'user_id' => $user['id'],
                'order_id' => $order_id,
                'type' => $type,
                'message' => $message
            ]);
            $message_success = 'Jūsų skundas sėkmingai pateiktas. Administratoriai su jumis susisieks.';
        }
    }
}

// Ištraukiame vartotojo užsakymus dropdown pasirinkimui
$user_orders = [];
if ($user) {
    $stmt = $pdo->prepare("
        SELECT id, created_at, total_amount
        FROM community_orders 
        WHERE buyer_id = :uid1 OR seller_id = :uid2
        ORDER BY created_at DESC
    ");
    $stmt->execute([
        'uid1' => $user['id'],
        'uid2' => $user['id']
    ]);
    $user_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (function_exists('headerStyles')) {
    echo headerStyles();
}
?>
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
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; transition: color .2s; }
    
    .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction:column; gap:32px; }

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
    .card { 
        background:var(--card); 
        border:1px solid var(--border); 
        border-radius:20px; 
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .card-body { padding: 32px; }

    /* Tabs */
    .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
    .tab-btn {
        background: #fff; border: 1px solid var(--border);
        color: var(--text-muted); font-weight: 600; font-size: 14px;
        padding: 10px 20px; border-radius: 999px; cursor: pointer;
        transition: all 0.2s;
    }
    .tab-btn:hover { border-color: var(--accent); color: var(--accent); }
    .tab-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }
    .tab-content { display: none; }
    .tab-content.active { display: block; animation: fadeIn 0.3s ease-in-out; }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

    /* Text & Lists */
    .section-title { margin: 0 0 24px; font-size: 22px; color: var(--text-main); }
    .faq-item { border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 20px; }
    .faq-item:last-child { border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
    .faq-item h3 { margin: 0 0 8px; color: var(--text-main); font-size: 18px; }
    .faq-item p { margin: 0; color: var(--text-muted); line-height: 1.6; }
    
    ul.policy-list { margin: 0; padding-left: 20px; color: var(--text-muted); line-height: 1.6; font-size: 15px;}
    ul.policy-list li { margin-bottom: 12px; }
    ul.policy-list strong { color: var(--text-main); }

    /* Forms */
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; color: var(--text-main); }
    .form-control {
        width: 100%; padding: 12px 16px;
        border: 1px solid var(--border); border-radius: 10px;
        font-family: inherit; font-size: 14px;
        transition: border-color 0.2s;
        background: #fff; color: var(--text-main);
    }
    .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
    textarea.form-control { resize: vertical; min-height: 120px; }

    /* Buttons */
    .btn { 
        padding:12px 24px; border-radius:10px; 
        font-weight:600; font-size:14px;
        cursor:pointer; text-decoration:none; 
        display:inline-flex; align-items:center; justify-content:center;
        transition: all .2s;
        border:none; background: #0f172a; color:#fff;
    }
    .btn:hover { background: #1e293b; color:#fff; transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }

    /* Notices */
    .notice { padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; gap:10px; align-items:center; }
    .notice.success { background: #ecfdf5; border: 1px solid #d1fae5; color: #065f46; }
    .notice.error { background: #fef2f2; border: 1px solid #fee2e2; color: #991b1b; }

    @media (max-width: 768px) {
        .hero { padding: 24px; flex-direction: column; align-items: stretch; }
    }
</style>

<?php renderHeader($pdo, 'community_help'); ?>

<div class="page">
    <section class="hero">
        <div class="hero-content">
            <div class="pill">🆘 Pagalba</div>
            <h1>Pagalba ir Taisyklės</h1>
            <p>Raskite atsakymus į dažniausiai užduodamus klausimus, sužinokite apie grąžinimus arba susisiekite su administracija.</p>
        </div>
    </section>

    <div class="tabs">
        <button class="tab-btn active" onclick="showTab('faq', event)">Taisyklės ir D.U.K.</button>
        <button class="tab-btn" onclick="showTab('policy', event)">Grąžinimai</button>
        <button class="tab-btn" onclick="showTab('ticket', event)">Pateikti skundą</button>
    </div>

    <div id="faq" class="card tab-content active">
        <div class="card-body">
            <h2 class="section-title">Dažniausiai užduodami klausimai</h2>
            <div class="faq-item">
                <h3>Kaip veikia saugus pirkimas?</h3>
                <p>Jūsų mokėjimas yra saugiai rezervuojamas. Pardavėjas pinigus gauna tik tada, kai patvirtinate, jog gavote prekę ir ji atitinka aprašymą.</p>
            </div>
            <div class="faq-item">
                <h3>Kada pardavėjas gauna pinigus?</h3>
                <p>Išsiuntus prekę ir pirkėjui patvirtinus jos gavimą, lėšos automatiškai pervedamos į jūsų susietą „Stripe“ sąskaitą (atskaičius platformos komisinį mokestį).</p>
            </div>
            <div class="faq-item">
                <h3>Draudžiamos prekės</h3>
                <p>Platformoje draudžiama parduoti nelegalius, suklastotus ar pavojingus daiktus. Už taisyklių pažeidimą paskyra gali būti blokuojama.</p>
            </div>
        </div>
    </div>

    <div id="policy" class="card tab-content">
        <div class="card-body">
            <h2 class="section-title">Ginčai ir grąžinimai (Refunds / Payouts)</h2>
            <ul class="policy-list">
                <li><strong>Pirkėjams:</strong> Jei prekė neatkeliavo arba atkeliavo sugadinta, per 3 dienas nuo numatyto pristatymo galite iškelti ginčą šio puslapio skiltyje „Pateikti skundą“. Patvirtinus problemą, pinigai bus grąžinti į jūsų kortelę.</li>
                <li><strong>Pardavėjams:</strong> Jei pirkėjas ignoruoja žinutes ir nepatvirtina gavimo, nors siuntinys pristatytas, pateikite skundą. Administracija patikrins siuntos numerį ir atliks priverstinį lėšų išmokėjimą (Force Payout).</li>
            </ul>
        </div>
    </div>

    <div id="ticket" class="card tab-content">
        <div class="card-body">
            <h2 class="section-title">Pateikti skundą ar ginčą</h2>
            
            <?php if ($message_success): ?>
                <div class="notice success">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <?= htmlspecialchars($message_success) ?>
                </div>
            <?php endif; ?>
            <?php if ($message_error): ?>
                <div class="notice error">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <?= htmlspecialchars($message_error) ?>
                </div>
            <?php endif; ?>

            <?php if (!$user): ?>
                <p style="color: var(--text-muted); margin-bottom: 0;">
                    <a href="/login.php" style="color: var(--accent); font-weight: 600; text-decoration: underline;">Prisijunkite</a>, kad pateiktumėte skundą.
                </p>
            <?php else: ?>
                <form method="POST" action="">
                    <?php if (function_exists('csrfField')) echo csrfField(); ?>
                    
                    <div class="form-group">
                        <label>Susijęs užsakymas (nebūtina)</label>
                        <select name="order_id" class="form-control">
                            <option value="">-- Kitas klausimas --</option>
                            <?php foreach ($user_orders as $o): ?>
                                <option value="<?= $o['id'] ?>">Užsakymas #<?= $o['id'] ?> (<?= number_format($o['total_amount'], 2) ?> €)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Problemos tipas</label>
                        <select name="type" class="form-control" required>
                            <option value="item_not_received">Prekė negauta (Pirkėjams)</option>
                            <option value="item_defective">Prekė neatitinka aprašymo (Pirkėjams)</option>
                            <option value="buyer_unresponsive">Pirkėjas nepatvirtina gavimo (Pardavėjams)</option>
                            <option value="fraud">Kitas sukčiavimas</option>
                            <option value="general">Bendras klausimas</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Detalus situacijos aprašymas</label>
                        <textarea name="message" class="form-control" required placeholder="Aprašykite problemą, nurodykite siuntos numerį, jei turite..."></textarea>
                    </div>

                    <button type="submit" name="submit_ticket" class="btn">Siųsti skundą</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function showTab(tabId, event) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        
        document.getElementById(tabId).classList.add('active');
        if (event && event.currentTarget) {
            event.currentTarget.classList.add('active');
        }
    }
</script>

<?php renderFooter($pdo); ?>
