<?php
// google_auth.php - pagalbinės funkcijos bendravimui su Google API

require_once __DIR__ . '/db.php';

function getGoogleConfig(): array {
    return [
        'client_id'     => requireEnv('GOOGLE_CLIENT_ID'),
        'client_secret' => requireEnv('GOOGLE_CLIENT_SECRET'),
        // redirect_uri šiam naujam metodui nustatomas tiesiogiai JS konfigūracijoje,
        // bet paliekame dėl suderinamumo su .env failu
        'redirect_uri'  => requireEnv('GOOGLE_REDIRECT_URI'),
    ];
}

/**
 * Patikrina Google ID Token (gautą per POST užklausą).
 * Tai saugesnis ir greitesnis būdas, apeinantis serverio URL blokavimus.
 */
function verifyGoogleIdToken(string $idToken): ?array {
    $config = getGoogleConfig();
    
    // Naudojame Google viešąjį endpointą tokeno tikrinimui
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . $idToken;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Gamyboje (Production) būtina true. Lokaliai (XAMPP) kartais reikia false, jei nėra sertifikatų.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }

    $payload = json_decode($response, true);

    // SAUGUMO TIKRINIMAS:
    // Privalome patikrinti, ar tokenas išduotas BŪTENT mūsų programėlei (aud = client_id).
    if (!isset($payload['aud']) || $payload['aud'] !== $config['client_id']) {
        return null;
    }

    // Patikriname galiojimo laiką
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null;
    }

    return $payload;
}

// Pagrindinė logika: Randa esamą vartotoją, susieja arba sukuria naują
function findOrCreateGoogleUser(PDO $pdo, array $payload): array {
    $googleId = $payload['sub']; // 'sub' yra unikalus Google ID šiame formate
    $email = $payload['email'];
    $name = $payload['name'] ?? 'Google Vartotojas';
    $picture = $payload['picture'] ?? null;

    // A. Tikriname pagal google_id
    $stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();

    if ($user) {
        return $user;
    }

    // B. Tikriname pagal el. paštą (jei senas vartotojas jungiasi su Google)
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        // Susiejame
        $update = $pdo->prepare('UPDATE users SET google_id = ? WHERE id = ?');
        $update->execute([$googleId, $existingUser['id']]);
        
        // Atnaujiname nuotrauką tik jei jos nėra
        if (empty($existingUser['profile_photo']) && $picture) {
            $pdo->prepare('UPDATE users SET profile_photo = ? WHERE id = ?')
                ->execute([$picture, $existingUser['id']]);
        }

        // Grąžiname atnaujintą vartotoją
        $stmt->execute([$email]);
        return $stmt->fetch();
    }

    // C. Sukuriame naują
    $randomPass = bin2hex(random_bytes(16));
    $hash = password_hash($randomPass, PASSWORD_DEFAULT);

    $insert = $pdo->prepare('
        INSERT INTO users (name, email, password_hash, google_id, profile_photo, is_admin) 
        VALUES (?, ?, ?, ?, ?, 0)
    ');
    $insert->execute([$name, $email, $hash, $googleId, $picture]);

    $newId = $pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$newId]);
    return $stmt->fetch();
}
?>
