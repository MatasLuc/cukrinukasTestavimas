<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
// 1. Įtraukiame Google Auth funkcijas
require_once __DIR__ . '/google_auth.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$pdo = getPdo();
ensureUsersTable($pdo);
ensureAdminAccount($pdo);
tryAutoLogin($pdo);

$userId = (int) $_SESSION['user_id'];

// --- 2. LOGIKA: Paskyrų atsiejimas (Google ir Facebook) ---

// A. Google atsiejimas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlink_google'])) {
    validateCsrfToken();
    $stmt = $pdo->prepare('UPDATE users SET google_id = NULL WHERE id = ?');
    $stmt->execute([$userId]);
    header('Location: /account.php?success=google_unlinked');
    exit;
}

// B. Facebook atsiejimas (NAUJA)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlink_facebook'])) {
    validateCsrfToken();
    $stmt = $pdo->prepare('UPDATE users SET facebook_id = NULL WHERE id = ?');
    $stmt->execute([$userId]);
    header('Location: /account.php?success=facebook_unlinked');
    exit;
}

// Paimame vartotojo duomenis (PRIDĖTI STRIPE LAUKAI)
$stmt = $pdo->prepare('SELECT id, name, email, profile_photo, birthdate, gender, city, country, google_id, facebook_id, stripe_account_id, stripe_onboarding_completed FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

$errors = [];
$success = '';

// Patikriname pranešimus (papildyta Facebook ir Stripe pranešimais)
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'google_linked') {
        $success = 'Google paskyra sėkmingai susieta!';
    } elseif ($_GET['success'] === 'google_unlinked') {
        $success = 'Google paskyra sėkmingai atsieta.';
    } elseif ($_GET['success'] === 'facebook_linked') {
        $success = 'Facebook paskyra sėkmingai susieta!';
    } elseif ($_GET['success'] === 'facebook_unlinked') {
        $success = 'Facebook paskyra sėkmingai atsieta.';
    } elseif ($_GET['success'] === 'stripe_connected') {
        $success = 'Sveikiname! Jūs tapote patvirtintu pardavėju. Dabar galite gauti išmokas.';
    }
}
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'google_taken') {
        $errors[] = 'Ši Google paskyra jau susieta su kitu vartotoju.';
    } elseif ($_GET['error'] === 'facebook_taken') {
        $errors[] = 'Ši Facebook paskyra jau susieta su kitu vartotoju.';
    } elseif ($_GET['error'] === 'stripe_incomplete') {
        $errors[] = 'Registracija Stripe sistemoje nebuvo pilnai baigta.';
    } elseif ($_GET['error'] === 'stripe_not_found') {
        $errors[] = 'Įvyko klaida nustatant sąskaitą. Susisiekite su administracija.';
    }
}

// 3. LOGIKA: Profilio atnaujinimas (Palikta originali logika)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['unlink_google']) && !isset($_POST['unlink_facebook'])) {
    validateCsrfToken();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $birthdate = $_POST['birthdate'] !== '' ? $_POST['birthdate'] : null;
    $gender = trim($_POST['gender'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $profilePhoto = $user['profile_photo'] ?? null;

    if (!empty($_FILES['profile_photo']['name'])) {
        $allowedMimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        $uploaded = saveUploadedFile($_FILES['profile_photo'], $allowedMimeMap, 'profile_');
        if ($uploaded) {
            $profilePhoto = $uploaded;
        }
    }

    if ($name === '' || $email === '') {
        $errors[] = 'Įveskite vardą ir el. paštą.';
    }

    if (!$errors) {
        $pdo->prepare('UPDATE users SET name = ?, email = ?, birthdate = ?, gender = ?, city = ?, country = ?, profile_photo = ? WHERE id = ?')
            ->execute([$name, $email, $birthdate, $gender ?: null, $city ?: null, $country ?: null, $profilePhoto, $userId]);
        $_SESSION['user_name'] = $name;
        
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
        }
        
        $success = 'Paskyra atnaujinta sėkmingai';
        
        // Atnaujiname kintamąjį
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    }
}

