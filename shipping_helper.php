<?php

/**
 * Gauna pristatymo nustatymus iš DB arba grąžina numatytuosius.
 * Apsaugota nuo dvigubo deklaravimo konflikto su db.php
 */
if (!function_exists('getShippingSettings')) {
    function getShippingSettings($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM shipping_settings LIMIT 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($settings) {
                return $settings;
            }
        } catch (Exception $e) {
            // Jei lentelės nėra, tęsiame su numatytaisiais
        }

        // Numatytieji nustatymai, jei duomenų bazėje nieko nėra
        return [
            'base_price' => 0,
            'courier_price' => 4.99,
            'locker_price' => 2.99,
            'free_over' => 50.00
        ];
    }
}

/**
 * Paskaičiuoja pristatymo kainą pagal krepšelio sumą ir metodą.
 * Pridėtas $forceFree parametras specialioms prekėms.
 */
if (!function_exists('calculateShippingPrice')) {
    function calculateShippingPrice($settings, $cartTotal, $method, $forceFree = false) {
        // Jei yra speciali prekė suteikianti nemokamą pristatymą
        if ($forceFree) {
            return 0.00;
        }

        // Ar taikomas nemokamas pristatymas pagal sumą?
        if (isset($settings['free_over']) && $settings['free_over'] > 0 && $cartTotal >= $settings['free_over']) {
            return 0.00;
        }

        switch ($method) {
            case 'courier':
                return (float)($settings['courier_price'] ?? 4.99);
            case 'locker':
                return (float)($settings['locker_price'] ?? 2.99);
            case 'pickup':
                return 0.00;
            default:
                return 0.00;
        }
    }
}
