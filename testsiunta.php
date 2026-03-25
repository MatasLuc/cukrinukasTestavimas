<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Pridėkite savo autoload failą, jei naudojate Composer
require_once __DIR__ . '/vendor/autoload.php'; 

use Mijora\Omniva\Request;
use Mijora\Omniva\Shipment\Request\CustomOmxRequest;
use Mijora\Omniva\Shipment\Request\OmxRequestInterface;

// ĮRAŠYKITE SAVO OMNIVA PRISIJUNGIMO DUOMENIS (arba pasiimkite iš savo DB/env):
$omnivaUser = '8206349'; 
$omnivaPass = 's%vi>H{cb1';
$barcode = 'CC982515234EE'; // Atšauktos siuntos barkodas

try {
    $request = new Request($omnivaUser, $omnivaPass);
    
    // Darome tiesioginę užklausą į API
    $customRequest = (new CustomOmxRequest())
        ->setEndpoint('shipments/' . $barcode)
        ->setRequestMethod(OmxRequestInterface::REQUEST_METHOD_GET);
        
    $response = $request->callOmxApi($customRequest);
    
    echo "<pre>PILNAS OMNIVA API ATSAKYMAS:\n";
    print_r(json_decode($response, true));
    echo "</pre>";
    
} catch (Exception $e) {
    echo "Klaida: " . $e->getMessage();
}
?>