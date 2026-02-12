<?php

function buildPayseraParams(array $order, array $config): array
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = $scheme . '://' . $host;

    return [
        'projectid' => $config['projectid'],
        'sign_password' => $config['sign_password'],
        'pay_url' => $config['pay_url'],
        'orderid' => $order['id'],
        'amount' => (int) round(((float)$order['total']) * 100),
        'currency' => $config['currency'],
        'accepturl' => $base . '/libwebtopay/accept.php',
        'cancelurl' => $base . '/libwebtopay/cancel.php',
        'callbackurl' => $base . '/libwebtopay/callback.php',
        'country' => 'LT',
        'test' => $config['test'],
    ];
}

