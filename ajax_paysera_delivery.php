<?php
// ajax_paysera_delivery.php
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');

// Tikriname, ar užklausa atėjo POST metodu
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Netinkamas užklausos metodas.']);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';

// Įkrauname aplinkos kintamuosius
loadEnvFile(__DIR__ . '/.env');

try {
    $pdo = getPdo();

    // 1. Gauname parduotuvės bazines siuntimo kainas iš duomenų bazės
    $stmt = $pdo->query("SELECT * FROM shipping_settings LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    $baseLockerPrice = (float)($settings['locker_price'] ?? 2.49);
    $baseCourierPrice = (float)($settings['courier_price'] ?? 3.99);

    // 2. Nuskaitome išsiųstus pristatymo duomenis
    $method = $_POST['method'] ?? '';
    $fallbackPrice = ($method === 'courier') ? $baseCourierPrice : $baseLockerPrice;

    // Pasiruošiame API prisijungimo duomenis iš aplinkos kintamųjų
    $projectId = getenv('PAYSERA_PROJECTID') ?: ($_ENV['PAYSERA_PROJECTID'] ?? '');
    $password = getenv('PAYSERA_PASSWORD') ?: ($_ENV['PAYSERA_PASSWORD'] ?? '');
    $apiUrl = getenv('PAYSERA_DELIVERY_API_URL') ?: ($_ENV['PAYSERA_DELIVERY_API_URL'] ?? 'https://delivery-api.paysera.com/merchant/rest/v1/');
    
    // Siuntėjo duomenys
    $senderCity = getenv('PAYSERA_SENDER_CITY') ?: ($_ENV['PAYSERA_SENDER_CITY'] ?? 'Vilnius');
    $senderZip = getenv('PAYSERA_SENDER_ZIP') ?: ($_ENV['PAYSERA_SENDER_ZIP'] ?? '08102');

    // Jei trūksta API prisijungimų, iškart grąžiname bazinę kainą, kad nestabdytume pirkimo
    if (empty($projectId) || empty($password)) {
        echo json_encode(['success' => true, 'price' => number_format($fallbackPrice, 2, '.', '')]);
        exit;
    }

    // 3. Suformuojame Paysera API užklausos duomenis (Hardcoded M dydis ir 1kg)
    $payload = [
        'sender' => [
            'country_code' => 'LT',
            'city' => $senderCity,
            'postal_code' => $senderZip
        ],
        'receiver' => [
            'country_code' => 'LT'
        ],
        'parcels' => [
            [
                'weight' => 1.0,
                'package_size' => 'M'
            ]
        ]
    ];

    // Pridedame specifinius gavėjo duomenis priklausomai nuo pasirinkimo
    if ($method === 'locker') {
        $parcelMachineId = $_POST['locker_id'] ?? '';
        
        if (empty($parcelMachineId)) {
            echo json_encode(['success' => true, 'price' => number_format($fallbackPrice, 2, '.', '')]);
            exit;
        }
        
        $payload['receiver']['parcel_machine_id'] = $parcelMachineId;
        
    } elseif ($method === 'courier') {
        $receiverCity = trim($_POST['city'] ?? '');
        $receiverZip = trim($_POST['zip'] ?? '');
        
        // Jei pirkėjas dar neįvedė pilno adreso, grąžiname bazinę kainą ir laukiame tolesnio įvedimo
        if (empty($receiverCity) || empty($receiverZip)) {
            echo json_encode(['success' => true, 'price' => number_format($fallbackPrice, 2, '.', '')]);
            exit;
        }
        
        $payload['receiver']['city'] = $receiverCity;
        // Išvalome pašto kodą (jei vartotojas įvedė pvz., "LT-12345", paliekame tik "12345")
        $payload['receiver']['postal_code'] = str_replace(['LT-', 'lt-', ' '], '', $receiverZip);
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Nežinomas pristatymo būdas.']);
        exit;
    }

    // 4. Siunčiame cURL užklausą į Paysera Delivery API
    $endpoint = rtrim($apiUrl, '/') . '/shipments/price';
    
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($projectId . ':' . $password)
    ]);
    
    // Nustatome trumpą limitą (5 sek.), kad API strigimo atveju klientas neturėtų ilgai laukti formoje
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); 

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $apiPrice = 0.00;

    // 5. Apdorojame API atsakymą
    if ($httpCode >= 200 && $httpCode < 300 && $response) {
        $responseData = json_decode($response, true);
        
        if (isset($responseData['price'])) {
            $apiPrice = (float)$responseData['price'];
        } elseif (isset($responseData['total_price'])) {
            $apiPrice = (float)$responseData['total_price'];
        } elseif (isset($responseData['total'])) {
            $apiPrice = (float)$responseData['total'];
        }
    }

    // 6. Kainų palyginimas - klientui pritaikome didesnę kainą iš dviejų galimų
    $finalPrice = max($apiPrice, $fallbackPrice);

    echo json_encode([
        'success' => true,
        'price' => number_format($finalPrice, 2, '.', '')
    ]);

} catch (Exception $e) {
    // Įvykus kritinei serverio klaidai (Fail-safe apsauga), grąžiname bazinę kainą
    echo json_encode([
        'success' => true, 
        'price' => isset($fallbackPrice) ? number_format($fallbackPrice, 2, '.', '') : '3.99'
    ]);
}
