<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
$messages = [];
$errors = [];
ensureUsersTable($pdo);
tryAutoLogin($pdo);
$dmReady = true;
try {
    ensureDirectMessages($pdo);
} catch (Throwable $e) {
    $dmReady = false;
    logError('Direct messages table bootstrap failed', $e);
    $errors[] = friendlyErrorMessage();
}
ensureNavigationTable($pdo);
$systemUserId = ensureSystemUser($pdo);

$user = currentUser();
if (!$user['id']) {
    header('Location: /login.php');
    exit;
}
$activePartnerId = isset($_GET['user']) ? (int)$_GET['user'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';
    
    if (!$dmReady) {
        $errors[] = 'Žinučių siųsti nepavyko (lentelė nepasiekiama).';
    } elseif ($action === 'send_new') {
        // Pakeista logika: priimame bendrą identifikatorių (vardą arba el. paštą)
        $recipientInput = trim($_POST['recipient_input'] ?? '');
        $body = trim($_POST['body'] ?? '');

        if (!$recipientInput || !$body) {
            $errors[] = 'Užpildykite gavėją ir žinutę.';
        } else {
            try {
                // Ieškome vartotojo pagal el. paštą ARBA vardą
                $stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = ? OR name = ? LIMIT 1');
                $stmt->execute([$recipientInput, $recipientInput]);
                $recipient = $stmt->fetch();

                if (!$recipient) {
                    $errors[] = 'Vartotojas tokiu vardu arba el. paštu nerastas.';
                } elseif ((int)$recipient['id'] === (int)$user['id']) {
                    $errors[] = 'Negalite rašyti sau.';
                } else {
                    $insert = $pdo->prepare('INSERT INTO direct_messages (sender_id, recipient_id, body) VALUES (?, ?, ?)');
                    $insert->execute([$user['id'], $recipient['id'], $body]);
                    $messages[] = 'Žinutė išsiųsta ' . htmlspecialchars($recipient['name']);
                    $activePartnerId = (int)$recipient['id'];
                }
            } catch (Throwable $e) {
                logError('Sending new direct message failed', $e);
                $errors[] = friendlyErrorMessage();
            }
        }
    }

    if ($dmReady && $action === 'send_existing') {
        $partnerId = (int)($_POST['partner_id'] ?? 0);
        $body = trim($_POST['body'] ?? '');
        if ($partnerId && $body) {
            try {
                $insert = $pdo->prepare('INSERT INTO direct_messages (sender_id, recipient_id, body) VALUES (?, ?, ?)');
                $insert->execute([$user['id'], $partnerId, $body]);
                $messages[] = 'Žinutė išsiųsta.';
                $activePartnerId = $partnerId;
            } catch (Throwable $e) {
                logError('Sending existing direct message failed', $e);
                $errors[] = friendlyErrorMessage();
            }
        }
    }
}

