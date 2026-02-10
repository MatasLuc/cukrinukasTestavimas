<?php
ob_start();
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/facebook_auth.php';

ob_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$accessToken = $input['accessToken'] ?? '';
$remember = !empty($input['remember']);

if (!$accessToken) {
    echo json_encode(['success' => false, 'error' => 'No access token provided']);
    exit;
}

try {
    $pdo = getPdo();
    ensureUsersTable($pdo);

    // 1. Tikriname tokeną
    $fbData = verifyFacebookToken($accessToken);
    if (!$fbData) {
        throw new Exception('Invalid Facebook Token');
    }

    // 2. LOGIKA: SUSIEJIMAS AR PRISIJUNGIMAS?
    
    // A. Jei vartotojas jau prisijungęs -> SUSIEJAME
    if (isset($_SESSION['user_id'])) {
        $currentUserId = (int)$_SESSION['user_id'];
        $fbId = $fbData['id'];

        // Patikriname, ar šis Facebook ID jau nėra naudojamas KITO vartotojo
        $stmt = $pdo->prepare("SELECT id FROM users WHERE facebook_id = ? AND id != ?");
        $stmt->execute([$fbId, $currentUserId]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Ši Facebook paskyra jau susieta su kitu vartotoju.']);
            exit;
        }

        // Susiejame
        $stmt = $pdo->prepare("UPDATE users SET facebook_id = ? WHERE id = ?");
        $stmt->execute([$fbId, $currentUserId]);

        // Jei vartotojas neturi profilio nuotraukos, galime paimti iš FB
        $picture = $fbData['picture']['data']['url'] ?? null;
        if ($picture) {
            $checkImg = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
            $checkImg->execute([$currentUserId]);
            if (empty($checkImg->fetchColumn())) {
                $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?")->execute([$picture, $currentUserId]);
            }
        }

        echo json_encode(['success' => true, 'action' => 'linked']);
        exit;
    }

    // B. Jei vartotojas neprisijungęs -> PRISIJUNGIAME / REGISTRUOJAME (Standartinė eiga)
    $dbUser = findOrCreateFacebookUser($pdo, $fbData);

    session_regenerate_id(true);
    $_SESSION['user_id'] = $dbUser['id'];
    $_SESSION['user_name'] = $dbUser['name'];
    $_SESSION['is_admin'] = (int)$dbUser['is_admin'];

    if ($remember && function_exists('setRememberMe')) {
        setRememberMe($pdo, (int)$dbUser['id']);
    }

    echo json_encode(['success' => true, 'action' => 'login']);

} catch (Exception $e) {
    if (function_exists('logError')) {
        logError('Facebook Auth Error: ' . $e->getMessage());
    }
    echo json_encode(['success' => false, 'error' => 'Authentication failed: ' . $e->getMessage()]);
}
