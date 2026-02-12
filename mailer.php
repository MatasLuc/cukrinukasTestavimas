<?php
// mailer.php
require_once __DIR__ . '/env.php';
loadEnvFile(); // Užkrauname kintamuosius iš .env

require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendEmail(string $to, string $subject, string $bodyHtml): bool {
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = requireEnv('SMTP_HOST');
        $mail->SMTPAuth   = true;
        $mail->Username   = requireEnv('SMTP_USER');
        $mail->Password   = requireEnv('SMTP_PASS');
        
        $port = (int)requireEnv('SMTP_PORT');
        $mail->Port = $port;
        $mail->CharSet = 'UTF-8';

        // Šifravimo nustatymai
        if ($port === 587) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = ''; 
            $mail->SMTPAutoTLS = true; 
        }

        // Recipients
        $mail->setFrom(requireEnv('SMTP_FROM_EMAIL'), requireEnv('SMTP_FROM_NAME'));
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $bodyHtml));

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Įrašome tikslią klaidą į failą 'mailer_errors.log', kad galėtumėte debugginti
        $errorMessage = date('Y-m-d H:i:s') . " - Mailer Error: {$mail->ErrorInfo}\n";
        file_put_contents(__DIR__ . '/mailer_errors.log', $errorMessage, FILE_APPEND);
        
        // Taip pat paliekame ir error_log
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Sukuria gražų HTML laiško šabloną, atitinkantį Cukrinukas.lt dizainą
 */
function getEmailTemplate(string $title, string $content, ?string $btnUrl = null, ?string $btnText = null): string {
    $year = date('Y');
    
    $accentColor = '#2563eb';
    $bgBody = '#f7f7fb';
    $bgCard = '#ffffff';
    $textMain = '#0f172a';
    $textMuted = '#475467';
    $border = '#e4e7ec';
    
    $buttonHtml = '';
    if ($btnUrl && $btnText) {
        $buttonHtml = "
        <table role='presentation' border='0' cellpadding='0' cellspacing='0' style='margin: 32px 0;'>
          <tr>
            <td align='center'>
              <a href='{$btnUrl}' style='background-color: {$accentColor}; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 12px; font-weight: 600; font-size: 15px; display: inline-block; font-family: \"Inter\", Helvetica, Arial, sans-serif; box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);'>
                {$btnText}
              </a>
            </td>
          </tr>
        </table>";
    }

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: {$bgBody}; font-family: 'Inter', Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
    <tr>
      <td style="padding: 40px 15px;" align="center">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: {$bgCard}; border-radius: 24px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); border: 1px solid {$border};">
          <tr>
            <td style="padding: 40px 40px 20px 40px; text-align: center; background-color: #ffffff;">
               <div style="font-size: 26px; font-weight: 700; color: #1e3a8a; letter-spacing: -0.5px;">
                 Cukrinukas<span style="color: {$accentColor};">.lt</span>
               </div>
            </td>
          </tr>
          <tr>
            <td style="padding: 20px 48px 48px 48px; color: {$textMuted}; font-size: 16px; line-height: 1.6; text-align: left;">
              <h1 style="margin-top: 0; margin-bottom: 24px; font-size: 22px; font-weight: 700; color: {$textMain}; text-align: center;">
                {$title}
              </h1>
              <div style="color: {$textMuted};">
                {$content}
              </div>
              {$buttonHtml}
              <div style="border-top: 1px solid {$border}; margin-top: 32px; padding-top: 24px; font-size: 14px; color: #64748b;">
                Pagarbiai,<br>
                <strong style="color: {$textMain};">Cukrinukas komanda</strong>
              </div>
            </td>
          </tr>
          <tr>
            <td style="background-color: #f8fafc; padding: 24px; text-align: center; font-size: 13px; color: #94a3b8; border-top: 1px solid {$border};">
              <p style="margin: 0;">&copy; {$year} Cukrinukas.lt. Visos teisės saugomos.</p>
              <p style="margin: 8px 0 0 0;">
                <a href="https://cukrinukas.lt" style="color: {$accentColor}; text-decoration: none; font-weight: 500;">Apsilankyti parduotuvėje</a>
              </p>
            </td>
          </tr>
        </table>
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" height="40"><tr><td>&nbsp;</td></tr></table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}
?>
