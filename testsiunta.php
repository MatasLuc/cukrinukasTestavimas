<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/omniva_helper.php';

$pdo = getPdo();
$omnivaHelper = new OmnivaHelper($pdo);

// ĮRAŠYKITE SAVO ATŠAUKTOS SIUNTOS BARKODĄ ČIA:
$barcode = 'CC982515234EE'; 

try {
    $events = $omnivaHelper->getTrackingEvents($barcode);
    echo "<pre>";
    print_r($events);
    echo "</pre>";
} catch (Exception $e) {
    echo "Klaida: " . $e->getMessage();
}
?>