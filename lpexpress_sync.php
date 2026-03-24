<?php
// lpexpress_sync.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/lpexpress_helper.php';

$pdo = getPdo();
$lpHelper = new LPExpressHelper($pdo);

// 1. Randame visus užsakymus, kurie jau turi LP Express sekimo numerį, 
// bet dar nėra užbaigti, atšaukti ar grąžinti
$stmt = $pdo->query("
    SELECT id, tracking_number 
    FROM orders 
    WHERE tracking_number IS NOT NULL 
      AND status NOT IN ('įvykdyta', 'atšaukta', 'grąžinta') 
      AND delivery_method IN ('lpexpress_terminal', 'lpexpress_courier')
");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($orders) > 0) {
    // Ištraukiame tik barkodus į vieną masyvą
    $barcodes = array_column($orders, 'tracking_number');
    
    try {
        // Vienu kreipimusi gauname visų siuntų įvykius
        $events = $lpHelper->getTrackingEvents($barcodes);
        
        // 2. Apdorojame atsakymą (ieškome PATIES NAUJAUSIO įvykio kiekvienai siuntai)
        $latestEvents = [];
        foreach ($events as $event) {
            $code = $event['mailBarcode'];
            // Jei tokio barkodo dar neturim, arba šis įvykis naujesnis už turimą
            if (!isset($latestEvents[$code]) || strtotime($event['eventDate']) > strtotime($latestEvents[$code]['eventDate'])) {
                $latestEvents[$code] = $event;
            }
        }

        // 3. Atnaujiname užsakymų duomenų bazę
        $updatedCount = 0;
        foreach ($orders as $order) {
            $barcode = $order['tracking_number'];
            
            if (isset($latestEvents[$barcode])) {
                $stateType = $latestEvents[$barcode]['publicStateType'];
                $newStatus = null;

                // Susiejame LP Express būsenas su jūsų sistemos būsenomis
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

                // Jei sugalvojome naują statusą, išsaugome jį DB
                if ($newStatus) {
                    $upd = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND status != ?");
                    $upd->execute([$newStatus, $order['id'], $newStatus]);
                    
                    if ($upd->rowCount() > 0) {
                        $updatedCount++;
                    }
                }
            }
        }
        
        echo "Sinchronizacija baigta. Atnaujinta užsakymų: $updatedCount \n";
        
    } catch (Exception $e) {
        echo "Klaida sinchronizuojant: " . $e->getMessage() . "\n";
    }
} else {
    echo "Nėra siuntų, kurias reikėtų atnaujinti.\n";
}
?>