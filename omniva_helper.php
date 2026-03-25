<?php
// omniva_helper.php

// Jei naudojate Composer autoload, užkrauname jį:
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Mijora\Omniva\Locations\PickupPoints;
use Mijora\Omniva\Shipment\Package\Package;
use Mijora\Omniva\Shipment\Package\Address;
use Mijora\Omniva\Shipment\Package\Contact;
use Mijora\Omniva\Shipment\Shipment;
use Mijora\Omniva\Shipment\Order;
use Mijora\Omniva\Shipment\Tracking;

class OmnivaHelper {
    private $pdo;
    private $username;
    private $password;

    public function __construct($pdo = null) {
        $this->pdo = $pdo;
        // Naudokite savo prisijungimus
        $this->username = getenv('OMNIVA_API_USERNAME') ?: '8206349';
        $this->password = getenv('OMNIVA_API_PASSWORD') ?: 'Kosmosas420';
    }

    /**
     * Patikrina ar teisingi Omniva API prisijungimo duomenys (username / password)
     * Grąžina true, jei prisijungti pavyko, false - jei ne.
     */
    public function checkConnection() {
        try {
            $tracking = new Tracking();
            $tracking->setAuth($this->username, $this->password);
            
            // Bandom išsiųsti testinę užklausą su netikru barkodu. 
            // Tikslas - pažiūrėti, ar API mus apskritai įleidžia (autorizuoja).
            $tracking->getTracking('TESTCONNECTION123');
            
            return true;
        } catch (Exception $e) {
            $msg = strtolower($e->getMessage());
            
            // Jei API grąžina 401 klaidą, "unauthorized" ar pan. - slaptažodis/ID neteisingi
            if (strpos($msg, '401') !== false || strpos($msg, 'unauthorized') !== false || strpos($msg, 'auth') !== false || strpos($msg, 'password') !== false) {
                error_log("Omniva API Prisijungimo klaida: Neteisingas vartotojo vardas arba slaptažodis. Detalės: " . $e->getMessage());
                return false;
            }
            
            // Jei gauname logišką API klaidą (pvz., "siunta nerasta", "invalid barcode"), 
            // reiškia pats prisijungimas buvo SĖKMINGAS.
            return true;
        }
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

    /**
     * Sukuria Omniva siuntą ir grąžina jos barkodą
     */
    public function createParcel($orderId, $receiverName, $phone, $email, $terminalId) {
        try {
            $senderAddress = (new Address())
                ->setCountry('LT')
                ->setPostcode(getenv('STORE_ZIP') ?: '00000') // Būtina nurodyti bent minimalų ZIP
                ->setDeliverypoint(getenv('STORE_CITY') ?: 'Vilnius')
                ->setStreet(getenv('STORE_STREET') ?: 'Pardavėjo g. 1');
                
            $senderContact = (new Contact())
                ->setAddress($senderAddress)
                ->setMobile(getenv('STORE_PHONE') ?: '+37060000000')
                ->setPersonName(getenv('STORE_NAME') ?: 'Cukrinukas');

            $receiverAddress = (new Address())
                ->setCountry('LT')
                ->setOffloadPostcode($terminalId); // Paštomato ZIP kodas tarnauja kaip ID

            $receiverContact = (new Contact())
                ->setAddress($receiverAddress)
                ->setMobile($phone)
                ->setPersonName(mb_substr($receiverName, 0, 100));

            $package = new Package();
            $package
                ->setId('ORDER_' . $orderId)
                ->setService('PU') // PU reiškia 'Parcel Machine' / Paštomatas
                ->setWeight(1) // Standartinis svoris
                ->setReceiverContact($receiverContact)
                ->setSenderContact($senderContact);

            $shipment = new Shipment();
            $shipment->setPackages([$package]);

            $order = new Order();
            $order->setAuth($this->username, $this->password);

            $result = $order->create($shipment);

            if (isset($result['savedBarcodes']) && !empty($result['savedBarcodes'])) {
                return $result['savedBarcodes'][0];
            }
            
            error_log("Omniva API siuntos kūrimo atsakymas: " . json_encode($result));
            return null;
        } catch (Exception $e) {
            error_log("Omniva API klaida kuriant siuntą: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Gauna siuntos sekimo informaciją
     */
    public function getTrackingEvents($barcode) {
        try {
            $tracking = new Tracking();
            $tracking->setAuth($this->username, $this->password);
            return $tracking->getTracking($barcode);
        } catch (Exception $e) {
            error_log("Omniva API klaida gaunant tracking: " . $e->getMessage());
            return null;
        }
    }
}