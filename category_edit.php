<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: /login.php');
    exit;
}

$pdo = getPdo();
tryAutoLogin($pdo);
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
$stmt->execute([$id]);
$category = $stmt->fetch();

if (!$category) {
    die('Kategorija nerasta');
}

// Gauname visas kitas kategorijas (kad galėtume pasirinkti tėvą)
// Išfiltruojame pačią save, kad negalėtų būti savo tėvu
$allCats = $pdo->prepare("SELECT * FROM categories WHERE id != ? AND (parent_id IS NULL OR parent_id != ?) ORDER BY name");
$allCats->execute([$id, $id]); 
$potentialParents = $allCats->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $name = trim($_POST['name']);
    $slug = trim($_POST['slug']);
    $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

    if ($name && $slug) {
        $upd = $pdo->prepare('UPDATE categories SET name = ?, slug = ?, parent_id = ? WHERE id = ?');
        $upd->execute([$name, $slug, $parentId, $id]);
        header('Location: /admin.php?view=categories');
        exit;
    }
}
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <title>Redaguoti kategoriją</title>
  <?php echo headerStyles(); ?>
  <style>
      .page { max-width:600px; margin:40px auto; padding:20px; background:#fff; border-radius:12px; box-shadow:0 5px 15px rgba(0,0,0,0.05); }
      label { display:block; margin:12px 0 6px; font-weight:600; }
      input, select { width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; }
      .btn { margin-top:20px; }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'admin'); ?>
  <div class="page">
    <h2>Redaguoti kategoriją</h2>
    <form method="post">
      <?php echo csrfField(); ?>
      
      <label>Pavadinimas</label>
      <input name="name" value="<?php echo htmlspecialchars($category['name']); ?>" required>
      
      <label>Slug (URL dalis)</label>
      <input name="slug" value="<?php echo htmlspecialchars($category['slug']); ?>" required>
      
      <label>Tėvinė kategorija</label>
      <select name="parent_id">
          <option value="">-- Pagrindinė (be tėvo) --</option>
          <?php foreach ($potentialParents as $p): ?>
            <option value="<?php echo $p['id']; ?>" <?php echo $category['parent_id'] == $p['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($p['name']); ?>
            </option>
          <?php endforeach; ?>
      </select>
      
      <button class="btn" type="submit">Išsaugoti</button>
      <a href="/admin.php?view=categories" style="margin-left:10px; color:#666;">Atšaukti</a>
    </form>
  </div>
</body>
</html>