$conversations = [];
$partnerIds = [];
if ($dmReady) {
    try {
        $stmt = $pdo->prepare('SELECT CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END AS partner_id, MAX(created_at) AS last_time FROM direct_messages WHERE sender_id = ? OR recipient_id = ? GROUP BY partner_id ORDER BY last_time DESC');
        $stmt->execute([$user['id'], $user['id'], $user['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $partnerIds[] = (int)$row['partner_id'];
            $conversations[(int)$row['partner_id']] = ['partner_id' => (int)$row['partner_id'], 'last_time' => $row['last_time']];
        }
    } catch (Throwable $e) {
        logError('Loading conversation list failed', $e);
        $errors[] = friendlyErrorMessage();
    }
}

if ($partnerIds) {
    $in = implode(',', array_fill(0, count($partnerIds), '?'));
    $detailStmt = $pdo->prepare("SELECT id, name FROM users WHERE id IN ($in)");
    $detailStmt->execute($partnerIds);
    foreach ($detailStmt->fetchAll() as $u) {
        $pid = (int)$u['id'];
        if (isset($conversations[$pid])) {
            $conversations[$pid]['name'] = $u['name'];
        }
    }
}

if (!$activePartnerId && $partnerIds) {
    $activePartnerId = $partnerIds[0];
}

$activePartnerName = null;
if ($activePartnerId) {
    if (isset($conversations[$activePartnerId]['name'])) {
        $activePartnerName = $conversations[$activePartnerId]['name'];
    } else {
        try {
            $nameStmt = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
            $nameStmt->execute([$activePartnerId]);
            $foundName = $nameStmt->fetchColumn();
            if ($foundName) {
                $activePartnerName = $foundName;
            }
        } catch (Throwable $e) {
            // Leave as null
        }
    }
}

$threadMessages = [];
if ($dmReady && $activePartnerId) {
    try {
        $stmt = $pdo->prepare('SELECT m.*, s.name AS sender_name, s.profile_photo AS sender_photo FROM direct_messages m JOIN users s ON s.id = m.sender_id WHERE (m.sender_id = :uid1 AND m.recipient_id = :pid1) OR (m.sender_id = :pid2 AND m.recipient_id = :uid2) ORDER BY m.created_at ASC');
        $stmt->execute([':uid1' => $user['id'], ':pid1' => $activePartnerId, ':pid2' => $activePartnerId, ':uid2' => $user['id']]);
        $threadMessages = $stmt->fetchAll();
        markDirectMessagesRead($pdo, $user['id'], $activePartnerId);
    } catch (Throwable $e) {
        logError('Loading direct message thread failed', $e);
        $errors[] = friendlyErrorMessage();
    }
}

echo headerStyles();
renderHeader($pdo, 'community');
?>
<style>
  :root {
      --bg: #f7f7fb;
      --card: #ffffff;
      --border: #e4e7ec;
      --text-main: #0f172a;
      --text-muted: #475467;
      --accent: #2563eb;
      --accent-hover: #1d4ed8;
      --accent-light: #eff6ff;
      --focus-ring: rgba(37, 99, 235, 0.2);
  }
  body { margin:0; background: var(--bg); color: var(--text-main); font-family:'Inter', sans-serif; }
  a { color:inherit; text-decoration:none; }

  /* Pakeistas max-width į 1200px ir padding/gap pagal news.php */
  .page { max-width:1200px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction:column; gap:28px; }
  
  /* Hero matching Account/Login style */
  .hero { 
      background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
      border:1px solid #dbeafe; 
      border-radius:24px; 
      padding:32px; 
      display:flex; 
      justify-content:space-between; 
      gap:24px; 
      flex-wrap:wrap; 
      align-items:center; 
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
  }
  .hero h1 { margin:0 0 8px; font-size:28px; color:#1e3a8a; letter-spacing:-0.5px; }
  .hero p { margin:0; color:#1e40af; line-height:1.5; max-width:640px; font-size:15px; }
  .pill { 
      display:inline-flex; align-items:center; gap:8px; 
      padding:6px 12px; border-radius:999px; 
      background:#fff; border:1px solid #bfdbfe; 
      font-weight:600; font-size:13px; color:#1e40af; 
      margin-bottom: 12px;
  }

  .layout { display:grid; grid-template-columns:340px 1fr; gap:24px; align-items:start; }
  @media(max-width: 960px){ .layout { grid-template-columns:1fr; } }

  .card { 
      background:var(--card); 
      border:1px solid var(--border); 
      border-radius:20px; 
      padding:24px; 
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
  }
  .card h3 { margin:0 0 16px; font-size:18px; color: var(--text-main); }
  
  /* Buttons */
  .btn { 
      padding:10px 16px; border-radius:10px; border:none; 
      background: #0f172a; color:#fff; font-weight:600; font-size:14px;
      cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center;
      transition: all .2s;
  }
  .btn:hover { background: #1e293b; transform: translateY(-1px); }
  
  .btn-outline { 
      background: #fff; color: var(--text-main); border: 1px solid var(--border); 
  }
  .btn-outline:hover { border-color: var(--accent); color: var(--accent); }

  /* Inputs */
  .form-control { 
      width:100%; padding:12px 14px; 
      border-radius:10px; border:1px solid var(--border); 
      background:#fff; font-family:inherit; font-size:14px; color: var(--text-main);
      transition: all .2s;
  }
  .form-control:focus { outline:none; border-color:var(--accent); box-shadow: 0 0 0 4px var(--focus-ring); }
  label { display:block; margin-bottom:6px; font-weight:600; font-size:13px; color:#344054; }

  /* Conversation List */
  .conversation-list { display:flex; flex-direction:column; gap:8px; }
  .conversation { 
      padding:12px 14px; border-radius:12px; 
      border:1px solid transparent; 
      display:flex; justify-content:space-between; gap:12px; align-items:center; 
      transition: all .2s; 
  }
  .conversation:hover { background: #f8fafc; }
  .conversation.active { 
      background: var(--accent-light); 
      border-color: #bfdbfe; 
  }
  .conversation-name { font-weight:600; font-size:15px; color: var(--text-main); }
  .conversation.active .conversation-name { color: #1e40af; }
  .conversation-meta { font-size:12px; color: var(--text-muted); }

  /* Chat Area */
  .chat-header { 
      padding-bottom:16px; margin-bottom:16px; border-bottom:1px solid var(--border); 
      display:flex; align-items:center; justify-content:space-between; 
  }
  .chat-window { 
      display:flex; flex-direction:column; gap:16px; 
      max-height:500px; overflow-y:auto; 
      padding-right:8px; margin-bottom:20px;
  }
  
  .message-row { display:flex; gap:12px; width: 100%; }
  .message-row.me { flex-direction: row-reverse; }
  
  .bubble { 
      border-radius:16px; padding:12px 16px; 
      max-width: 80%; line-height:1.5; font-size:15px;
      position: relative;
  }
  .bubble.me { 
      background: var(--accent); color: #fff; 
      border-bottom-right-radius: 2px;
  }
  .bubble.them { 
      background: #f1f5f9; color: var(--text-main); 
      border-bottom-left-radius: 2px;
  }
  
  .mini-avatar { 
      width:38px; height:38px; border-radius:10px; 
      background:#eff6ff; border:1px solid #dbeafe; 
      display:flex; align-items:center; justify-content:center; 
      font-weight:700; color:var(--accent); overflow:hidden; flex-shrink:0; font-size: 14px;
  }
  .mini-avatar img { width:100%; height:100%; object-fit:cover; }
  
  .message-meta { 
      font-size:11px; color: var(--text-muted); margin-top:4px; 
      text-align: right; opacity: 0.8;
  }
  .message-row.them .message-meta { text-align: left; }

  /* Alerts */
  .notice { padding:12px 16px; border-radius:10px; margin-bottom:16px; font-size:14px; display:flex; gap:10px; }
  .notice.success { background: #ecfdf5; border: 1px solid #d1fae5; color: #065f46; }
  .notice.error { background: #fef2f2; border: 1px solid #fee2e2; color: #991b1b; }

  @media(max-width: 720px){
    .bubble { max-width:90%; }
    .hero { padding: 24px; }
  }
</style>

<main class="page">
  <section class="hero">
    <div>
      <div class="pill">💬 Žinutės</div>
      <h1>Privatūs pokalbiai</h1>
      <p>Bendraukite su kitais bendruomenės nariais, klauskite patarimų ir dalinkitės patirtimi saugiai.</p>
    </div>
    <a class="btn btn-outline" href="#new">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px;"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
        Nauja žinutė
    </a>
  </section>

  <div class="layout">
    <div style="display:flex; flex-direction:column; gap:24px;">
      
      <section class="card" id="new">
        <h3>Nauja žinutė</h3>
        <form method="post" style="display:grid; gap:16px;">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="send_new">
          <div>
            <label for="recipient_input">Gavėjas (Vardas arba El. paštas)</label>
            <input class="form-control" type="text" id="recipient_input" name="recipient_input" placeholder="pvz. Vardenis arba pastas@cukrinukas.lt" required>
          </div>
          <div>
            <label for="new_body">Žinutė</label>
            <textarea class="form-control" id="new_body" name="body" style="min-height:100px; resize:vertical;" placeholder="Labas, norėjau paklausti..." required></textarea>
          </div>
          <button class="btn" type="submit">Siųsti žinutę</button>
        </form>
      </section>

      <section class="card">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
          <h3 style="margin:0;">Pokalbiai</h3>
          <span style="font-size:12px; font-weight:600; background:#f1f5f9; padding:2px 8px; border-radius:12px;"><?php echo count($conversations); ?></span>
        </div>
        
        <?php if ($conversations): ?>
          <div class="conversation-list">
            <?php foreach ($conversations as $conv): ?>
              <a class="conversation <?php echo $activePartnerId===(int)$conv['partner_id'] ? 'active' : ''; ?>" href="?user=<?php echo (int)$conv['partner_id']; ?>">
                <div style="display:flex; gap:10px; align-items:center;">
                   <div style="width:8px; height:8px; border-radius:50%; background: <?php echo $activePartnerId===(int)$conv['partner_id'] ? 'var(--accent)' : '#cbd5e1'; ?>;"></div>
                   <div>
                      <div class="conversation-name"><?php echo htmlspecialchars($conv['name'] ?? 'Narys #' . $conv['partner_id']); ?></div>
                      <div class="conversation-meta"><?php echo htmlspecialchars(date('Y-m-d', strtotime($conv['last_time']))); ?></div>
                   </div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?php echo $activePartnerId===(int)$conv['partner_id'] ? 'var(--accent)' : '#94a3b8'; ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="notice" style="background:#f8fafc; color:var(--text-muted); margin:0;">Dar neturite pradėtų pokalbių.</div>
        <?php endif; ?>
      </section>
    </div>

    <section class="card" style="display:flex; flex-direction:column; height: 100%;">
      
      <?php foreach ($messages as $msg): ?>
        <div class="notice success">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            <?php echo $msg; ?>
        </div>
      <?php endforeach; ?>
      
      <?php foreach ($errors as $err): ?>
        <div class="notice error">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <?php echo $err; ?>
        </div>
      <?php endforeach; ?>

      <?php if ($activePartnerId): ?>
        <div class="chat-header">
          <div>
             <span style="font-size:12px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Pokalbis su</span>
             <div style="font-size:18px; font-weight:700; color:var(--text-main); margin-top:2px;">
                 <?php echo htmlspecialchars($activePartnerName ?? ('Narys #' . $activePartnerId)); ?>
             </div>
          </div>
          <div style="font-size:13px; color:var(--text-muted);">ID: <?php echo (int)$activePartnerId; ?></div>
        </div>

        <div class="chat-window">
            <?php if ($threadMessages): ?>
              <?php foreach ($threadMessages as $tm): ?>
                <?php
                  $isMe = (int)$tm['sender_id'] === (int)$user['id'];
                  $avatarInitial = strtoupper(mb_substr($tm['sender_name'] ?? 'V', 0, 1));
                  $avatarImg = !empty($tm['sender_photo']) ? $tm['sender_photo'] : null;
                ?>
                <div class="message-row <?php echo $isMe ? 'me' : 'them'; ?>">
                    
                    <?php if (!$isMe): ?>
                      <div class="mini-avatar" title="<?php echo htmlspecialchars($tm['sender_name']); ?>">
                        <?php if ($avatarImg): ?>
                          <img src="<?php echo htmlspecialchars($avatarImg); ?>" alt="">
                        <?php else: ?>
                          <?php echo htmlspecialchars($avatarInitial); ?>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>

                    <div style="display:flex; flex-direction:column; max-width:80%;">
                        <div class="bubble <?php echo $isMe ? 'me' : 'them'; ?>">
                          <?php
                            $isSystemMessage = $systemUserId && ((int)$tm['sender_id'] === (int)$systemUserId);
                            echo $isSystemMessage ? $tm['body'] : nl2br(htmlspecialchars($tm['body']));
                          ?>
                        </div>
                        <div class="message-meta">
                            <?php echo htmlspecialchars(date('H:i | M d', strtotime($tm['created_at']))); ?>
                        </div>
                    </div>

                    <?php if ($isMe): ?>
                        <div class="mini-avatar" title="Jūs">
                            <?php if ($avatarImg): ?>
                              <img src="<?php echo htmlspecialchars($avatarImg); ?>" alt="">
                            <?php else: ?>
                              <?php echo htmlspecialchars($avatarInitial); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div style="text-align:center; padding:40px; color:var(--text-muted);">
                  <div style="font-size:48px; margin-bottom:10px;">👋</div>
                  <p>Tai jūsų pokalbio pradžia. Pasisveikinkite!</p>
              </div>
            <?php endif; ?>
        </div>

        <form method="post" style="display:flex; gap:12px; align-items:flex-end; border-top:1px solid var(--border); padding-top:20px; margin-top:auto;">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="send_existing">
          <input type="hidden" name="partner_id" value="<?php echo (int)$activePartnerId; ?>">
          
          <div style="flex:1;">
            <textarea class="form-control" name="body" style="min-height:50px; resize:none; border-radius:20px; padding:12px 16px;" placeholder="Rašyti žinutę..." required></textarea>
          </div>
          
          <button class="btn" type="submit" style="border-radius:50%; width:46px; height:46px; padding:0; flex-shrink:0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
          </button>
        </form>

      <?php else: ?>
        <div style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; color:var(--text-muted); min-height:300px;">
            <div style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center; margin-bottom:16px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            </div>
            <h3 style="color:var(--text-main); margin:0 0 8px;">Nėra pasirinkto pokalbio</h3>
            <p style="max-width:300px; margin:0;">Pasirinkite pokalbį iš kairiojo meniu arba pradėkite naują susirašinėjimą.</p>
        </div>
      <?php endif; ?>
    </section>
  </div>
</main>
<?php renderFooter(); ?>
