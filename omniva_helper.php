<?php
// omniva_helper.php

// Jei naudojate Composer autoload, užkrauname jį:
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Mijora\Omniva\Locations\PickupPoints;

class OmnivaHelper {
    private $pdo;
    private $username;
    private $password;

    public function __construct($pdo = null) {
        $this->pdo = $pdo;
        // Naudokite savo prisijungimus
        $this->username = getenv('OMNIVA_API_USERNAME') ?: '8206349';
        $this->password = getenv('OMNIVA_API_PASSWORD') ?: 'Test123';
    }

    /**
     * Gauna Omniva paštomatų sąrašą Lietuvoje ir pritaiko prie esamo formato
     */
    public function getTerminals() {
        try {
            $pickupPoints = new PickupPoints();
            // Ištraukiame tik Lietuvos (LT) paštomatus
            $locations = $pickupPoints->getFilteredLocations('LT');
            
            $terminals = [];
            foreach ($locations as $loc) {
                // Naudojame masyvo sintaksę: $loc['RAKTAS'] vietoj $loc->RAKTAS
                
                // A2_NAME dažniausiai yra miestas, A1_NAME - apskritis
                $city = !empty($loc['A2_NAME']) ? $loc['A2_NAME'] : ($loc['A1_NAME'] ?? '');
                
                // Suformuojame adresą
                $address = trim(($loc['A5_NAME'] ?? '') . ' ' . ($loc['A7_NAME'] ?? ''));
                
                // Jei kartais A5 ir A7 tušti, apsidraudimui panaudojame tiesiog NAME
                if (empty($address)) {
                    $address = $loc['NAME'] ?? '';
                }

                $terminals[] = [
                    'id' => $loc['ZIP'] ?? '',
                    'name' => $loc['NAME'] ?? '',
                    'city' => $city, 
                    'address' => $address,
                ];
            }
            return $terminals;
        } catch (Exception $e) {
            error_log("Omniva API klaida gaunant terminalus: " . $e->getMessage());
            return [];
        }
    }
}