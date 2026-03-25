<?php
// lpexpress_sync.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/lpexpress_helper.php';
require_once __DIR__ . '/omniva_helper.php'; // Pridėtas Omniva pagalbininkas

$pdo = getPdo();
$lpHelper = new LPExpressHelper($pdo);
$omnivaHelper = new OmnivaHelper($pdo);

// 1. Randame visus užsakymus, kurie jau turi sekimo numerį (LP Express ir Omniva)
// bet dar nėra užbaigti, atšaukti ar grąžinti
$stmt = $pdo->query("
    SELECT id, tracking_number, delivery_method 
    FROM orders 
    WHERE tracking_number IS NOT NULL 
      AND status NOT IN ('įvykdyta', 'atšaukta', 'grąžinta') 
      AND delivery_method IN ('lpexpress_terminal', 'lpexpress_courier', 'omniva_terminal')
");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($orders) > 0) {
    // Išskirstome siuntas į atskirus masyvus pagal tiekėjus
    $lpBarcodes = [];
    $omnivaOrders = [];
    
    foreach ($orders as $order) {
        if (strpos($order['delivery_method'], 'lpexpress') !== false) {
            $lpBarcodes[] = $order['tracking_number'];
        } elseif ($order['delivery_method'] === 'omniva_terminal') {
            $omnivaOrders[] = $order;
        }
    }
    
    $updatedCount = 0;

    // ==========================================
    // LP EXPRESS SINCHRONIZACIJA
    // ==========================================
    if (!empty($lpBarcodes)) {
        try {
            $events = $lpHelper->getTrackingEvents($lpBarcodes);
            $latestEvents = [];
            foreach ($events as $event) {
                $code = $event['mailBarcode'];
                if (!isset($latestEvents[$code]) || strtotime($event['eventDate']) > strtotime($latestEvents[$code]['eventDate'])) {
                    $latestEvents[$code] = $event;
                }
            }

            foreach ($orders as $order) {
                if (strpos($order['delivery_method'], 'lpexpress') === false) continue;
                
                $barcode = $order['tracking_number'];
                if (isset($latestEvents[$barcode])) {
                    $stateType = $latestEvents[$barcode]['publicStateType'];
                    $newStatus = null;

                    switch ($stateType) {
                        case 'ON_THE_WAY':
                        case 'PARCEL_RECEIVED':
                            $newStatus = 'siunčiama';
                            break;
                        case 'PARCEL_DELIVERED':
                            $newStatus = 'įvykdyta';
                            break;
                        case 'PARCEL_CANCELED':
                            $newStatus = 'atšaukta';
                            break;
                        case 'RETURNING':
                            $newStatus = 'grąžinta';
                            break;
                    }

                    if ($newStatus) {
                        $upd = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND status != ?");
                        $upd->execute([$newStatus, $order['id'], $newStatus]);
                        if ($upd->rowCount() > 0) {
                            $updatedCount++;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            echo "Klaida sinchronizuojant LP Express: " . $e->getMessage() . "\n";
        }
    }

    // ==========================================
    // OMNIVA SINCHRONIZACIJA
    // ==========================================
    if (!empty($omnivaOrders)) {
        foreach ($omnivaOrders as $order) {
            try {
                $barcode = $order['tracking_number'];
                $events = $omnivaHelper->getTrackingEvents($barcode);
                
                if (!empty($events) && is_array($events)) {
                    // 1. Randame NAUJAUSIĄ įvykį pagal 'eventDate' (nes Omniva API grąžina nuo seniausio iki naujausio)
                    $latestEvent = null;
                    $latestTime = 0;
                    
                    foreach ($events as $event) {
                        if (isset($event['eventDate'])) {
                            $eventTime = strtotime($event['eventDate']);
                            if ($eventTime > $latestTime) {
                                $latestTime = $eventTime;
                                $latestEvent = $event;
                            }
                        }
                    }
                    
                    if ($latestEvent) {
                        // 2. Ieškome įvykio kodo įvairiais galimais pavadinimais (svarbiausias - eventCode)
                        $rawStateCode = $latestEvent['eventCode'] ?? $latestEvent['stateCode'] ?? $latestEvent['StateCode'] ?? $latestEvent['state'] ?? null;
                        
                        if ($rawStateCode) {
                            $stateCode = strtoupper($rawStateCode);
                            $newStatus = null;
                            
                            // 3. Universalus statusų atpažinimas (veiks ir su naujais OMX 'TRT_SHIPMENT_...', ir su senais kodais)
                            if (strpos($stateCode, 'DELIVERED') !== false || in_array($stateCode, ['DLV'])) {
                                $newStatus = 'įvykdyta';
                            } elseif (strpos($stateCode, 'RETURN') !== false || in_array($stateCode, ['RET'])) {
                                $newStatus = 'grąžinta';
                            } elseif (strpos($stateCode, 'CANCEL') !== false || in_array($stateCode, ['CAN', 'CNL', 'DELETED'])) {
                                $newStatus = 'atšaukta';
                            } elseif (strpos($stateCode, 'REGISTERED') === false) {
                                // Jei tai ne registracija ir ne galutinis statusas, reiškia siunta kažkur juda
                                $newStatus = 'siunčiama';
                            }
                            
                            // 4. Atnaujiname duomenų bazę
                            if ($newStatus) {
                                $upd = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND status != ?");
                                $upd->execute([$newStatus, $order['id'], $newStatus]);
                                if ($upd->rowCount() > 0) {
                                    $updatedCount++;
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                echo "Klaida sinchronizuojant Omniva ($barcode): " . $e->getMessage() . "\n";
            }
        }
    }

    echo "Sinchronizacija baigta. Atnaujinta užsakymų: $updatedCount \n";
} else {
    echo "Nėra siuntų, kurias reikėtų atnaujinti.\n";
}
?>