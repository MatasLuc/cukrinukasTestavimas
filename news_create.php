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
ensureNewsTable($pdo); // Užtikrina ir ryšių lentelės buvimą
ensureAdminAccount($pdo);
tryAutoLogin($pdo);

// Gauname kategorijas
$categories = $pdo->query("SELECT * FROM news_categories ORDER BY name ASC")->fetchAll();

$errors = [];
$title = '';
$summary = '';
$author = '';
$selectedCatIds = [];
$body = '';
$visibility = 'public';
$isFeatured = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    
    $title = trim($_POST['title'] ?? '');
    $summary = trim($_POST['summary'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $selectedCatIds = $_POST['categories'] ?? []; // Gauname masyvą
    $body = trim($_POST['body'] ?? '');
    $visibility = $_POST['visibility'] === 'members' ? 'members' : 'public';
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;

    if ($title === '' || $body === '' || $summary === '') {
        $errors[] = 'Užpildykite pavadinimą, santrauką ir tekstą.';
    }

    $imagePath = '';
    $uploaded = uploadImageWithValidation($_FILES['image'] ?? [], 'news_', $errors, 'Įkelkite naujienos nuotrauką.');
    if ($uploaded) {
        $imagePath = $uploaded;
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // 1. Įrašome naujieną
            $stmt = $pdo->prepare('INSERT INTO news (title, summary, author, image_url, body, visibility, is_featured) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$title, $summary, $author, $imagePath, $body, $visibility, $isFeatured]);
            $newsId = $pdo->lastInsertId();

            // 2. Įrašome kategorijas
            if (!empty($selectedCatIds)) {
                $relStmt = $pdo->prepare('INSERT INTO news_category_relations (news_id, category_id) VALUES (?, ?)');
                foreach ($selectedCatIds as $catId) {
                    $relStmt->execute([$newsId, (int)$catId]);
                }
            }

            $pdo->commit();
            header('Location: /news.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logError('News creation failed', $e);
            $errors[] = friendlyErrorMessage();
        }
    }
}
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kurti naujieną | Cukrinukas</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <?php echo headerStyles(); ?>
  <style>
    :root { --color-bg: #f7f7fb; --color-primary: #0b0b0b; }
    * { box-sizing: border-box; }
    .wrapper { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .card { background: #fff; padding: 28px; border-radius: 16px; box-shadow: 0 14px 32px rgba(0,0,0,0.08); width: min(720px, 100%); }
    .card h1 { margin: 0 0 8px; font-size: 26px; }
    label { display: block; margin: 14px 0 6px; font-weight: 600; }
    input[type=text], input[type=file], textarea, select { width: 100%; padding: 12px; border-radius: 12px; border: 1px solid #d7d7e2; background: #f9f9ff; font-size: 15px; }
    textarea { min-height: 100px; resize: vertical; }
    button[type=submit] { padding: 12px 18px; border-radius: 12px; border: none; background: #0b0b0b; color: #fff; font-weight: 600; cursor:pointer; margin-top: 14px; }
    .notice.error { background: #fff1f1; border: 1px solid #f3b7b7; color: #991b1b; padding:12px; border-radius:12px; margin-bottom:12px; }
    
    /* Kategorijų stilius */
    .cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; border: 1px solid #d7d7e2; padding: 12px; border-radius: 12px; background: #f9f9ff; max-height: 200px; overflow-y: auto; }
    .cat-item { display:flex; align-items:center; gap:8px; cursor:pointer; padding:4px; border-radius:6px; transition:background 0.1s; margin:0 !important; font-weight: normal !important;}
    .cat-item:hover { background:#eef2ff; }
    .cat-item input { width:18px; height:18px; accent-color:#0b0b0b; cursor:pointer; margin:0; }

    /* Redaktoriaus stiliai */
    .toolbar button, .toolbar input, .toolbar select { border-radius:8px; padding:6px 10px; border:1px solid #d7d7e2; background:#fff; cursor:pointer; color:#0b0b0b; font-weight:600; user-select: none; font-size:14px; }
    .toolbar button:hover { background:#f0f0f0; }
    .toolbar input[type=color] { padding:0; width:36px; height:32px; vertical-align:middle; }
    .rich-editor { min-height:300px; padding:16px; border:1px solid #d7d7e2; border-radius:12px; background:#f9f9ff; font-family: 'Inter', sans-serif; line-height:1.6; }
    .rich-editor img { max-width:100%; height:auto; display:block; margin:12px 0; border-radius:12px; }
    .rich-editor ul, .rich-editor ol { padding-left: 20px; }
    .rich-editor blockquote { border-left: 4px solid #ccc; margin: 10px 0; padding-left: 10px; color: #555; }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'news'); ?>
  <div class="wrapper">
    <div class="card">
      <h1>Nauja naujiena</h1>
      <p style="margin:0 0 14px; color:#444;">Paskelbkite naują įrašą diabeto bendruomenei.</p>

      <?php if ($errors): ?>
        <div class="notice error">
          <?php foreach ($errors as $error): echo htmlspecialchars($error) . '<br>'; endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" onsubmit="return syncBody();">
        <?php echo csrfField(); ?>
        
        <label for="title">Pavadinimas</label>
        <input id="title" name="title" type="text" required value="<?php echo htmlspecialchars($title); ?>">

        <label for="author">Autorius</label>
        <input id="author" name="author" type="text" value="<?php echo htmlspecialchars($author); ?>" placeholder="pvz. Redakcija">

        <label>Kategorijos (galima žymėti kelias)</label>
        <div class="cat-grid">
            <?php foreach ($categories as $cat): ?>
                <label class="cat-item">
                    <input type="checkbox" name="categories[]" value="<?php echo $cat['id']; ?>" 
                        <?php echo in_array($cat['id'], $selectedCatIds) ? 'checked' : ''; ?>>
                    <span><?php echo htmlspecialchars($cat['name']); ?></span>
                </label>
            <?php endforeach; ?>
            <?php if(empty($categories)): ?>
                <div style="color:#666; font-size:14px; padding:4px;">Nėra sukurtų kategorijų.</div>
            <?php endif; ?>
        </div>

        <label for="summary">Santrauka (rodoma sąraše)</label>
        <textarea id="summary" name="summary" required><?php echo htmlspecialchars($summary); ?></textarea>

        <label for="image">Pagrindinė nuotrauka</label>
        <input id="image" name="image" type="file" accept="image/*" required>

        <label for="body-editor">Turinys</label>
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
            <option value="6">Milžiniškas</option>
          </select>
          <button type="button" onmousedown="event.preventDefault()" onclick="format('removeFormat')">Išvalyti</button>
        </div>
        
        <div id="body-editor" class="rich-editor" contenteditable="true">
          <?php echo sanitizeHtml($body); ?>
        </div>
        
        <input type="file" id="inline-image-input" accept="image/*" style="display:none;">
        <textarea id="body" name="body" hidden><?php echo htmlspecialchars($body); ?></textarea>

        <label style="display:flex; align-items:center; gap:8px; margin-top:12px;">
          <input type="checkbox" name="is_featured" value="1" <?php echo $isFeatured ? 'checked' : ''; ?> style="width:auto;"> Rodyti kaip išskirtinę (titulinė)
        </label>

        <label for="visibility">Matomumas</label>
        <select id="visibility" name="visibility">
          <option value="public" <?php echo $visibility === 'public' ? 'selected' : ''; ?>>Visiems matoma</option>
          <option value="members" <?php echo $visibility === 'members' ? 'selected' : ''; ?>>Tik prisijungusiems</option>
        </select>

        <button type="submit">Sukurti naujieną</button>
      </form>
      
      <div style="margin-top: 16px;">
        <a href="/news.php" style="text-decoration:underline;">Atšaukti</a>
      </div>
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
      const url = prompt('Įveskite nuorodą (pvz. https://google.com):');
      if (url) { format('createLink', url); }
    }
    
    function decorateImages() {
      // Užtikrina, kad įkelti paveikslėliai atrodytų gerai
      editor.querySelectorAll('img').forEach(img => {
        img.style.maxWidth = '100%';
        img.style.height = 'auto';
        img.style.display = 'block';
        img.style.margin = '12px 0';
        img.style.borderRadius = '12px';
      });
    }
    
    // Paveikslėlių įkėlimas į tekstą
    async function triggerInlineImage() {
      inlineImageInput.click();
    }
    
    inlineImageInput.addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      
      const formData = new FormData();
      formData.append('image', file);
      // Pabandome gauti CSRF tokeną, jei jis yra formoje
      const csrfEl = document.querySelector('input[name="csrf_token"]');
      if (csrfEl) {
          formData.append('csrf_token', csrfEl.value);
      }

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
        console.error(err);
        alert('Klaida įkeliant nuotrauką.');
      }
      inlineImageInput.value = '';
    });
    
    function syncBody() {
      decorateImages();
      const content = editor.innerHTML.trim();
      if (!content || content === '<br>') {
        alert('Prašome užpildyti naujienos turinį.');
        return false;
      }
      hiddenBody.value = content;
      return true;
    }
    
    // Pradinis formatavimas (jei redaguojama)
    decorateImages();
  </script>
  <?php renderFooter($pdo); ?>
</body>
</html>
