<?php
require_once __DIR__ . '/db.php';

function getShippingSettings(PDO $pdo) {
    // Paimame naujausius nustatymus
    $stmt = $pdo->query("SELECT * FROM shipping_settings ORDER BY updated_at DESC LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Jei lentelė tuščia, grąžiname numatytąsias reikšmes
    if (!$settings) {
        return [
            'base_price' => 0,
            'courier_price' => 4.99,
            'locker_price' => 2.99,
            'free_over' => 50.00
        ];
    }
    
    return $settings;
}

function calculateShippingPrice($settings, $cartTotal, $method) {
    // 1. Patikriname ar taikomas nemokamas pristatymas pagal sumą
    if ($settings['free_over'] > 0 && $cartTotal >= $settings['free_over']) {
        return 0.00;
    }

    // 2. Grąžiname kainą pagal metodą
    // Pastaba: 'base_price' galite naudoti kaip fiksuotą mokestį, jei reikia, 
    // bet čia naudosime konkrečius courier/locker įkainius.
    switch ($method) {
        case 'courier':
            return (float)$settings['courier_price'];
        case 'locker':
            return (float)$settings['locker_price']; // Pataisiau jūsų 'locker_pricel' typo į standartinį, jei DB stulpelis kitoks - pakeiskite čia
        case 'pickup':
            return 0.00;
        default:
            return (float)$settings['base_price'];
    }
}
?>
