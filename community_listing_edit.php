<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
ensureUsersTable($pdo);
ensureCommunityTables($pdo);
ensureNavigationTable($pdo);
tryAutoLogin($pdo);

$user = currentUser();
if (!$user['id']) {
    $_SESSION['flash_error'] = 'Prisijunkite, kad redaguotumėte skelbimą.';
    header('Location: /login.php');
    exit;
}

$listingId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM community_listings WHERE id = ?');
$stmt->execute([$listingId]);
$listing = $stmt->fetch();
if (!$listing) {
    $_SESSION['flash_error'] = 'Skelbimas nerastas.';
    header('Location: /community_market.php');
    exit;
}

$categories = $pdo->query('SELECT * FROM community_listing_categories ORDER BY name ASC')->fetchAll();

if ((int)$listing['user_id'] !== (int)$user['id'] && !$user['is_admin']) {
    $_SESSION['flash_error'] = 'Neturite teisės redaguoti šio skelbimo.';
    header('Location: /community_market.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $listingType = $_POST['listing_type'] ?? 'sell';
    $sellerEmail = trim($_POST['seller_email'] ?? '');
    $sellerPhone = trim($_POST['seller_phone'] ?? '');

    if (!in_array($listingType, ['sell', 'buy'])) $listingType = 'sell';

    if (!$title || !$description) {
        $errors[] = 'Užpildykite pavadinimą ir aprašymą.';
    }
    if (!$sellerEmail && !$sellerPhone) {
        $errors[] = 'Nurodykite bent vieną kontaktą (el. paštą arba tel. nr.).';
    }
    $img = uploadImageWithValidation($_FILES['image'] ?? [], 'community_', $errors, null, false);

    if (!$errors) {
        $pdo->prepare('UPDATE community_listings SET title = ?, description = ?, price = ?, status = ?, seller_email = ?, seller_phone = ?, category_id = ?, listing_type = ? WHERE id = ?')
            ->execute([$title, $description, $price, $status, $sellerEmail ?: null, $sellerPhone ?: null, $categoryId ?: null, $listingType, $listingId]);
        if ($img) {
            $pdo->prepare('UPDATE community_listings SET image_url = ? WHERE id = ?')->execute([$img, $listingId]);
        }
        $_SESSION['flash_success'] = 'Skelbimas atnaujintas';
        header('Location: /community_market.php');
        exit;
    }
}

?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Skelbimo redagavimas | Cukrinukas</title>
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

/* Grupavimas */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

@media (max-width: 700px) { 
    .form-row, .form-row-3 { grid-template-columns: 1fr; } 
}

/* Radio stilius tipui */
.type-selector { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
.radio-option { position: relative; }
.radio-option input { position: absolute; opacity: 0; width: 0; height: 0; }
.radio-tile {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 16px; border-radius: 12px; border: 2px solid var(--border);
    background: #fff; cursor: pointer; transition: all 0.2s; height: 100%;
}
.radio-tile .icon { font-size: 24px; margin-bottom: 8px; }
.radio-tile .label { font-weight: 600; font-size: 15px; color: var(--text); }

.radio-option input:checked + .radio-tile {
    border-color: var(--accent); background: #eff6ff;
}
.radio-option input:checked + .radio-tile .label { color: var(--accent); }
</style>
</head>
<body>
  <?php renderHeader($pdo, 'community'); ?>

  <div class="page">
    <section class="hero">
        <div style="max-width: 800px; margin: 0 auto;">
            <h1>Redaguoti skelbimą</h1>
            <p>Atnaujinkite skelbimo informaciją.</p>
        </div>
    </section>

    <div class="card">
        <?php foreach ($errors as $err): ?>
          <div class="alert-error">&times; <?php echo htmlspecialchars($err); ?></div>
        <?php endforeach; ?>
        
        <form method="post" enctype="multipart/form-data">
          <?php echo csrfField(); ?>
          
          <label><span>Skelbimo tipas</span></label>
          <div class="type-selector">
              <?php $currentType = $_POST['listing_type'] ?? $listing['listing_type'] ?? 'sell'; ?>
              <label class="radio-option">
                  <input type="radio" name="listing_type" value="sell" <?php echo $currentType === 'sell' ? 'checked' : ''; ?>>
                  <div class="radio-tile">
                      <div class="icon">📦</div>
                      <div class="label">Parduodu</div>
                  </div>
              </label>
              <label class="radio-option">
                  <input type="radio" name="listing_type" value="buy" <?php echo $currentType === 'buy' ? 'checked' : ''; ?>>
                  <div class="radio-tile">
                      <div class="icon">🔍</div>
                      <div class="label">Ieškau / Perku</div>
                  </div>
              </label>
          </div>
          
          <label>
            <span>Pavadinimas</span>
            <input name="title" value="<?php echo htmlspecialchars($_POST['title'] ?? $listing['title']); ?>" required>
          </label>
          
          <label>
            <span>Aprašymas</span>
            <textarea name="description" style="min-height:160px;" required><?php echo htmlspecialchars($_POST['description'] ?? $listing['description']); ?></textarea>
          </label>
          
          <div class="form-row-3">
              <label>
                <span>Kaina (€)</span>
                <input name="price" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars($_POST['price'] ?? $listing['price']); ?>">
              </label>
              
              <label>
                <span>Kategorija</span>
                <select name="category_id">
                  <option value="">Pasirinkti</option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo (int)$cat['id']; ?>" <?php echo (((int)($_POST['category_id'] ?? $listing['category_id'])) === (int)$cat['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              
              <label>
                <span>Statusas</span>
                <select name="status">
                    <option value="active" <?php echo (($listing['status'] ?? '') === 'active' || ($_POST['status'] ?? '') === 'active') ? 'selected' : ''; ?>>Aktyvus</option>
                    <option value="sold" <?php echo (($listing['status'] ?? '') === 'sold' || ($_POST['status'] ?? '') === 'sold') ? 'selected' : ''; ?>>Užbaigtas/Parduotas</option>
                </select>
              </label>
          </div>
          
          <div class="form-row">
              <label>
                <span>El. paštas</span>
                <input name="seller_email" value="<?php echo htmlspecialchars($_POST['seller_email'] ?? $listing['seller_email']); ?>" placeholder="info@...">
              </label>
              <label>
                <span>Tel. nr.</span>
                <input name="seller_phone" value="<?php echo htmlspecialchars($_POST['seller_phone'] ?? $listing['seller_phone']); ?>" placeholder="+370...">
              </label>
          </div>
          
          <label>
             <span>Nuotrauka</span>
             <?php if (!empty($listing['image_url'])): ?>
                <div style="margin-bottom:10px; display:flex; align-items:center; gap:12px; background:#f9fafb; padding:10px; border-radius:12px; border:1px solid var(--border);">
                  <img src="<?php echo htmlspecialchars($listing['image_url']); ?>" alt="Esama nuotrauka" style="width:60px; height:60px; object-fit:cover; border-radius:8px;">
                  <span style="font-size:13px; color:var(--muted);">Esama nuotrauka</span>
                </div>
             <?php endif; ?>
             <input type="file" name="image" accept="image/*" style="padding:10px;">
          </label>
          
          <div style="display:flex; gap:12px; margin-top: 24px;">
            <button class="btn" type="submit">Išsaugoti</button>
            <a class="btn btn-secondary" href="/community_market.php">Atšaukti</a>
          </div>
        </form>
    </div>
  </div>

  <?php renderFooter($pdo); ?>
</body>
</html>
