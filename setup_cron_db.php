<?php
// setup_cron_db.php
require_once __DIR__ . '/db.php';

try {
    $pdo = getPdo();
    
    // 1. Atnaujiname ORDERS lentelę (laiškų sekimui)
    $columnsOrders = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    
    $alterOrders = [];
    
    // Statusas: Išsiųsta
    if (!in_array('email_shipped_sent', $columnsOrders)) {
        $alterOrders[] = "ALTER TABLE orders ADD COLUMN email_shipped_sent TINYINT(1) NOT NULL DEFAULT 0";
    }
    // Statusas: Įvykdyta (Atsiliepimai)
    if (!in_array('email_review_sent', $columnsOrders)) {
        $alterOrders[] = "ALTER TABLE orders ADD COLUMN email_review_sent TINYINT(1) NOT NULL DEFAULT 0";
    }
    // Jei dar nėra ankstesnių
    if (!in_array('email_rem_1h', $columnsOrders)) {
        $alterOrders[] = "ALTER TABLE orders ADD COLUMN email_rem_1h TINYINT(1) NOT NULL DEFAULT 0";
    }
    if (!in_array('email_rem_24h', $columnsOrders)) {
        $alterOrders[] = "ALTER TABLE orders ADD COLUMN email_rem_24h TINYINT(1) NOT NULL DEFAULT 0";
    }
    if (!in_array('email_thankyou', $columnsOrders)) {
        $alterOrders[] = "ALTER TABLE orders ADD COLUMN email_thankyou TINYINT(1) NOT NULL DEFAULT 0";
    }
    // Pridedame updated_at, kad žinotume, kada pasikeitė statusas į "įvykdyta"
    if (!in_array('updated_at', $columnsOrders)) {
        $alterOrders[] = "ALTER TABLE orders ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
    }

    foreach ($alterOrders as $sql) {
        $pdo->exec($sql);
        echo "Orders lentelė atnaujinta: $sql <br>";
    }

    // 2. Atnaujiname USERS lentelę (Gimtadieniams ir Win-back)
    $columnsUsers = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $alterUsers = [];

    // Fiksuojame metus, kuriais pasveikinome (kad nesveikintume kelis kartus per tuos pačius metus)
    if (!in_array('last_birthday_promo', $columnsUsers)) {
        $alterUsers[] = "ALTER TABLE users ADD COLUMN last_birthday_promo INT DEFAULT NULL";
    }
    // Fiksuojame datą, kada siuntėme "Pasiilgome jūsų" laišką
    if (!in_array('last_winback_promo', $columnsUsers)) {
        $alterUsers[] = "ALTER TABLE users ADD COLUMN last_winback_promo DATETIME DEFAULT NULL";
    }

    foreach ($alterUsers as $sql) {
        $pdo->exec($sql);
        echo "Users lentelė atnaujinta: $sql <br>";
    }
    
    echo "<h3>Duomenų bazė sėkmingai paruošta visiems automatiniams laiškams!</h3>";

} catch (PDOException $e) {
    die("Klaida atnaujinant DB: " . $e->getMessage());
}
?>
