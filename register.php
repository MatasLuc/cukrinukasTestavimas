<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/google_auth.php';

$pdo = getPdo();
ensureUsersTable($pdo);
ensureProductsTable($pdo);
ensureAdminAccount($pdo);
tryAutoLogin($pdo);

// SAUGUS GOOGLE CONFIG GAVIMAS
$googleConfig = [];
try {
    $googleConfig = getGoogleConfig();
} catch (Throwable $e) {
    if (function_exists('logError')) {
        logError('Google Auth Config Error: ' . $e->getMessage());
    }
}

// SAUGUS FACEBOOK APP ID GAVIMAS
$fbAppIdFromEnv = getenv('FACEBOOK_APP_ID');
$facebookAppId = $fbAppIdFromEnv ? $fbAppIdFromEnv : 'JUSU_FACEBOOK_APP_ID_CIA';

$errors = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $errors[] = 'Užpildykite visus laukus.';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Slaptažodis turi būti bent 6 simbolių.';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'Toks el. paštas jau registruotas.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
                $insert->execute([$name, $email, $hash]);
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $pdo->lastInsertId();
                $_SESSION['user_name'] = $name;
                $_SESSION['is_admin'] = 0;
                $content = "<p>Dėkojame, kad prisiregistravote. Dabar galite prisijungti, išsisaugoti mėgstamus receptus ir greičiau apsipirkti.</p>";
                
                $html = getEmailTemplate('Sveiki atvykę į bendruomenę! 👋', $content, 'https://' . $_SERVER['HTTP_HOST'] . '/login.php', 'Prisijungti');
                
                try {
                    sendEmail($email, 'Sveiki atvykę į Cukrinuką!', $html);
                } catch (Throwable $e) {
                    // Ignoruojame laiško klaidą
                }

                header('Location: /');
                exit;
            }
        } catch (Throwable $e) {
            if (function_exists('logError')) {
                logError('Registration failed', $e);
            }
            $errors[] = 'Įvyko klaida registruojantis. Bandykite vėliau.';
        }
    }
}
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Registracija | Cukrinukas.lt</title>
  <?php echo headerStyles(); ?>
  <style>
    :root {
      --bg: #f7f7fb;
      --surface: #ffffff;
      --border: #e4e7ec;
      --input-bg: #ffffff;
      --text-main: #0f172a;
      --text-muted: #475467;
      --accent: #2563eb;
      --focus-ring: rgba(37, 99, 235, 0.2);
    }
    body { background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    
    .auth-wrapper {
        min-height: calc(100vh - 160px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 20px;
    }
    
    .auth-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        max-width: 1000px;
        width: 100%;
        background: var(--surface);
        border-radius: 24px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        border: 1px solid var(--border);
        overflow: hidden;
    }

    /* Left Side - Hero/Info */
    .auth-info {
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        padding: 48px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        border-right: 1px solid var(--border);
    }
    .auth-info h1 { margin: 0 0 16px; font-size: 32px; color: #1e3a8a; letter-spacing: -0.5px; }
    .auth-info p { margin: 0 0 32px; color: #1e40af; line-height: 1.6; font-size: 16px; }
    
    .feature-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 16px; }
    .feature-item { display: flex; align-items: center; gap: 12px; color: #1e3a8a; font-weight: 500; }
    .feature-icon { 
        width: 24px; height: 24px; 
        background: #2563eb; color: #fff; 
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-size: 14px; flex-shrink: 0;
    }

    /* Right Side - Form */
    .auth-form-box { padding: 48px; }
    .auth-header { margin-bottom: 32px; }
    .auth-header h2 { margin: 0 0 8px; font-size: 24px; color: var(--text-main); }
    .auth-header p { margin: 0; color: var(--text-muted); font-size: 14px; }

    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 14px; color: #344054; }
    .form-input { 
        width: 100%; padding: 12px 14px; 
        border: 1px solid var(--border); border-radius: 10px; 
        background: var(--input-bg); color: var(--text-main);
        font-size: 15px; transition: all .2s;
    }
    .form-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px var(--focus-ring); }
    
    .btn-submit {
        width: 100%; padding: 12px;
        border-radius: 10px; border: none;
        background: #0f172a; color: #fff;
        font-weight: 600; font-size: 15px;
        cursor: pointer; transition: all .2s;
        display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-submit:hover { background: #1e293b; transform: translateY(-1px); }

    .auth-links { margin-top: 24px; display: flex; justify-content: center; font-size: 14px; color: var(--text-muted); }
    .auth-links a { color: var(--accent); font-weight: 600; text-decoration: none; margin-left: 6px; transition: color .2s; }
    .auth-links a:hover { color: #1d4ed8; }

    .auth-divider {
        display: flex; align-items: center; gap: 16px;
        color: var(--text-muted); font-size: 13px; font-weight: 500;
        margin: 24px 0;
    }
    .auth-divider::before, .auth-divider::after {
        content: ''; flex: 1; height: 1px; background: var(--border);
    }

    /* Social Buttons */
    .social-buttons {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-bottom: 24px;
    }
    
    #google-btn-container {
        width: 100%;
        min-height: 44px;
    }

    .btn-facebook {
        width: 100%;
        padding: 10px 12px;
        border-radius: 4px;
        border: 1px solid #1877f2;
        background: #1877f2;
        color: #fff;
        font-weight: 500;
        font-size: 14px;
        font-family: 'Roboto', sans-serif;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: background .2s;
        height: 40px;
    }
    .btn-facebook:hover {
        background: #166fe5;
        border-color: #166fe5;
    }
    .btn-facebook svg {
        fill: white;
        width: 20px;
        height: 20px;
    }

    /* Messages */
    .notice { padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; display: flex; gap: 10px; line-height: 1.4; }
    .notice.error { background: #fef2f2; border: 1px solid #fee2e2; color: #991b1b; }
    .notice.success { background: #ecfdf5; border: 1px solid #d1fae5; color: #065f46; }
    
    @media (max-width: 800px) {
        .auth-wrapper {
            padding: 20px 10px; 
            align-items: flex-start;
        }
        .auth-container { 
            grid-template-columns: 1fr; 
            max-width: 500px;
        }
        .auth-info { 
            display: none; 
        }
        .auth-form-box { 
            padding: 32px 20px; 
        }
    }
  </style>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body>
  
  <script>
  window.fbAsyncInit = function() {
    FB.init({
      appId      : '<?= htmlspecialchars($facebookAppId) ?>',
      cookie     : true,
      xfbml      : true,
      version    : 'v18.0'
    });
  };

  (function(d, s, id){
     var js, fjs = d.getElementsByTagName(s)[0];
     if (d.getElementById(id)) {return;}
     js = d.createElement(s); js.id = id;
     js.src = "https://connect.facebook.net/en_US/sdk.js";
     fjs.parentNode.insertBefore(js, fjs);
   }(document, 'script', 'facebook-jssdk'));

   function loginWithFacebook() {
       // Registracijos metu automatiškai nustatome remember: true
       FB.login(function(response) {
           if (response.authResponse) {
               fetch('/facebook_callback.php', {
                   method: 'POST',
                   headers: { 'Content-Type': 'application/json' },
                   body: JSON.stringify({ 
                       accessToken: response.authResponse.accessToken,
                       remember: true 
                   })
               })
               .then(res => res.json())
               .then(data => {
                   if (data.success) {
                       window.location.href = '/';
                   } else {
                       alert('Nepavyko prisijungti su Facebook: ' + (data.error || 'Unknown error'));
                   }
               })
               .catch(err => {
                   console.error(err);
                   alert('Sistemos klaida jungiantis su Facebook.');
               });
           } else {
               console.log('User cancelled login or did not fully authorize.');
           }
       }, {scope: 'public_profile,email'});
   }
  </script>

  <?php renderHeader($pdo, 'register'); ?>

  <div class="auth-wrapper">
    <div class="auth-container">
        <div class="auth-info">
            <h1>Kurkite paskyrą</h1>
            <p>Tapkite bendruomenės dalimi ir mėgaukitės patogesniu apsipirkimu, receptų išsaugojimu bei specialiais pasiūlymais.</p>
            
            <ul class="feature-list">
                <li class="feature-item">
                    <div class="feature-icon">✨</div>
                    <span>Nemokama narystė</span>
                </li>
                <li class="feature-item">
                    <div class="feature-icon">🚚</div>
                    <span>Greitesnis atsiskaitymas</span>
                </li>
                <li class="feature-item">
                    <div class="feature-icon">🎁</div>
                    <span>Kaupiamoji nuolaidų sistema</span>
                </li>
            </ul>
        </div>

        <div class="auth-form-box">
            <div class="auth-header">
                <h2>Registracija</h2>
                <p>Pasirinkite registracijos būdą</p>
            </div>

            <div class="social-buttons">
                <div id="google-btn-container"></div>
                
                <button type="button" class="btn-facebook" onclick="loginWithFacebook()">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    Registruotis su Facebook
                </button>
            </div>

            <div class="auth-divider">arba su el. paštu</div>

            <?php if ($errors): ?>
                <div class="notice error">
                    <svg style="width:20px;height:20px;flex-shrink:0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <div>
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="notice success">
                    <svg style="width:20px;height:20px;flex-shrink:0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                    <div><?php echo htmlspecialchars($message); ?></div>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php echo csrfField(); ?>
                
                <div class="form-group">
                    <label for="name">Vardas</label>
                    <input class="form-input" id="name" name="name" type="text" placeholder="Jūsų vardas" required>
                </div>

                <div class="form-group">
                    <label for="email">El. paštas</label>
                    <input class="form-input" id="email" name="email" type="email" placeholder="vardas@pastas.lt" required autocomplete="email">
                </div>

                <div class="form-group">
                    <label for="password">Slaptažodis</label>
                    <input class="form-input" id="password" name="password" type="password" placeholder="Mažiausiai 6 simboliai" required autocomplete="new-password" minlength="6">
                </div>

                <button type="submit" class="btn-submit">Registruotis</button>
            </form>

            <div class="auth-links">
                Jau turite paskyrą? <a href="/login.php">Prisijunkite</a>
            </div>
        </div>
    </div>
  </div>

  <?php renderFooter($pdo); ?>

  <script>
    window.onload = function () {
        // Google Init (tik jei turime client_id)
        <?php if (!empty($googleConfig['client_id'])): ?>
        google.accounts.id.initialize({
            client_id: "<?= htmlspecialchars($googleConfig['client_id'] ?? '') ?>",
            ux_mode: "redirect",
            login_uri: "https://<?= $_SERVER['HTTP_HOST'] ?>/google_callback.php"
        });

        const container = document.getElementById('google-btn-container');
        if (container) {
            const width = container.offsetWidth;
            google.accounts.id.renderButton(
                container,
                { 
                    theme: "outline", 
                    size: "large", 
                    width: width, 
                    text: "signup_with",
                    logo_alignment: "left"
                }
            );
        }
        <?php else: ?>
        console.warn('Google login disabled: missing configuration.');
        <?php endif; ?>
    };
  </script>
</body>
</html>
