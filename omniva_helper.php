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
use Mijora\Omniva\Shipment\Package\Measures;
use Mijora\Omniva\Shipment\Shipment;
use Mijora\Omniva\Shipment\ShipmentHeader;
use Mijora\Omniva\Shipment\Tracking;

class OmnivaHelper {
    private $pdo;
    private $username;
    private $password;

    public function __construct($pdo = null) {
        $this->pdo = $pdo;
        // Naudokite savo prisijungimus
        $this->username = getenv('OMNIVA_API_USERNAME') ?: '8206349';
        $this->password = getenv('OMNIVA_API_PASSWORD') ?: 's%vi>H{cb1';
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
                $city = !empty($loc['A2_NAME']) ? $loc['A2_NAME'] : ($loc['A1_NAME'] ?? '');
                $address = trim(($loc['A5_NAME'] ?? '') . ' ' . ($loc['A7_NAME'] ?? ''));
                
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
            $shipment = new Shipment();
            $shipment->setComment('Uzsakymas #' . $orderId);
            
            $shipmentHeader = new ShipmentHeader();
            $shipmentHeader
                ->setSenderCd($this->username)
                ->setFileId(date('YmdHis') . $orderId);
            $shipment->setShipmentHeader($shipmentHeader);

            $senderAddress = (new Address())
                ->setCountry('LT')
                ->setPostcode(getenv('STORE_ZIP') ?: '00000') // Būtina nurodyti bent minimalų ZIP
                ->setDeliverypoint(getenv('STORE_CITY') ?: 'Vilnius')
                ->setStreet(getenv('STORE_STREET') ?: 'Pardavejo g. 1');
                
            $senderContact = (new Contact())
                ->setAddress($senderAddress)
                ->setMobile(getenv('STORE_PHONE') ?: '+37060000000')
                ->setEmail(getenv('STORE_EMAIL') ?: 'info@cukrinukas.lt')
                ->setPersonName(getenv('STORE_NAME') ?: 'Cukrinukas');

            $receiverAddress = (new Address())
                ->setCountry('LT')
                ->setOffloadPostcode($terminalId); // Paštomato ZIP kodas tarnauja kaip ID

            $receiverContact = (new Contact())
                ->setAddress($receiverAddress)
                ->setMobile($phone)
                ->setEmail($email)
                ->setPersonName(mb_substr($receiverName, 0, 100));

            $package = new Package();
            $package
                ->setId('ORDER_' . $orderId)
                ->setService('PU') // PU reiškia 'Parcel Machine' / Paštomatas
                ->setReceiverContact($receiverContact)
                ->setSenderContact($senderContact);

            // ======================================
            // Ištaisyta svorio nustatymo logika
            // ======================================
            $measures = new Measures();
            $measures->setWeight(1); // Standartinis svoris
            $package->setMeasures($measures);

            $shipment->setPackages([$package]);
            $shipment->setAuth($this->username, $this->password);

            // Kviečiame API naudodami seną, stabilesnį metodą (išvengiam pakibimų)
            $result = $shipment->registerShipment(true);

            if (is_array($result) && isset($result['barcodes']) && !empty($result['barcodes'])) {
                return $result['barcodes'][0];
            } elseif (is_string($result)) {
                // Pabandom paskaityti senojo API (XML) atsakymą
                $xmlResponse = @simplexml_load_string($result);
                if ($xmlResponse !== false && isset($xmlResponse->savedPacketInfo->barcode)) {
                     return (string) $xmlResponse->savedPacketInfo->barcode;
                }
            }
            
            error_log("Omniva API siuntos kūrimo klaida / nenumatytas atsakymas: " . json_encode($result));
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
            // Pakeista į OMX API metodą
            return $tracking->getTrackingOmx($barcode);
        } catch (Exception $e) {
            error_log("Omniva API klaida gaunant tracking: " . $e->getMessage());
            return null;
        }
    }
}