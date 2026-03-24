<?php

class LPExpressHelper {
    private $conn;
    private $apiUrl;
    private $username;
    private $password;

    public function __construct($conn) {
        $this->conn = $conn;
        // Naudojame aplinkos kintamuosius. Jei .env dar neužkrautas, naudokite numatytas testines reikšmes
        $this->apiUrl = getenv('LPEXPRESS_API_URL') ?: 'https://api-manosiuntostst.post.lt';
        $this->username = getenv('LPEXPRESS_API_USERNAME') ?: 'labas@cukrinukas.lt';
        $this->password = getenv('LPEXPRESS_API_PASSWORD') ?: 'Test123';
    }

    /**
     * Gauna Access Token. Jei jis išsaugotas DB ir dar galioja, naudoja jį.
     */
    private function getAccessToken() {
        // Patikriname, ar tokenas yra duomenų bazėje (naudojame PDO)
        $stmt = $this->conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'lpexpress_token_data'");
        $stmt->execute();
        
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tokenData = json_decode($row['setting_value'], true);
            // Patikriname ar tokenas dar galioja (paliekame 5 min buferį)
            if (isset($tokenData['expires_at']) && $tokenData['expires_at'] > (time() + 300)) {
                return $tokenData['access_token'];
            }
        }

        // Jei tokeno nėra arba jis baigė galioti, darome užklausą naujam
        $endpoint = $this->apiUrl . '/oauth/token?grant_type=password&username=' . urlencode($this->username) . '&password=' . urlencode($this->password) . '&scope=read%2Bwrite%2BAPI_CLIENT';
        
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close nereikalingas PHP 8.0+

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['access_token'])) {
                // Išsaugome tokeną į DB (naudojame PDO)
                $expiresAt = time() + $data['expires_in'];
                $saveData = json_encode([
                    'access_token' => $data['access_token'],
                    'expires_at' => $expiresAt
                ]);

                $stmt = $this->conn->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('lpexpress_token_data', ?)");
                $stmt->execute([$saveData]);

                return $data['access_token'];
            }
        }

        throw new Exception("Nepavyko gauti LP Express API Tokeno. Atsakymas: " . $response);
    }

    /**
     * Pagrindinis metodas API užklausoms
     */
    private function request($method, $endpoint, $data = null) {
        $token = $this->getAccessToken();
        $ch = curl_init($this->apiUrl . $endpoint);

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close nereikalingas PHP 8.0+

        $decoded = json_decode($response, true);
        
        // Jei tai PDF lipdukas, grąžinsime patį atsakymą (raw data), ne masyvą
        if ($method === 'GET' && strpos($endpoint, '/sticker/pdf') !== false && $httpCode === 200) {
            return $response;
        }

        if ($httpCode >= 400) {
            throw new Exception("LP Express API Klaida ($httpCode): " . print_r($decoded, true));
        }

        return $decoded;
    }

    /**
     * 1. Gauna visų paštomatų (terminalų) sąrašą Lietuvoje
     */
    public function getTerminals() {
        return $this->request('GET', '/api/v2/terminal?receiverCountryCode=LT');
    }

    /**
     * 2. Sukuria siuntą (Parcel)
     */
    public function createParcel($orderId, $deliveryMethod, $receiverName, $phone, $email, $addressData, $terminalId = null) {
        // T2T = iš paštomato į paštomatą. T2H = iš paštomato į rankas (kurjeris)
        $isTerminal = ($deliveryMethod === 'lpexpress_terminal');
        
        $planCode = $isTerminal ? 'TERMINAL' : 'HANDS';
        $parcelType = $isTerminal ? 'T2T' : 'T2H';
        
        // Formuojame užklausą pagal dokumentaciją
        $payload = [
            "idRef" => "ORDER_" . $orderId,
            "plan" => ["code" => $planCode],
            "parcel" => [
                "type" => $parcelType,
                "size" => "S" // Numatytas dydis, vėliau galima pritaikyti pagal prekes
            ],
            "receiver" => [
                "name" => mb_substr($receiverName, 0, 100), // Max ilgis 100
                "contacts" => [
                    "phone" => $phone,
                    "email" => $email
                ]
            ]
        ];

        // Pridedame adresą arba paštomato ID
        if ($isTerminal && $terminalId) {
            $payload["receiver"]["address"] = [
                "countryCode" => "LT",
                "terminalId" => $terminalId
            ];
        } else {
            // Kurjeriui reikia adreso
            $payload["receiver"]["address"] = [
                "countryCode" => "LT",
                "street" => mb_substr($addressData, 0, 100) 
            ];
        }

        $response = $this->request('POST', '/api/v2/parcel', $payload);
        return $response['parcelId'] ?? null;
    }

    /**
     * 3. Inicijuoja siuntą, kad ji būtų patvirtinta
     */
    public function initiateShipping($parcelId) {
        $payload = ["parcelIds" => [$parcelId]];
        $response = $this->request('POST', '/api/v2/shipping/initiate?processAsync=false', $payload);
        return $response['requestId'] ?? null;
    }

    /**
     * 4. Gauna siuntos sekimo numerį pagal Request ID
     */
    public function getShippingStatus($requestId) {
        $response = $this->request('GET', '/api/v2/shipping/status/' . $requestId);
        if (isset($response['items'][0]['barcode'])) {
            return $response['items'][0]['barcode'];
        }
        return null;
    }

    /**
     * 5. Parsisiunčia lipduką (PDF formatu)
     */
    public function getLabelPdf($parcelId) {
        return $this->request('GET', '/api/v2/sticker/pdf?parcelIds=' . $parcelId . '&layout=LAYOUT_10x15&labelOrientation=PORTRAIT');
    }
}