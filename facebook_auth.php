<?php
// facebook_auth.php

require_once __DIR__ . '/db.php';

function getFacebookConfig(): array {
    return [
        'app_id'     => requireEnv('FACEBOOK_APP_ID'),
        'app_secret' => requireEnv('FACEBOOK_APP_SECRET'),
        'version'    => 'v18.0',
    ];
}

/**
 * Patikrina gautą Facebook Access Token ir grąžina vartotojo duomenis.
 */
function verifyFacebookToken(string $accessToken): ?array {
    $url = "https://graph.facebook.com/me?fields=id,name,email,picture.type(large)&access_token=" . $accessToken;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }

    $payload = json_decode($response, true);

    if (empty($payload['id'])) {
        return null;
    }

    return $payload;
}

/**
 * Suranda arba sukuria vartotoją pagal Facebook duomenis
 */
function findOrCreateFacebookUser(PDO $pdo, array $fbData): array {
    $fbId = $fbData['id'];
    $email = $fbData['email'] ?? null;
    $name = $fbData['name'] ?? 'Facebook Vartotojas';
    
    // Paimame nuotraukos URL saugiau
    $picture = $fbData['picture']['data']['url'] ?? null;

    // A. Tikriname pagal facebook_id
    $stmt = $pdo->prepare('SELECT * FROM users WHERE facebook_id = ? LIMIT 1');
    $stmt->execute([$fbId]);
    $user = $stmt->fetch();

    if ($user) {
        return $user;
    }

    // B. Tikriname pagal el. paštą (jei Facebook jį davė)
    if ($email) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            // Susiejame egzistuojantį vartotoją su Facebook
            $update = $pdo->prepare('UPDATE users SET facebook_id = ? WHERE id = ?');
            $update->execute([$fbId, $existingUser['id']]);
            
            // Atnaujiname nuotrauką tik jei vartotojas jos neturi
            if (empty($existingUser['profile_photo']) && $picture) {
                $pdo->prepare('UPDATE users SET profile_photo = ? WHERE id = ?')
                    ->execute([$picture, $existingUser['id']]);
            }

            // Grąžiname atnaujintą vartotoją
            $stmt->execute([$email]);
            return $stmt->fetch();
        }
    }

    // C. Sukuriame naują vartotoją
    if (!$email) {
        // Jei nėra el. pašto, sukuriame unikalų placeholder
        $email = $fbId . '@facebook.user.local';
    }

    $randomPass = bin2hex(random_bytes(16));
    $hash = password_hash($randomPass, PASSWORD_DEFAULT);

    $insert = $pdo->prepare('
        INSERT INTO users (name, email, password_hash, facebook_id, profile_photo, is_admin) 
        VALUES (?, ?, ?, ?, ?, 0)
    ');
    $insert->execute([$name, $email, $hash, $fbId, $picture]);

    $newId = $pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$newId]);
    return $stmt->fetch();
}
