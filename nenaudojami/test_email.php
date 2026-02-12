<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/mailer.php';

echo "Bandome siųsti laišką...<br>";

// Pakeiskite į savo el. paštą
$to = 'matas.luckuss@gmail.com'; 

if (sendEmail($to, 'Testas', '<h1>Tai testinis laiškas</h1>')) {
    echo "Laiškas sėkmingai išsiųstas!";
} else {
    echo "KLAIDA: Laiško išsiųsti nepavyko.";
}
?>
