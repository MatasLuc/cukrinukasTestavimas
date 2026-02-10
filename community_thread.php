<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
ensureUsersTable($pdo);
ensureCommunityTables($pdo);
tryAutoLogin($pdo);

$threadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Gauname temą
$stmt = $pdo->prepare('
    SELECT t.*, u.name as author_name, c.name as category_name, u.is_admin
    FROM community_threads t
    JOIN users u ON u.id = t.user_id
    LEFT JOIN community_thread_categories c ON c.id = t.category_id
    WHERE t.id = ?
');
$stmt->execute([$threadId]);
$thread = $stmt->fetch();

if (!$thread) {
    header('Location: /community_discussions.php');
    exit;
}

// Komentarų gavimas
$stmtComments = $pdo->prepare('
    SELECT c.*, u.name as author_name, u.is_admin
    FROM community_comments c
    JOIN users u ON u.id = c.user_id
    WHERE c.thread_id = ?
    ORDER BY c.created_at ASC
');
$stmtComments->execute([$threadId]);
$comments = $stmtComments->fetchAll();

// Komentaro rašymas
$user = currentUser();
$blocked = $user['id'] ? isCommunityBlocked($pdo, (int)$user['id']) : null;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['body'])) {
    if (!$user) {
        header('Location: /login.php');
        exit;
    }
    if ($blocked) {
        $error = 'Jūs esate užblokuotas bendruomenėje.';
    } else {
        validateCsrfToken();
        $body = trim($_POST['body']);
        if (strlen($body) < 2) {
            $error = 'Komentaras per trumpas.';
        } else {
            $ins = $pdo->prepare('INSERT INTO community_comments (thread_id, user_id, body) VALUES (?, ?, ?)');
            $ins->execute([$threadId, $user['id'], $body]);
            
            // Atnaujinam temos updated_at
            $pdo->prepare('UPDATE community_threads SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$threadId]);
            
            $_SESSION['flash_success'] = 'Komentaras pridėtas.';
            header("Location: /community_thread.php?id=$threadId");
            exit;
        }
    }
}

if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($thread['title']); ?> | Diskusijos</title>
  <?php echo headerStyles(); ?>
<style>
/* Bendras stilius */
:root { --bg: #f7f7fb; --card: #ffffff; --border: #e4e7ec; --text: #1f2937; --muted: #52606d; --accent: #2563eb; }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--text); }
a { color:inherit; text-decoration:none; }

.page { max-width: 1200px; margin: 0 auto; padding: 32px 20px 72px; display: grid; gap: 28px; }

/* Hero sekcija */
.hero {
  padding: 26px; border-radius: 28px; background: linear-gradient(135deg, #eff6ff, #dbeafe);
  border: 1px solid #e5e7eb; box-shadow: 0 18px 48px rgba(0,0,0,0.08);
  display: flex; flex-direction: column; gap: 12px;
}

.hero__pill { display:inline-flex; align-items:center; gap:8px; background:#fff; padding:6px 12px; border-radius:999px; font-weight:700; font-size: 13px; color: #0f172a; box-shadow:0 2px 8px rgba(0,0,0,0.05); }
.hero h1 { margin: 8px 0 4px; font-size: clamp(22px, 4vw, 30px); color: #0f172a; line-height: 1.3; }

.thread-meta { display: flex; align-items: center; gap: 12px; color: var(--muted); font-size: 14px; margin-top: 6px; flex-wrap: wrap; }
.author-avatar { width: 28px; height: 28px; border-radius: 50%; background: #dbeafe; color: var(--accent); display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px; border: 1px solid #bfdbfe; }

/* Kortelės */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }

.post-body { line-height: 1.7; color: #374151; font-size: 16px; white-space: pre-wrap; }

/* Komentarai */
.comments-list { display: flex; flex-direction: column; gap: 16px; margin-top: 24px; }
.comment-item { 
    background: #fff; border: 1px solid var(--border); 
    border-radius: 16px; padding: 18px; 
    display: flex; flex-direction: column; gap: 10px;
}
.comment-header { display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: var(--muted); }
.comment-author { font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px; }
.admin-badge { background: #fef3c7; color: #d97706; padding: 2px 6px; border-radius: 4px; font-size: 10px; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px; }

/* Forma */
.comment-form textarea {
    width: 100%; padding: 14px; border-radius: 12px; border: 1px solid var(--border);
    font-family: inherit; font-size: 15px; min-height: 100px; margin-bottom: 12px;
    background: #f9fafb; color: var(--text);
    transition: all .2s;
}
.comment-form textarea:focus { border-color: var(--accent); background: #fff; outline: none; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }

.btn { display: inline-flex; align-items: center; justify-content: center; padding: 10px 20px; border-radius: 12px; background: #0b0b0b; color: #fff; border: 1px solid #0b0b0b; font-weight: 600; cursor: pointer; transition: opacity 0.2s; font-size: 14px; }
.btn:hover { opacity: 0.9; }
.btn-secondary { background: #fff; color: #0b0b0b; border-color: var(--border); }

.alert { border-radius:12px; padding:12px; margin-bottom: 20px; }
.alert-success { background:#ecfdf5; border:1px solid #a7f3d0; color: #065f46; }
.alert-error { background:#fef2f2; border:1px solid #fecaca; color: #991b1b; }
</style>
</head>
<body>
  <?php renderHeader($pdo, 'community'); ?>

  <div class="page">
    <section class="hero">
       <div style="margin-bottom: -5px;">
        <a href="/community_discussions.php" style="font-size:13px; font-weight:600; color:var(--muted); display:inline-flex; align-items:center; gap:4px;">← Atgal į diskusijas</a>
       </div>
       
       <div>
         <?php if ($thread['category_name']): ?>
            <div class="hero__pill">#<?php echo htmlspecialchars($thread['category_name']); ?></div>
         <?php endif; ?>
         
         <h1><?php echo htmlspecialchars($thread['title']); ?></h1>
         
         <div class="thread-meta">
            <div style="display:flex; align-items:center; gap:6px;">
                <div class="author-avatar"><?php echo strtoupper(substr($thread['author_name'], 0, 1)); ?></div>
                <span style="font-weight:600; color:var(--text);"><?php echo htmlspecialchars($thread['author_name']); ?></span>
            </div>
            <span>•</span>
            <span><?php echo date('Y-m-d H:i', strtotime($thread['created_at'])); ?></span>
            <?php if (!empty($thread['is_admin'])): ?>
                <span class="admin-badge">ADMIN</span>
            <?php endif; ?>
         </div>
       </div>
    </section>
    
    <?php if ($success): ?>
        <div class="alert alert-success">&check; <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">&times; <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card">
       <div class="post-body"><?php echo nl2br(htmlspecialchars($thread['body'])); ?></div>
    </div>

    <div>
       <h3 style="margin-bottom:16px; font-size:18px;">Komentarai (<?php echo count($comments); ?>)</h3>
       
       <div class="card comment-form">
         <?php if ($user['id']): ?>
            <form method="post">
                <?php echo csrfField(); ?>
                <textarea name="body" placeholder="Rašyti atsakymą..." required></textarea>
                <div style="text-align:right;">
                    <button class="btn" type="submit">Komentuoti</button>
                </div>
            </form>
         <?php else: ?>
            <div style="text-align:center; padding: 10px; color: var(--muted);">
                <a href="/login.php" style="color:var(--accent); font-weight:bold;">Prisijunkite</a>, kad galėtumėte komentuoti.
            </div>
         <?php endif; ?>
       </div>

       <div class="comments-list">
          <?php foreach ($comments as $comment): ?>
             <div class="comment-item" id="c<?php echo $comment['id']; ?>">
                <div class="comment-header">
                    <div class="comment-author">
                        <div class="author-avatar" style="width:24px; height:24px; font-size:11px;"><?php echo strtoupper(substr($comment['author_name'], 0, 1)); ?></div>
                        <?php echo htmlspecialchars($comment['author_name']); ?>
                        <?php if (!empty($comment['is_admin'])): ?>
                            <span class="admin-badge">ADMIN</span>
                        <?php endif; ?>
                    </div>
                    <span><?php echo date('Y-m-d H:i', strtotime($comment['created_at'])); ?></span>
                </div>
                <div style="line-height:1.5; color:var(--text);">
                    <?php echo nl2br(htmlspecialchars($comment['body'])); ?>
                </div>
             </div>
          <?php endforeach; ?>
          
          <?php if (empty($comments)): ?>
            <div style="text-align:center; padding:20px; color:var(--muted); font-size:14px;">
                Dar nėra komentarų. Būkite pirmas!
            </div>
          <?php endif; ?>
       </div>
    </div>
  </div>

  <?php renderFooter($pdo); ?>
</body>
</html>
