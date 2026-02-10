<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/google_auth.php';

// Klaidų rodymas (produkcijoje išjungti)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pdo = getPdo();
ensureUsersTable($pdo);

// 1. Patikriname ar gavome duomenis iš Google
if (!isset($_POST['credential']) || !isset($_POST['g_csrf_token'])) {
    // Jei bandyta atidaryti tiesiogiai be Google POST duomenų
    header('Location: /login.php?error=google_no_creds');
    exit;
}

// 2. CSRF Saugumo patikrinimas
$cookieToken = $_COOKIE['g_csrf_token'] ?? '';
$postToken   = $_POST['g_csrf_token'];

// Pastaba: Jei testuojate be HTTPS, naršyklė gali neatsiųsti slapuko.
if (empty($cookieToken) || $cookieToken !== $postToken) {
    // Jei labai reikia testuoti localhost be HTTPS, laikinai užkomentuokite šią eilutę,
    // bet produkcijoje tai BŪTINA saugumui.
    die('Klaida: Saugumo (CSRF) patikrinimas nepavyko. Bandykite dar kartą arba naudokite HTTPS.');
}

// 3. Verifikuojame ID tokeną BE Google bibliotekos (naudojant REST API)
$idToken = $_POST['credential'];
$verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

// Naudojame CURL užklausai į Google
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $verifyUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    die('Klaida: Nepavyko susisiekti su Google patvirtinimui.');
}

$payload = json_decode($response, true);

if (!$payload || isset($payload['error_description'])) {
    die('Klaida: Google tokenas negaliojantis. ' . ($payload['error_description'] ?? ''));
}

// 4. Patikriname, ar tokenas skirtas MŪSŲ svetainei (svarbu saugumui)
$config = getGoogleConfig();
if (isset($config['client_id']) && $payload['aud'] !== $config['client_id']) {
    die('Klaida: Tokenas skirtas kitam klientui (neteisingas Client ID).');
}

try {
    // Gauname vartotojo duomenis iš patvirtinto atsakymo
    $googleId = $payload['sub'];
    $email = $payload['email'];
    $name = $payload['name'];
    $picture = $payload['picture'] ?? '';
    $emailVerified = $payload['email_verified'] ?? false;

    if (!$emailVerified) {
         die('Klaida: Jūsų Google el. paštas nepatvirtintas.');
    }

    // 5. Ieškome vartotojo duomenų bazėje
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // Vartotojas rastas -> Prijungiame
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['is_admin'] = (int)$user['is_admin'];
    } else {
        // Vartotojas nerastas -> Sukuriame naują
        // Sugeneruojame atsitiktinį slaptažodį
        $randomPass = bin2hex(random_bytes(16));
        $hash = password_hash($randomPass, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, created_at, is_admin) VALUES (?, ?, ?, NOW(), 0)');
        
        $stmt->execute([$name, $email, $hash]);
        $newUserId = $pdo->lastInsertId();

        $_SESSION['user_id'] = $newUserId;
        $_SESSION['user_name'] = $name;
        $_SESSION['is_admin'] = 0;
    }

    // 6. Jei vartotojas pasirinko "Prisiminti mane" (perduota iš login.php per slapuką)
    if (isset($_COOKIE['remember_selection']) && $_COOKIE['remember_selection'] === '1') {
        if (function_exists('setRememberMe')) {
            setRememberMe($pdo, (int)$_SESSION['user_id']);
        }
        // Išvalome laikiną pasirinkimo slapuką
        setcookie('remember_selection', '', time() - 3600, '/');
    }

    // 7. Nukreipiame į pagrindinį puslapį
    header('Location: /');
    exit;

} catch (Exception $e) {
    die('Sistemos klaida: ' . htmlspecialchars($e->getMessage()));
}
?>
