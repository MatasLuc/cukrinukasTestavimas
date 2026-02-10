<?php
// Įjungiame klaidų rodymą
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

echo "<h3>📧 SMTP Diagnostika</h3>";

// 1. Tikriname, ar užsikrauna nustatymai
$vars = ['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS', 'SMTP_PORT', 'SMTP_FROM_EMAIL'];
$missing = [];
foreach ($vars as $v) {
    $val = getenv($v);
    if (!$val) $missing[] = $v;
    else echo "<div><strong>$v:</strong> " . ($v === 'SMTP_PASS' ? '******' : htmlspecialchars($val)) . "</div>";
}

if (!empty($missing)) {
    echo "<h3 style='color:red'>❌ TRŪKSTA .env KINTAMŲJŲ: " . implode(', ', $missing) . "</h3>";
    exit;
}

// 2. Bandome siųsti su detaliu žurnalu
echo "<hr><strong>Pradedamas siuntimas... (žiūrėkite apačioje)</strong><br><br>";

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = getenv('SMTP_HOST');
    $mail->SMTPAuth   = true;
    $mail->Username   = getenv('SMTP_USER');
    $mail->Password   = getenv('SMTP_PASS');
    $mail->Port       = (int)getenv('SMTP_PORT');
    
    // SVARBU: Jūsų mailer.php kodas priverstinai naudojo SSL (ENCRYPTION_SMTPS).
    // Čia bandome atspėti teisingą protokolą pagal portą.
    if ($mail->Port == 587) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        echo "<div><em>Naudojamas STARTTLS (pagal portą 587)</em></div>";
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        echo "<div><em>Naudojamas SSL/SMTPS (standartinis nustatymas)</em></div>";
    }

    // Įjungiame „Debug“ režimą, kad matytume, ką atsako serveris
    $mail->SMTPDebug = 2; 
    $mail->Debugoutput = 'html';

    $mail->setFrom(getenv('SMTP_FROM_EMAIL'), 'Debug Test');
    
    // Įveskite savo el. paštą testavimui
    $to = 'matas.luckuss@gmail.com'; 
    $mail->addAddress($to);

    $mail->Subject = 'SMTP Testas ' . date('H:i:s');
    $mail->Body    = 'Tai yra testinis laiškas ryšio patikrinimui.';

    $mail->send();
    echo "<h2 style='color:green'>✅ LAIŠKAS IŠSIŲSTAS SĖKMINGAI!</h2>";
    echo "<p>Patikrinkite gavėjo ($to) dėžutę (ir Spam).</p>";
    
} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ KLAIDA: Nepavyko išsiųsti</h2>";
    echo "<div style='background: #ffebeb; padding: 10px; border: 1px solid red;'>";
    echo "<strong>Mailer Error:</strong> " . $mail->ErrorInfo;
    echo "</div>";
}
?>
