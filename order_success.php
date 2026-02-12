<?php
// order_success.php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/db.php';

session_start();
$pdo = getPdo();

// Išvalome krepšelį, jei vartotojas grįžo po sėkmingo apmokėjimo
if (isset($_GET['session_id'])) {
    unset($_SESSION['cart']);
}

?>
<!doctype html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>Ačiū už užsakymą!</title>
    <?php echo headerStyles(); ?>
    <style>
        .success-box {
            max-width: 600px;
            margin: 50px auto;
            text-align: center;
            padding: 40px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .icon { font-size: 60px; color: #10b981; margin-bottom: 20px; }
        .btn {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 6px;
        }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <?php renderHeader($pdo); ?>

    <div class="success-box">
        <div class="icon">✅</div>
        <h1>Ačiū už užsakymą!</h1>
        <p>Mokėjimas sėkmingai gautas. Jūsų užsakymas pradėtas vykdyti.</p>
        <p>Patvirtinimo laišką išsiuntėme jūsų el. paštu.</p>
        
        <br>
        <a href="/orders.php" class="btn">Mano užsakymai</a>
        <a href="/products.php" class="btn" style="background: #64748b;">Grįžti į parduotuvę</a>
    </div>

    <?php renderFooter($pdo); ?>
</body>
</html>
