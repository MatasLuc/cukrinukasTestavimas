<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
tryAutoLogin($pdo);
$token = $_GET['token'] ?? '';
$error = '';
$success = '';

// Patikriname tokeną
$stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1');
$stmt->execute([$token]);
$resetRequest = $stmt->fetch();

if (!$resetRequest) {
    die('Netinkama arba pasibaigusi nuoroda. <a href="/forgot_password.php">Bandykite dar kartą</a>.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $pass1 = $_POST['pass1'] ?? '';
    $pass2 = $_POST['pass2'] ?? '';

    if (strlen($pass1) < 6) {
        $error = 'Slaptažodis per trumpas (mažiausiai 6 simboliai).';
    } elseif ($pass1 !== $pass2) {
        $error = 'Slaptažodžiai nesutampa.';
    } else {
        // Atnaujiname vartotoją
        $hash = password_hash($pass1, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?')->execute([$hash, $resetRequest['email']]);
        
        // Ištriname panaudotą tokeną
        $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$resetRequest['email']]);
        
        $success = 'Slaptažodis pakeistas! Dabar galite <a href="/login.php" style="color:#6f4ef2;">Prisijungti</a>';
    }
}
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Naujas slaptažodis | Cukrinukas</title>
  <?php echo headerStyles(); ?>
  <style>
    :root {
      --bg: #f7f7fb;
      --surface: #ffffff;
      --ink: #0f172a;
      --muted: #5b5f6a;
      --accent: #6f4ef2;
      --accent-2: #2f9aff;
      --border: #e4e6f0;
    }
    * { box-sizing: border-box; }
    body { background: var(--bg); color: var(--ink); }
    .wrapper { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 32px 24px; max-width: 1100px; margin: 0 auto; }
    .card { background: var(--surface); padding: 30px; border-radius: 20px; box-shadow: 0 16px 38px rgba(15,23,42,0.08); border: 1px solid var(--border); width: min(460px, 100%); margin: 0 auto; }
    .card h2 { margin: 0 0 8px; font-size: 26px; letter-spacing: -0.2px; }
    .card p { margin: 0 0 18px; color: var(--muted); }
    label { display: block; margin-bottom: 6px; font-weight: 700; color: #121826; }
    input { width: 100%; padding: 14px; border-radius: 14px; border: 1px solid var(--border); background: #fbfbff; font-size: 15px; transition: all .2s ease; }
    input:focus { outline: 2px solid rgba(111,78,242,0.3); box-shadow: 0 8px 20px rgba(111,78,242,0.12); }
    button { width: 100%; padding: 14px; border-radius: 14px; border: none; background: linear-gradient(135deg, var(--accent), var(--accent-2)); color: #fff; font-weight: 700; cursor: pointer; margin-top: 12px; box-shadow: 0 16px 40px rgba(47,154,255,0.25); }
    .link-row { display: flex; justify-content: space-between; margin-top: 14px; font-size: 14px; }
    .notice { padding: 14px; border-radius: 14px; margin-bottom: 12px; border: 1px solid; }
    .notice.error { background: #fff1f1; border: 1px solid #f3b7b7; color: #991b1b; box-shadow: 0 10px 24px rgba(244, 63, 94, 0.12); }
    .notice.success { background: #edf9f0; border: 1px solid #b8e2c4; color: #0f5132; box-shadow: 0 10px 24px rgba(16, 185, 129, 0.12); }
    .brand { display: inline-flex; align-items: center; gap: 10px; font-weight: 800; color: var(--ink); text-decoration: none; margin-bottom: 16px; font-size: 21px; letter-spacing: -0.2px; }
  </style>
</head>
<body>
<?php renderHeader($pdo, 'login'); ?>
<div class="wrapper">
    <div class="card">
        <a class="brand" href="/">Cukrinukas.lt</a>
        <h2>Naujas slaptažodis</h2>
        <p>Įveskite naują slaptažodį savo paskyrai.</p>

        <?php if ($success): ?><div class="notice success"><?php echo $success; ?></div>
        <?php else: ?>
            <?php if ($error): ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="post">
                <?php echo csrfField(); ?>
                <label for="pass1">Naujas slaptažodis</label>
                <input id="pass1" type="password" name="pass1" required autocomplete="new-password" minlength="6">
                
                <label for="pass2">Pakartokite slaptažodį</label>
                <input id="pass2" type="password" name="pass2" required autocomplete="new-password">
                
                <button type="submit">Išsaugoti</button>
            </form>
        <?php endif; ?>
        
        <div class="link-row">
            <a href="/login.php">← Grįžti į prisijungimą</a>
            <a href="/">↩ Pagrindinis</a>
        </div>
    </div>
</div>
<?php renderFooter($pdo); ?>
</body>
</html>

