<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

// Atvaizduojame flash žinutes (pvz., po Paysera apmokėjimo)
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;

// Išvalome žinutes po parodymo
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$pdo = getPdo();
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Užsakymas priimtas | Cukrinukas.lt</title>
  <?php echo headerStyles(); ?>
  <style>
    :root {
      --bg: #f7f7fb;
      --card: #ffffff;
      --text-main: #0f172a;
      --text-muted: #475467;
      --accent: #2563eb;
      --success-bg: #ecfdf5;
      --success-text: #065f46;
      --border: #e4e7ec;
    }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    .page { max-width: 600px; margin: 60px auto; padding: 0 20px; text-align: center; }
    
    .success-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 24px;
        padding: 48px 32px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .icon-wrapper {
        width: 80px;
        height: 80px;
        background: var(--success-bg);
        color: var(--success-text);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 24px;
    }
    .icon-wrapper svg { width: 40px; height: 40px; }

    h1 { margin: 0 0 12px; font-size: 28px; color: var(--text-main); }
    p { margin: 0 0 32px; color: var(--text-muted); line-height: 1.6; }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 14px 28px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        text-decoration: none;
        background: #0f172a;
        color: #fff;
        transition: all .2s;
    }
    .btn:hover { background: #1e293b; transform: translateY(-1px); }

    .alert {
        padding: 16px;
        border-radius: 12px;
        margin-bottom: 24px;
        text-align: left;
        font-size: 14px;
    }
    .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; }
    .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'success'); ?>
  
  <div class="page">
    <div class="success-card">
        
        <?php if ($flashSuccess): ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($flashSuccess); ?></div>
        <?php endif; ?>
        
        <?php if ($flashError): ?>
            <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($flashError); ?></div>
        <?php endif; ?>

        <div class="icon-wrapper">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </div>

        <h1>Ačiū už užsakymą!</h1>
        <p>
            Jūsų užsakymas sėkmingai priimtas. <br>
            Apie vykdymo eigą informuosime el. paštu.
        </p>

        <a href="/products.php" class="btn">Grįžti į parduotuvę</a>
    </div>
  </div>

  <?php renderFooter($pdo); ?>
</body>
</html>
