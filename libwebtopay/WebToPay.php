<?php
/**
 * Minimal Paysera (WebToPay) helper used for redirect and callback verification.
 * This is a lightweight subset built to keep the project self contained.
 */
class WebToPay
{
    public static string $endpoint = 'https://www.paysera.com/pay/';

    public static function redirectToPayment(array $params): void
    {
        if (empty($params['sign_password'])) {
            throw new InvalidArgumentException('sign_password is required');
        }
        $signPassword = $params['sign_password'];
        unset($params['sign_password']);

        $payload = self::buildRequest($params, $signPassword);
        $endpoint = $params['pay_url'] ?? self::$endpoint;
        unset($params['pay_url']);

        echo '<!doctype html><html><head><meta charset="utf-8"><title>Paysera</title></head><body>';
        echo '<form id="paysera" method="POST" action="' . htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="data" value="' . htmlspecialchars($payload['data'], ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="sign" value="' . htmlspecialchars($payload['sign'], ENT_QUOTES, 'UTF-8') . '">';
        echo '<noscript><button type="submit">Tęsti į apmokėjimą</button></noscript>';
        echo '</form>';
        echo '<script>document.getElementById("paysera").submit();</script>';
        echo '</body></html>';
        exit;
    }

    public static function buildRequest(array $params, string $signPassword): array
    {
        $data = base64_encode(http_build_query($params));
        $sign = md5($data . $signPassword);
        return ['data' => $data, 'sign' => $sign];
    }

    public static function parseCallback(array $request, string $signPassword): array
    {
        $data = $request['data'] ?? '';
        $sign = $request['sign'] ?? '';
        if (!$data || !$sign) {
            throw new InvalidArgumentException('Missing Paysera data');
        }
        if (md5($data . $signPassword) !== $sign) {
            throw new InvalidArgumentException('Invalid Paysera signature');
        }
        $decoded = base64_decode($data);
        $response = [];
        parse_str($decoded, $response);
        return $response;
    }
}