// 4. Gauname Google ir Facebook konfigūraciją
$googleConfig = getGoogleConfig();
$fbAppId = getenv('FACEBOOK_APP_ID') ?: 'JUSU_FACEBOOK_APP_ID_CIA'; // Pakeisti jei nėra env
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Paskyra | Cukrinukas.lt</title>
  <?php echo headerStyles(); ?>
  
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  
  <style>
    :root {
      --bg: #f7f7fb;
      --card: #ffffff;
      --border: #e4e7ec;
      --text-main: #0f172a;
      --text-muted: #475467;
      --accent: #2563eb;
      --accent-hover: #1d4ed8;
      --focus-ring: rgba(37, 99, 235, 0.2);
    }
    * { box-sizing:border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family:'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; }
    
    .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; display:grid; gap:28px; }

    /* Hero Section */
    .hero { 
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border:1px solid #dbeafe; 
        border-radius:24px; 
        padding:32px; 
        display:flex; 
        align-items:center; 
        justify-content:space-between; 
        gap:24px; 
        flex-wrap:wrap; 
    }
    .hero h1 { margin:0 0 8px; font-size:28px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; line-height:1.5; max-width:520px; font-size:15px; }
    
    .pill { 
        display:inline-flex; align-items:center; gap:8px; 
        padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #bfdbfe; 
        font-weight:600; font-size:13px; color:#1e40af; 
        margin-bottom: 12px;
    }
    
    .stat-card { 
        background:#fff; border:1px solid rgba(255,255,255,0.6); 
        padding:16px 20px; border-radius:16px; 
        min-width:160px; text-align:right;
        box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.1);
    }
    .stat-card strong { display:block; font-size:20px; color:#1e3a8a; margin-bottom: 4px; }
    .stat-card span { color: #64748b; font-size:13px; font-weight: 500; }

    /* Layout */
    .layout { display:grid; grid-template-columns: 1fr 320px; gap:24px; align-items:start; }
    @media(max-width: 850px){ .layout { grid-template-columns:1fr; } }

    /* Cards */
    .card { 
        background:var(--card); 
        border:1px solid var(--border); 
        border-radius:20px; 
        padding:32px; 
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        margin-bottom: 24px;
    }
    .card h2 { margin:0 0 8px; font-size:20px; color: var(--text-main); }
    .card-desc { margin:0 0 24px; color: var(--text-muted); font-size:14px; line-height: 1.5; }

    /* Form Elements */
    label { display:block; margin:0 0 6px; font-weight:600; font-size:14px; color:#344054; }
    
    .form-control { 
        width:100%; padding:12px 14px; 
        border-radius:10px; border:1px solid var(--border); 
        background:#fff; font-family:inherit; font-size:15px; color: var(--text-main);
        transition: all .2s;
    }
    .form-control:focus { outline:none; border-color:var(--accent); box-shadow: 0 0 0 4px var(--focus-ring); }
    
    .form-grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    .form-group { margin-bottom: 20px; }
    
    button.btn-primary { 
        padding:12px 16px; border-radius:10px; border:none; 
        background: #0f172a; color:#fff; font-weight:600; font-size:15px;
        cursor:pointer; width:100%; 
        transition: all .2s;
        display: flex; align-items: center; justify-content: center;
    }
    button.btn-primary:hover { background: #1e293b; transform: translateY(-1px); }

    /* Linked Accounts Styles */
    .account-row {
        display: flex; align-items: center; justify-content: space-between;
        padding: 16px; border: 1px solid var(--border); border-radius: 12px;
        background: #f8fafc;
        flex-wrap: wrap; 
        gap: 12px;
        margin-bottom: 12px;
    }
    .account-info { display: flex; align-items: center; gap: 12px; font-weight: 500; color: var(--text-main); }
    .badge-linked { background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid #bbf7d0; }
    .badge-unlinked { background: #f1f5f9; color: #64748b; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid #e2e8f0; }
    
    .btn-unlink { 
        background: #fff; border: 1px solid #fee2e2; color: #991b1b; 
        padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all .2s;
    }
    .btn-unlink:hover { background: #fef2f2; border-color: #fecaca; }

    /* Facebook button */
    .btn-link-fb {
        background: #1877f2; border: 1px solid #1877f2; color: #fff;
        padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all .2s;
    }
    .btn-link-fb:hover { background: #166fe5; }

    /* Messages */
    .notice { padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; gap:10px; align-items:flex-start; line-height:1.4; }
    .error { background: #fef2f2; border: 1px solid #fee2e2; color: #991b1b; }
    .success { background: #ecfdf5; border: 1px solid #d1fae5; color: #065f46; }

    /* Profile Photo */
    .profile-row { display:flex; align-items:center; gap:20px; margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid var(--border); }
    .avatar { 
        width:80px; height:80px; border-radius:20px; 
        background:#eff6ff; border:1px solid #dbeafe; 
        display:flex; align-items:center; justify-content:center; 
        font-weight:700; font-size: 24px; color:var(--accent); 
        overflow:hidden; flex-shrink: 0;
    }
    .avatar img { width:100%; height:100%; object-fit:cover; }
    .file-input-wrapper { flex: 1; }
    input[type="file"] { font-size: 14px; color: var(--text-muted); }
    input[type="file"]::file-selector-button {
        margin-right: 12px; padding: 8px 12px; border-radius: 8px;
        background: #fff; border: 1px solid var(--border);
        cursor: pointer; font-weight: 500; transition: all .2s;
    }
    input[type="file"]::file-selector-button:hover { background: #f8fafc; border-color: #cbd5e1; }

    /* Recommendations List */
    .rec-list { list-style:none; padding:0; margin:0; }
    .rec-list li { 
        position: relative; padding-left: 24px; margin-bottom: 12px; 
        font-size: 14px; color: var(--text-muted); line-height: 1.5; 
    }
    .rec-list li::before {
        content: "✓"; position: absolute; left: 0; top: 2px;
        color: var(--accent); font-weight: bold;
    }

    /* Stripe Specific Styles */
    .stripe-btn {
        display: inline-flex; align-items: center; background-color: #635bff; color: white;
        border: none; padding: 10px 16px; border-radius: 8px; text-decoration: none;
        font-weight: 600; font-size: 13px; transition: background-color 0.2s;
    }
    .stripe-btn:hover { background-color: #4b45c2; color: white; }
    .dashboard-link {
        color: #635bff; text-decoration: none; font-weight: 500; font-size: 13px;
        margin-left: 10px; display: inline-block;
    }
    .dashboard-link:hover { text-decoration: underline; }
    .stripe-icon { margin-right: 8px; font-weight: bold; font-family: sans-serif; }
    
    @media(max-width: 600px) {
        .form-grid { grid-template-columns: 1fr; gap:0; }
        .hero { padding: 24px; }
        .card { padding: 24px; }
    }
  </style>
  
  <script>
    // Facebook SDK Inicializacija
    window.fbAsyncInit = function() {
        FB.init({
            appId      : '<?= htmlspecialchars($fbAppId) ?>',
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

    // Facebook susiejimo funkcija
    function linkFacebook() {
        FB.login(function(response) {
            if (response.authResponse) {
                // Siunčiame tokeną į backend, jis atpažins, kad vartotojas prisijungęs ir susies
                fetch('/facebook_callback.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ accessToken: response.authResponse.accessToken })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = '/account.php?success=facebook_linked';
                    } else {
                        alert('Klaida: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Sistemos klaida.');
                });
            }
        }, {scope: 'public_profile,email'});
    }
  </script>
</head>
<body>
  <?php renderHeader($pdo, 'account'); ?>
  
  <div class="page">
    <section class="hero">
      <div>
        <div class="pill">👤 Paskyros nustatymai</div>
        <h1>Jūsų profilis</h1>
        <p>Atnaujinkite savo asmeninę informaciją, valdykite susietas paskyras ir keiskite prisijungimo duomenis.</p>
      </div>
      <div class="stat-card">
        <strong><?php echo htmlspecialchars($user['name'] ?? 'Vartotojas'); ?></strong>
        <span>Prisijungęs vartotojas</span>
      </div>
    </section>

    <div class="layout">
      <div>
          <div class="card">
            <h2>Profilio duomenys</h2>
            <p class="card-desc">Redaguokite informaciją, kurią mato kiti bendruomenės nariai bei kuri naudojama užsakymams.</p>
            
            <?php foreach ($errors as $err): ?>
                <div class="notice error">
                    <svg style="width:20px;height:20px;flex-shrink:0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span><?php echo htmlspecialchars($err); ?></span>
                </div>
            <?php endforeach; ?>
            
            <?php if ($success): ?>
                <div class="notice success">
                    <svg style="width:20px;height:20px;flex-shrink:0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
              <?php echo csrfField(); ?>
              
              <div class="profile-row">
                <div class="avatar">
                  <?php if (!empty($user['profile_photo'])): ?>
                    <img src="<?php echo htmlspecialchars($user['profile_photo']); ?>" alt="Profilis">
                  <?php else: ?>
                    <?php echo strtoupper(mb_substr($user['name'] ?? 'V', 0, 1)); ?>
                  <?php endif; ?>
                </div>
                <div class="file-input-wrapper">
                  <label for="profile_photo">Keisti nuotrauką</label>
                  <input id="profile_photo" name="profile_photo" type="file" accept="image/png, image/jpeg, image/webp">
                  <div style="font-size:12px; color:var(--text-muted); margin-top:4px;">Rekomenduojama: PNG, JPG iki 5MB.</div>
                </div>
              </div>

              <div class="form-group">
                  <label for="name">Vardas</label>
                  <input class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
              </div>

              <div class="form-group">
                  <label for="email">El. paštas</label>
                  <input class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" type="email" required>
              </div>

              <div class="form-grid">
                <div class="form-group">
                  <label for="birthdate">Gimimo data</label>
                  <input class="form-control" id="birthdate" name="birthdate" type="date" value="<?php echo htmlspecialchars($user['birthdate'] ?? ''); ?>">
                </div>
                <div class="form-group">
                  <label for="gender">Lytis</label>
                  <select class="form-control" id="gender" name="gender">
                    <option value="">Nepasirinkta</option>
                    <?php foreach (['moteris' => 'Moteris','vyras' => 'Vyras','kita' => 'Kita'] as $val => $label): ?>
                      <option value="<?php echo $val; ?>" <?php echo ($user['gender'] ?? '') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="form-grid">
                <div class="form-group">
                  <label for="city">Miestas</label>
                  <input class="form-control" id="city" name="city" value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>" placeholder="Pvz. Vilnius">
                </div>
                <div class="form-group">
                  <label for="country">Šalis</label>
                  <input class="form-control" id="country" name="country" value="<?php echo htmlspecialchars($user['country'] ?? ''); ?>" placeholder="Pvz. Lietuva">
                </div>
              </div>

              <div class="form-group" style="margin-top:10px; padding-top:20px; border-top:1px solid var(--border);">
                <label for="password">Keisti slaptažodį</label>
                <input class="form-control" id="password" name="password" type="password" placeholder="Naujas slaptažodis (palikite tuščią, jei nekeičiate)">
              </div>

              <button type="submit" class="btn-primary">Išsaugoti pakeitimus</button>
            </form>
          </div>
          
          <div id="g_id_onload"
               data-client_id="<?= htmlspecialchars($googleConfig['client_id']) ?>"
               data-context="signin"
               data-ux_mode="redirect"
               data-login_uri="https://<?= $_SERVER['HTTP_HOST'] ?>/google_callback.php"
               data-auto_prompt="false">
          </div>

          <div class="card">
            <h2>Susietos paskyros</h2>
            <p class="card-desc">Valdykite greito prisijungimo būdus.</p>
            
            <div class="account-row">
                <div class="account-info">
                    <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.04-3.71 1.04-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/><path d="M1 1h22v22H1z" fill="none"/></svg>
                    <span>Google</span>
                    <?php if (!empty($user['google_id'])): ?>
                        <span class="badge-linked">Susieta</span>
                    <?php else: ?>
                        <span class="badge-unlinked">Nesusieta</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($user['google_id'])): ?>
                    <form method="post" style="margin:0;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="unlink_google" value="1">
                        <button type="submit" class="btn-unlink" onclick="return confirm('Ar tikrai norite atsieti Google paskyrą?')">Atsieti</button>
                    </form>
                <?php else: ?>
                    <div class="g_id_signin"
                         data-type="standard"
                         data-shape="rectangular"
                         data-theme="outline"
                         data-text="continue_with"
                         data-size="medium"
                         data-logo_alignment="left">
                    </div>
                <?php endif; ?>
            </div>
          </div>

          <div class="card">
            <h2>Pardavėjo statusas</h2>
            <p class="card-desc">Norėdami parduoti prekes bendruomenės turgelyje, turite susieti savo sąskaitą su Stripe.</p>
            
            <div class="account-row">
                <div class="account-info">
                    <span style="font-size: 20px; color: #635bff; font-weight: bold;">S</span>
                    <span>Stripe Express</span>
                    <?php if (!empty($user['stripe_onboarding_completed'])): ?>
                        <span class="badge-linked">Patvirtinta</span>
                    <?php else: ?>
                        <span class="badge-unlinked">Nepradėta</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($user['stripe_onboarding_completed'])): ?>
                    <a href="stripe_connect.php" class="dashboard-link">Stripe Valdymas &rarr;</a>
                <?php else: ?>
                    <a href="stripe_connect.php" class="stripe-btn">
                       <span class="stripe-icon">S</span> <?php echo !empty($user['stripe_account_id']) ? 'Tęsti registraciją' : 'Tapti pardavėju'; ?>
                    </a>
                <?php endif; ?>
            </div>
          </div>
          </div>

      <div class="card" style="background: #f8fafc; border: 1px solid #e2e8f0; height: fit-content;">
        <h2>Saugumo patarimai</h2>
        <p class="card-desc">Keletas patarimų, kaip apsaugoti savo paskyrą.</p>
        <ul class="rec-list">
          <li>Naudokite unikalų slaptažodį, sudarytą iš raidžių ir skaičių.</li>
          <li>Susiekite paskyrą su Google ar Facebook patogesniam prisijungimui.</li>
          <li>Periodiškai atnaujinkite savo el. paštą, kad gautumėte pranešimus apie užsakymus.</li>
        </ul>
        <div style="margin-top:24px; padding-top:20px; border-top:1px solid #e2e8f0;">
             <a href="/orders.php" style="display:block; font-weight:600; font-size:14px; margin-bottom:12px; color:var(--text-main);">📦 Mano užsakymai →</a>
             <a href="/saved.php" style="display:block; font-weight:600; font-size:14px; color:var(--text-main);">❤️ Išsaugoti produktai →</a>
        </div>
      </div>
    </div>
  </div>

  <?php renderFooter($pdo); ?>
</body>
</html>
