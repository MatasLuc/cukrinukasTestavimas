<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/layout.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: /login.php');
    exit;
}

$pdo = getPdo();
ensureRecipesTable($pdo);
ensureAdminAccount($pdo);
tryAutoLogin($pdo);

// Visos receptų kategorijos
$categories = $pdo->query("SELECT * FROM recipe_categories ORDER BY name ASC")->fetchAll();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM recipes WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    die('Įrašas nerastas');
}

// GAUNAME PRISKIRTAS KATEGORIJAS
$stmtCats = $pdo->prepare("SELECT category_id FROM recipe_category_relations WHERE recipe_id = ?");
$stmtCats->execute([$id]);
$currentCatIds = $stmtCats->fetchAll(PDO::FETCH_COLUMN);

$errors = [];
$message = '';
// Numatytosios reikšmės iš DB
$title = $item['title'];
$summary = $item['summary'] ?? '';
$author = $item['author'];
$body = $item['body'];
$visibility = $item['visibility'];

// Nauji kintamieji
$isVisible = $item['is_visible'] ?? 1;
$publishDate = $item['publish_date'] ? date('Y-m-d\TH:i', strtotime($item['publish_date'])) : date('Y-m-d\TH:i');
$seoKeywords = $item['seo_keywords'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $title = trim($_POST['title'] ?? '');
    $summary = trim($_POST['summary'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $selectedCatIds = $_POST['categories'] ?? [];
    $body = trim($_POST['body'] ?? '');
    $visibility = $_POST['visibility'] === 'members' ? 'members' : 'public';
    
    // Naujų laukų nuskaitymas
    $isVisible = isset($_POST['is_visible']) ? 1 : 0;
    $publishDateInput = $_POST['publish_date'] ?? '';
    $publishDateDb = $publishDateInput ? date('Y-m-d H:i:s', strtotime($publishDateInput)) : date('Y-m-d H:i:s');
    $seoKeywords = trim($_POST['seo_keywords'] ?? '');

    // Formos reikšmės atstatymui
    if ($publishDateInput) {
        $publishDate = date('Y-m-d\TH:i', strtotime($publishDateInput));
    }

    if ($title === '' || $body === '' || $summary === '') {
        $errors[] = 'Užpildykite visus privalomus laukus.';
    }

    $imagePath = $item['image_url'];
    $newImage = uploadImageWithValidation($_FILES['image'] ?? [], 'recipe_', $errors, null);
    if ($newImage) {
        $imagePath = $newImage;
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // 1. Atnaujiname recepto info
            $stmt = $pdo->prepare('UPDATE recipes SET title = ?, summary = ?, author = ?, image_url = ?, body = ?, visibility = ?, is_visible = ?, publish_date = ?, seo_keywords = ? WHERE id = ?');
            $stmt->execute([$title, $summary, $author, $imagePath, $body, $visibility, $isVisible, $publishDateDb, $seoKeywords, $id]);

            // 2. Atnaujiname kategorijas: Ištriname senas -> Įrašome naujas
            $pdo->prepare("DELETE FROM recipe_category_relations WHERE recipe_id = ?")->execute([$id]);
            
            if (!empty($selectedCatIds)) {
                $relStmt = $pdo->prepare('INSERT INTO recipe_category_relations (recipe_id, category_id) VALUES (?, ?)');
                foreach ($selectedCatIds as $catId) {
                    $relStmt->execute([$id, (int)$catId]);
                }
            }
            
            $pdo->commit();
            
            $message = 'Receptas sėkmingai atnaujintas';
            // Atnaujiname atvaizdavimui skirtą kintamąjį
            $currentCatIds = $selectedCatIds;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logError('Recipe update failed', $e);
            $errors[] = friendlyErrorMessage();
        }
    }
}

$safeBody = sanitizeHtml($body);
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Redaguoti receptą</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <?php echo headerStyles(); ?>
  <style>
    :root { --color-bg: #f7f7fb; }
    .wrapper { padding: 24px; display:flex; justify-content:center; }
    .card { background: #fff; padding: 28px; border-radius: 16px; width: min(720px, 100%); box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
    
    label { display:block; margin:12px 0 5px; font-weight:600; }
    input[type=text], select, textarea, input[type=datetime-local] { width: 100%; padding: 10px; border:1px solid #ccc; border-radius:8px; background:#fbfbff; }
    
    .notice.success { background:#e6fffa; color:#047481; padding:10px; border-radius:8px; margin-bottom:10px; border:1px solid #b2f5ea; }
    .notice.error { background:#fff5f5; color:red; padding:10px; border:1px solid red; border-radius:8px; }
    
    .cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; border: 1px solid #ddd; padding: 12px; border-radius: 8px; background: #fbfbff; max-height: 200px; overflow-y: auto; }
    .cat-item { display:flex; align-items:center; gap:8px; cursor:pointer; padding:4px; transition:background 0.1s; border-radius:4px; margin:0 !important; font-weight:normal !important; }
    .cat-item:hover { background:#eee; }

    /* Redaktorius */
    .toolbar button, .toolbar input, .toolbar select { border-radius:8px; padding:6px 10px; border:1px solid #d7d7e2; background:#fff; cursor:pointer; color:#0b0b0b; font-weight:600; user-select: none; font-size:14px; }
    .toolbar input[type=color] { padding:0; width:36px; height:32px; vertical-align:middle; }
    .rich-editor { min-height:300px; padding:16px; border:1px solid #d7d7e2; border-radius:12px; background:#fbfbff; font-family: 'Inter', sans-serif; line-height:1.6; }
    .rich-editor img { max-width:100%; height:auto; display:block; margin:12px 0; border-radius:12px; }
    .rich-editor blockquote { border-left: 4px solid #ccc; margin: 10px 0; padding-left: 10px; color: #555; }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'recipes'); ?>
  <div class="wrapper">
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center;">
          <h1>Redaguoti receptą</h1>
          <a href="/recipes.php" class="btn secondary" style="font-size:14px; padding:6px 12px; border:1px solid #ccc; border-radius:8px;">Grįžti</a>
      </div>

      <?php if ($message): ?><div class="notice success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
      <?php if ($errors): ?><div class="notice error"><?= implode('<br>', $errors) ?></div><?php endif; ?>
      
      <form method="post" enctype="multipart/form-data" onsubmit="return syncBody();">
        <?php echo csrfField(); ?>

        <label style="display:flex; align-items:center; gap:8px; margin-bottom:14px; padding: 10px; background: #eef2ff; border-radius: 8px;">
          <input type="checkbox" name="is_visible" value="1" <?= $isVisible ? 'checked' : '' ?> style="width:auto; transform: scale(1.2);"> 
          <b>Įrašas aktyvus (matomas lankytojams)</b>
        </label>

        <label for="publish_date">Publikavimo data ir laikas (suplanuoti į priekį)</label>
        <input id="publish_date" name="publish_date" type="datetime-local" value="<?= htmlspecialchars($publishDate) ?>">
        
        <label>Pavadinimas</label>
        <input name="title" type="text" value="<?= htmlspecialchars($title) ?>" required>

        <label>Autorius</label>
        <input name="author" type="text" value="<?= htmlspecialchars($author) ?>">

        <label>Kategorijos</label>
        <div class="cat-grid">
            <?php foreach ($categories as $cat): ?>
                <label class="cat-item">
                    <input type="checkbox" name="categories[]" value="<?= $cat['id'] ?>" 
                        <?= in_array($cat['id'], $currentCatIds) ? 'checked' : '' ?> 
                        style="width:auto; margin:0;">
                    <?= htmlspecialchars($cat['name']) ?>
                </label>
            <?php endforeach; ?>
        </div>

        <label>Santrauka</label>
        <textarea name="summary" required style="min-height:80px;"><?= htmlspecialchars($summary) ?></textarea>

        <label>Nuotrauka</label>
        <div style="display:flex; align-items:center; gap:15px; margin-bottom:5px;">
            <?php if($item['image_url']): ?>
                <img src="<?= htmlspecialchars($item['image_url']) ?>" style="width:60px; height:60px; object-fit:cover; border-radius:6px;">
            <?php endif; ?>
            <input name="image" type="file" accept="image/*">
        </div>
        
        <label>Aprašymas</label>
        <div class="toolbar" style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:8px;">
          <button type="button" onmousedown="event.preventDefault()" onclick="format('bold')"><b>B</b></button>
          <button type="button" onmousedown="event.preventDefault()" onclick="format('italic')"><em>I</em></button>
          <button type="button" onmousedown="event.preventDefault()" onclick="format('underline')"><u>U</u></button>
          <button type="button" onmousedown="event.preventDefault()" onclick="format('strikeThrough')"><s>S</s></button>
          
          <span style="border-left:1px solid #ddd; margin:0 4px;"></span>
          
          <button type="button" onmousedown="event.preventDefault()" onclick="format('insertUnorderedList')">• Sąrašas</button>
          <button type="button" onmousedown="event.preventDefault()" onclick="format('insertOrderedList')">1. Sąrašas</button>
          <button type="button" onmousedown="event.preventDefault()" onclick="format('formatBlock','blockquote')">Citata</button>
          
          <span style="border-left:1px solid #ddd; margin:0 4px;"></span>

          <button type="button" onmousedown="event.preventDefault()" onclick="format('justifyLeft')">↤</button>
          <button type="button" onmousedown="event.preventDefault()" onclick="format('justifyCenter')">↔</button>
          <button type="button" onmousedown="event.preventDefault()" onclick="format('justifyRight')">↦</button>
          
          <span style="border-left:1px solid #ddd; margin:0 4px;"></span>

          <button type="button" onmousedown="event.preventDefault()" onclick="createLink()">🔗 Nuoroda</button>
          <button type="button" onmousedown="event.preventDefault()" onclick="triggerInlineImage()">🖼️ Įkelti foto</button>
          
          <input type="color" onchange="formatColor(this.value)" title="Teksto spalva">
          <select onchange="format('fontSize', this.value)" style="width:auto; padding:6px;">
            <option value="3">Dydis</option>
            <option value="2">Mažas</option>
            <option value="3">Vidutinis</option>
            <option value="4">Didelis</option>
            <option value="5">Labai didelis</option>
          </select>
          <button type="button" onmousedown="event.preventDefault()" onclick="format('removeFormat')">Išvalyti</button>
        </div>

        <div id="body-editor" class="rich-editor" contenteditable="true"><?= $safeBody ?></div>
        
        <input type="file" id="inline-image-input" accept="image/*" style="display:none;">
        <textarea id="body" name="body" hidden><?= htmlspecialchars($body) ?></textarea>

        <label for="seo_keywords">SEO Raktažodžiai (atskirti kableliais)</label>
        <input id="seo_keywords" name="seo_keywords" type="text" value="<?= htmlspecialchars($seoKeywords) ?>" placeholder="diabetas, receptai, mityba...">

        <label>Prieigos lygis</label>
        <select name="visibility">
            <option value="public" <?= $visibility == 'public' ? 'selected' : '' ?>>Visiems matomas</option>
            <option value="members" <?= $visibility == 'members' ? 'selected' : '' ?>>Tik registruotiems</option>
        </select>

        <button type="submit" style="background:#0b0b0b; color:#fff; padding:12px 24px; border-radius:10px; border:none; margin-top:20px; font-weight:bold; cursor:pointer; width:100%;">Išsaugoti pakeitimus</button>
      </form>
    </div>
  </div>
  <script>
    const editor = document.getElementById('body-editor');
    const hiddenBody = document.getElementById('body');
    const inlineImageInput = document.getElementById('inline-image-input');

    function format(cmd, value = null) {
      document.execCommand(cmd, false, value);
      editor.focus();
    }
    
    function formatColor(color) { 
        format('foreColor', color); 
    }
    
    function createLink() {
      const url = prompt('Įveskite nuorodą:');
      if (url) { format('createLink', url); }
    }
    
    function decorateImages() {
      editor.querySelectorAll('img').forEach(img => {
        img.style.maxWidth = '100%';
        img.style.height = 'auto';
        img.style.display = 'block';
        img.style.margin = '12px 0';
        img.style.borderRadius = '12px';
      });
    }
    
    async function triggerInlineImage() {
      inlineImageInput.click();
    }
    
    inlineImageInput.addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      
      const formData = new FormData();
      formData.append('image', file);
      const csrfEl = document.querySelector('input[name="csrf_token"]');
      if (csrfEl) formData.append('csrf_token', csrfEl.value);

      try {
        const res = await fetch('/editor_upload.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success && data.url) {
          format('insertImage', data.url);
          decorateImages();
        } else {
          alert(data.error || 'Nepavyko įkelti nuotraukos');
        }
      } catch (err) {
        alert('Klaida įkeliant nuotrauką');
      }
      inlineImageInput.value = '';
    });

    function syncBody() {
      decorateImages();
      const content = editor.innerHTML.trim();
      if (!content || content === '<br>') {
          alert('Negalima išsaugoti tuščio turinio.');
          return false;
      }
      hiddenBody.value = content;
      return true;
    }
    
    decorateImages();
  </script>
</body>
</html>