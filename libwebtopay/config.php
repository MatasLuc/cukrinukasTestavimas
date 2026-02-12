<?php
require_once __DIR__ . '/../env.php';
loadEnvFile();

return [
    'projectid' => requireEnv('PAYSERA_PROJECTID'),
    'sign_password' => requireEnv('PAYSERA_PASSWORD'),
    'pay_url' => requireEnv('PAYSERA_PAY_URL'),
    'delivery_api_url' => requireEnv('PAYSERA_DELIVERY_API_URL'),
    'test' => (int) requireEnv('PAYSERA_TEST'),
    'currency' => requireEnv('PAYSERA_CURRENCY'),
];
