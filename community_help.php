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
?>
<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="UTF-8">
    <title>Pagalba ir Taisyklės - Turgelis</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        .help-container { max-width: 800px; margin: 40px auto; padding: 0 20px; font-family: sans-serif; }
        .help-card { background: #fff; border-radius: 12px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .faq-item { margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 15px; }
        .faq-item h3 { color: var(--primary-color, #e63946); margin-bottom: 8px; }
        .faq-item p { margin: 0; line-height: 1.6; color: #555; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px; }
        .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-family: inherit; }
        .form-group textarea { resize: vertical; min-height: 120px; }
        .btn-submit { padding: 12px 20px; background: var(--primary-color, #e63946); color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .alert-success { background: #e9f5e9; color: #2a9d8f; border: 1px solid #c3e6c3; }
        .alert-error { background: #fee; color: #e63946; border: 1px solid #fcc; }
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .tab-btn { background: none; border: none; font-size: 16px; font-weight: bold; color: #666; cursor: pointer; padding: 10px 15px; border-radius: 6px; }
        .tab-btn.active { color: var(--primary-color, #e63946); background: #ffeeee; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body>
<?php renderHeader($pdo); ?>

<div class="help-container">
    <h1>Turgelio Pagalba ir Taisyklės</h1>

    <div class="tabs">
        <button class="tab-btn active" onclick="showTab('faq')">Taisyklės ir D.U.K.</button>
        <button class="tab-btn" onclick="showTab('policy')">Grąžinimai</button>
        <button class="tab-btn" onclick="showTab('ticket')">Pateikti skundą</button>
    </div>

    <div id="faq" class="help-card tab-content active">
        <h2>Dažniausiai užduodami klausimai</h2>
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

    <div id="policy" class="help-card tab-content">
        <h2>Ginčai ir grąžinimai (Refunds / Payouts)</h2>
        <ul>
            <li><strong>Pirkėjams:</strong> Jei prekė neatkeliavo arba atkeliavo sugadinta, per 3 dienas nuo numatyto pristatymo galite iškelti ginčą šio puslapio skiltyje „Pateikti skundą“. Patvirtinus problemą, pinigai bus grąžinti į jūsų kortelę.</li>
            <li><strong>Pardavėjams:</strong> Jei pirkėjas ignoruoja žinutes ir nepatvirtina gavimo, nors siuntinys pristatytas, pateikite skundą. Administracija patikrins siuntos numerį ir atliks priverstinį lėšų išmokėjimą (Force Payout).</li>
        </ul>
    </div>

    <div id="ticket" class="help-card tab-content">
        <h2>Pateikti skundą ar ginčą</h2>
        
        <?php if ($message_success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message_success) ?></div>
        <?php endif; ?>
        <?php if ($message_error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($message_error) ?></div>
        <?php endif; ?>

        <?php if (!$user): ?>
            <p><a href="/login.php">Prisijunkite</a>, kad pateiktumėte skundą.</p>
        <?php else: ?>
            <form method="POST" action="">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label>Susijęs užsakymas (nebūtina)</label>
                    <select name="order_id">
                        <option value="">-- Kitas klausimas --</option>
                        <?php foreach ($user_orders as $o): ?>
                            <option value="<?= $o['id'] ?>">Užsakymas #<?= $o['id'] ?> (<?= number_format($o['total_amount'], 2) ?> €)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Problemos tipas</label>
                    <select name="type" required>
                        <option value="item_not_received">Prekė negauta (Pirkėjams)</option>
                        <option value="item_defective">Prekė neatitinka aprašymo (Pirkėjams)</option>
                        <option value="buyer_unresponsive">Pirkėjas nepatvirtina gavimo (Pardavėjams)</option>
                        <option value="fraud">Kitas sukčiavimas</option>
                        <option value="general">Bendras klausimas</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Detalus situacijos aprašymas</label>
                    <textarea name="message" required placeholder="Aprašykite problemą, nurodykite siuntos numerį, jei turite..."></textarea>
                </div>

                <button type="submit" name="submit_ticket" class="btn-submit">Siųsti skundą</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
    function showTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        event.currentTarget.classList.add('active');
    }
</script>

<?php renderFooter($pdo); ?>
</body>
</html>
