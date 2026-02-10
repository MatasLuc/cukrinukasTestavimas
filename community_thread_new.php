<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
ensureUsersTable($pdo);
ensureCommunityTables($pdo);
ensureNavigationTable($pdo);
tryAutoLogin($pdo);

$user = currentUser();
$blocked = $user['id'] ? isCommunityBlocked($pdo, (int)$user['id']) : null;
$errors = [];
$categories = $pdo->query('SELECT * FROM community_thread_categories ORDER BY name ASC')->fetchAll();

if (!$user['id']) {
    $_SESSION['flash_error'] = 'Prisijunkite, kad sukurtumėte temą.';
    header('Location: /login.php');
    exit;
}

if ($blocked) {
    $_SESSION['flash_error'] = 'Temos kūrimas apribotas iki ' . ($blocked['banned_until'] ?? 'neribotai');
    header('Location: /community_discussions.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    if (!$title || !$body) {
        $errors[] = 'Užpildykite pavadinimą ir žinutę.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO community_threads (user_id, category_id, title, body) VALUES (?, ?, ?, ?)');
        $stmt->execute([$user['id'], $categoryId ?: null, $title, $body]);
        $_SESSION['flash_success'] = 'Diskusija sukurta';
        header('Location: /community_discussions.php');
        exit;
    }
}

?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nauja tema | Cukrinukas</title>
  <?php echo headerStyles(); ?>
<style>
/* Bendras stilius */
:root { --bg: #f7f7fb; --card: #ffffff; --border: #e4e7ec; --text: #1f2937; --muted: #52606d; --accent: #2563eb; }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--text); }
a { color:inherit; text-decoration:none; }

.page { max-width: 1200px; margin: 0 auto; padding: 32px 20px 72px; display: grid; gap: 28px; }

/* Hero */
.hero {
  padding: 26px; border-radius: 28px; background: linear-gradient(135deg, #eff6ff, #dbeafe);
  border: 1px solid #e5e7eb; box-shadow: 0 18px 48px rgba(0,0,0,0.08);
}
.hero h1 { margin: 0 0 8px; font-size: clamp(24px, 4vw, 32px); color: #0f172a; }
.hero p { margin: 0; color: var(--muted); }

/* Formos stilius */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 32px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); max-width: 800px; margin: 0 auto; width: 100%; }

label { display: block; margin-bottom: 16px; }
label span { display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px; color: var(--text); }

input, textarea, select {
    width: 100%;
    padding: 12px 16px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: #fff;
    font-size: 15px;
    color: var(--text);
    transition: border-color .2s, box-shadow .2s;
    font-family: inherit;
}
input:focus, textarea:focus, select:focus {
    border-color: var(--accent);
    outline: none;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.btn { display: inline-flex; align-items: center; justify-content: center; padding: 12px 24px; border-radius: 12px; background: #0b0b0b; color: #fff; border: 1px solid #0b0b0b; font-weight: 600; cursor: pointer; transition: opacity 0.2s; font-size: 15px; }
.btn:hover { opacity: 0.9; }
.btn-secondary { background: #fff; color: #0b0b0b; border-color: var(--border); }

.alert-error { background:#fef2f2; border:1px solid #fecaca; color: #991b1b; padding:12px; border-radius:12px; margin-bottom:20px; }
</style>
</head>
<body>
  <?php renderHeader($pdo, 'community'); ?>
  
  <div class="page">
    <section class="hero">
        <div style="max-width: 800px; margin: 0 auto;">
            <h1>Nauja tema</h1>
            <p>Pasidalykite klausimu ar patarimu su bendruomene.</p>
        </div>
    </section>

    <div class="card">
        <?php foreach ($errors as $err): ?>
          <div class="alert-error">&times; <?php echo htmlspecialchars($err); ?></div>
        <?php endforeach; ?>
        
        <form method="post">
          <?php echo csrfField(); ?>
          
          <label>
            <span>Pavadinimas</span>
            <input name="title" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" required placeholder="Apie ką norite pasikalbėti?">
          </label>
          
          <label>
            <span>Žinutė</span>
            <textarea name="body" style="min-height:200px;" required placeholder="Jūsų mintys..."><?php echo htmlspecialchars($_POST['body'] ?? ''); ?></textarea>
          </label>
          
          <label>
            <span>Kategorija (pasirinktinai)</span>
            <select name="category_id">
              <option value="">Be kategorijos</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?php echo (int)$cat['id']; ?>" <?php echo (isset($_POST['category_id']) && (int)$_POST['category_id'] === (int)$cat['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          
          <div style="display:flex; gap:12px; margin-top: 24px;">
            <button class="btn" type="submit">Kurti temą</button>
            <a class="btn btn-secondary" href="/community_discussions.php">Atšaukti</a>
          </div>
        </form>
    </div>
  </div>
  
  <?php renderFooter($pdo); ?>
</body>
</html>
