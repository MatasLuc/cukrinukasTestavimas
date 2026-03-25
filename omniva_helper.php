<?php
// omniva_helper.php

// Jei naudojate Composer autoload, užkrauname jį:
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Mijora\Omniva\Locations\PickupPoints;
use Mijora\Omniva\Shipment\Shipment;
use Mijora\Omniva\Shipment\ShipmentHeader;
use Mijora\Omniva\Shipment\Package\Package;
use Mijora\Omniva\Shipment\Package\Address;
use Mijora\Omniva\Shipment\Package\Contact;
use Mijora\Omniva\Shipment\Package\Measures;

class OmnivaHelper {
    private $pdo;
    private $username;
    private $password;

    public function __construct($pdo = null) {
        $this->pdo = $pdo;
        $this->username = getenv('OMNIVA_API_USERNAME') ?: '8206349';
        $this->password = getenv('OMNIVA_API_PASSWORD') ?: 'Kosmosas420!';
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
                $terminals[] = [
                    'id' => $loc->ZIP,
                    'name' => $loc->NAME,
                    // A2_NAME dažniausiai yra miestas, A1_NAME - apskritis
                    'city' => !empty($loc->A2_NAME) ? $loc->A2_NAME : $loc->A1_NAME, 
                    'address' => trim(($loc->A5_NAME ?? '') . ' ' . ($loc->A7_NAME ?? '')),
                ];
            }
            return $terminals;
        } catch (Exception $e) {
            error_log("Omniva API klaida gaunant terminalus: " . $e->getMessage());
            return [];
        }
    }
}