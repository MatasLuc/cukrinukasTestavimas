<?php
// admin/shipping.php

// 1. VEIKSMŲ APDOROJIMAS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';

    // --- KAINŲ NUSTATYMAI ---
    if ($action === 'update_settings') {
        $base = (float)($_POST['base_price'] ?? 0);
        $courier = (float)($_POST['courier_price'] ?? 0);
        $locker = (float)($_POST['locker_price'] ?? 0);
        $freeOver = $_POST['free_over'] === '' ? null : (float)$_POST['free_over'];
        
        saveShippingSettings($pdo, $base, $courier, $locker, $freeOver);
        $_SESSION['flash_success'] = 'Pristatymo kainos atnaujintos.';
        header('Location: /admin.php?view=shipping');
        exit;
    }

    // --- PAŠTOMATAI (CSV IMPORTAS) ---
    if ($action === 'import_lockers') {
        $provider = $_POST['provider'] ?? 'lpexpress';
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === 0) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if ($handle) {
                $lockers = [];
                // Nuskaitome CSV. Tikimės formato: Pavadinimas, Adresas, Pastaba (nebūtina)
                // Galima praleisti pirmą eilutę jei tai headeris, bet čia skaitome viską
                while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                    if (count($data) >= 2) {
                        $lockers[] = [
                            'title' => trim($data[0]),
                            'address' => trim($data[1]),
                            'note' => $data[2] ?? ''
                        ];
                    }
                }
                fclose($handle);
                
                if (count($lockers) > 0) {
                    bulkSaveParcelLockers($pdo, $provider, $lockers);
                    $_SESSION['flash_success'] = 'Sėkmingai importuota ' . count($lockers) . ' paštomatų.';
                } else {
                    $_SESSION['flash_error'] = 'Nepavyko nuskaityti CSV arba failas tuščias.';
                }
            }
        }
        header('Location: /admin.php?view=shipping');
        exit;
    }

    // --- PAŠTOMATAS (RANKINIS IŠSAUGOJIMAS) ---
    if ($action === 'save_locker') {
        $id = (int)($_POST['id'] ?? 0);
        $provider = $_POST['provider'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $note = trim($_POST['note'] ?? '');

        if ($provider && $title && $address) {
            if ($id > 0) {
                updateParcelLocker($pdo, $id, $provider, $title, $address, $note);
            } else {
                saveParcelLocker($pdo, $provider, $title, $address, $note);
            }
            $_SESSION['flash_success'] = 'Paštomatas išsaugotas.';
        } else {
            $_SESSION['flash_error'] = 'Užpildykite privalomus laukus.';
        }
        header('Location: /admin.php?view=shipping');
        exit;
    }

    // --- IŠTRINTI PAŠTOMATĄ ---
    if ($action === 'delete_locker') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM parcel_lockers WHERE id = ?")->execute([$id]);
        $_SESSION['flash_success'] = 'Paštomatas ištrintas.';
        header('Location: /admin.php?view=shipping');
        exit;
    }

    // --- NEMOKAMO PRISTATYMO PREKĖS ---
    if ($action === 'update_free_shipping_products') {
        $ids = $_POST['product_ids'] ?? [];
        // Išvalome 0 ir pasikartojimus
        $ids = array_unique(array_filter($ids, fn($v) => (int)$v > 0));
        saveFreeShippingProducts($pdo, $ids);
        $_SESSION['flash_success'] = 'Dovanų prekės atnaujintos.';
        header('Location: /admin.php?view=shipping');
        exit;
    }
}

// 2. DUOMENŲ GAVIMAS
$settings = getShippingSettings($pdo);
$lockersGrouped = getLockerNetworks($pdo);
$freeShippingProducts = getFreeShippingProducts($pdo); // Grąžina jau su produkto info
$allProducts = $pdo->query("SELECT id, title FROM products ORDER BY title ASC")->fetchAll();

// Suskaičiuojame paštomatus
$lockerCounts = [];
foreach ($lockersGrouped as $prov => $list) {
    $lockerCounts[$prov] = count($list);
}
?>

<style>
    .shipping-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
    @media (max-width: 900px) { .shipping-grid { grid-template-columns: 1fr; } }
    
    .price-row { 
        display: flex; justify-content: space-between; align-items: center; 
        padding: 10px 0; border-bottom: 1px solid #f3f4f6; 
    }
    .price-row:last-child { border-bottom: none; }
    .price-row label { font-size: 14px; font-weight: 600; color: #374151; }
    .price-input { width: 100px; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; text-align: right; font-weight: 600; }
    
    .stat-badge { background: #e0e7ff; color: #3730a3; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
    
    .locker-list-preview { max-height: 300px; overflow-y: auto; border: 1px solid #eee; border-radius: 6px; margin-top: 10px; }
    .locker-item { padding: 8px 10px; border-bottom: 1px solid #f9f9f9; display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
    .locker-item:hover { background: #f9fafb; }
</style>

<div class="shipping-grid">
    <div style="display:flex; flex-direction:column; gap:24px;">
        
        <div class="card">
            <h3 style="margin-top:0;">Pristatymo kainos</h3>
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_settings">
                
                <div class="price-row">
                    <label>🚚 Kurjeris į namus (€)</label>
                    <input type="number" step="0.01" name="courier_price" class="price-input" value="<?php echo $settings['courier_price']; ?>">
                </div>
                <div class="price-row">
                    <label>📦 Paštomatas (€)</label>
                    <input type="number" step="0.01" name="locker_price" class="price-input" value="<?php echo $settings['locker_price']; ?>">
                </div>
                <div class="price-row">
                    <label>Bazinė kaina (Atsarginė) (€)</label>
                    <input type="number" step="0.01" name="base_price" class="price-input" value="<?php echo $settings['base_price']; ?>">
                </div>
                <div class="price-row" style="background:#f0fdf4; margin: 0 -20px; padding: 10px 20px; border-top:1px solid #dcfce7; border-bottom:1px solid #dcfce7;">
                    <label style="color:#166534;">🎉 Nemokamai nuo sumos (€)</label>
                    <input type="number" step="0.01" name="free_over" class="price-input" placeholder="-" value="<?php echo $settings['free_over']; ?>" style="border-color:#86efac;">
                </div>
                
                <div style="margin-top:16px; text-align:right;">
                    <button type="submit" class="btn">Išsaugoti nustatymus</button>
                </div>
            </form>
        </div>

        <div class="card" style="background:#fefce8; border-color:#fef08a;">
            <h3 style="margin-top:0; color:#854d0e;">Dovanų prekės (Nemokamas siuntimas)</h3>
            <p class="muted" style="font-size:13px; margin-bottom:15px; color:#a16207;">
                Jei klientas įsideda bent vieną iš šių prekių į krepšelį, jam aktyvuojamas nemokamas pristatymas. (Maks. 4 prekės).
            </p>
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_free_shipping_products">
                
                <?php for ($i = 0; $i < 4; $i++): 
                    $currentId = $freeShippingProducts[$i]['product_id'] ?? 0;
                ?>
                    <div style="margin-bottom:10px;">
                        <select name="product_ids[]" class="form-control" style="border-color:#fde047;">
                            <option value="0">-- Pasirinkite prekę --</option>
                            <?php foreach ($allProducts as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo ($p['id'] == $currentId) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endfor; ?>
                
                <div style="margin-top:16px; text-align:right;">
                    <button type="submit" class="btn" style="background:#ca8a04; border-color:#ca8a04;">Atnaujinti dovanas</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0;">Paštomatų tinklai</h3>
        </div>
        
        <div style="display:flex; gap:8px; margin-bottom:24px; flex-wrap:wrap;">
            <span class="stat-badge">LP Express: <?php echo $lockerCounts['lpexpress'] ?? 0; ?></span>
            <span class="stat-badge">Omniva: <?php echo $lockerCounts['omniva'] ?? 0; ?></span>
            <span class="stat-badge">DPD: <?php echo $lockerCounts['dpd'] ?? 0; ?></span>
            <span class="stat-badge">Venipak: <?php echo $lockerCounts['venipak'] ?? 0; ?></span>
        </div>

        <div style="background:#f9fafb; padding:16px; border-radius:8px; border:1px dashed #d1d5db; margin-bottom:24px;">
            <h4 style="margin:0 0 12px 0; font-size:14px; text-transform:uppercase; color:#6b7280;">Masinis importas (CSV)</h4>
            <form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:flex-end;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="import_lockers">
                
                <div style="flex:1;">
                    <label style="font-size:12px; display:block; margin-bottom:4px;">Tiekėjas</label>
                    <select name="provider" class="form-control" style="font-size:13px; padding:6px;">
                        <option value="lpexpress">LP Express</option>
                        <option value="omniva">Omniva</option>
                        <option value="dpd">DPD</option>
                        <option value="venipak">Venipak</option>
                    </select>
                </div>
                <div style="flex:2;">
                    <label style="font-size:12px; display:block; margin-bottom:4px;">Failas</label>
                    <input type="file" name="csv_file" accept=".csv" required class="form-control" style="font-size:12px; padding:4px;">
                </div>
                <button type="submit" class="btn secondary" style="font-size:13px; height:34px;">Įkelti</button>
            </form>
            <div style="font-size:11px; color:#999; margin-top:6px;">Stulpeliai: Pavadinimas, Adresas, Pastaba (nebūtina).</div>
        </div>

        <h4 style="margin:0 0 12px 0; font-size:14px; text-transform:uppercase; color:#6b7280;">Pridėti naują paštomatą</h4>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_locker">
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:10px;">
                <div>
                    <label style="font-size:12px;">Tiekėjas</label>
                    <select name="provider" class="form-control">
                        <option value="lpexpress">LP Express</option>
                        <option value="omniva">Omniva</option>
                        <option value="dpd">DPD</option>
                        <option value="venipak">Venipak</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;">Pavadinimas / ID</label>
                    <input type="text" name="title" class="form-control" required placeholder="Pvz. LP1234">
                </div>
            </div>
            
            <div style="margin-bottom:10px;">
                <label style="font-size:12px;">Adresas</label>
                <input type="text" name="address" class="form-control" required placeholder="Gatvė g. 1, Miestas">
            </div>
            
            <div style="margin-bottom:16px;">
                <label style="font-size:12px;">Pastaba (pvz. darbo laikas)</label>
                <input type="text" name="note" class="form-control">
            </div>
            
            <button type="submit" class="btn" style="width:100%;">Pridėti paštomatą</button>
        </form>

        <?php if (!empty($lockersGrouped)): ?>
            <div style="margin-top:24px;">
                <h4 style="margin:0 0 10px 0; font-size:14px;">Paskutiniai pridėti</h4>
                <div class="locker-list-preview">
                    <?php 
                    $count = 0;
                    foreach ($lockersGrouped as $prov => $items):
                        foreach ($items as $l):
                            if($count++ > 20) break 2; // Rodyti tik 20 vnt
                    ?>
                        <div class="locker-item">
                            <div>
                                <span style="font-weight:700; color:#555;"><?php echo strtoupper($prov); ?></span> 
                                <?php echo htmlspecialchars($l['title']); ?>
                                <div style="color:#999; font-size:11px;"><?php echo htmlspecialchars($l['address']); ?></div>
                            </div>
                            <form method="post" onsubmit="return confirm('Ištrinti?');" style="margin:0;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_locker">
                                <input type="hidden" name="id" value="<?php echo $l['id']; ?>">
                                <button type="submit" style="border:none; background:none; color:#ef4444; font-weight:bold; cursor:pointer;">&times;</button>
                            </form>
                        </div>
                    <?php endforeach; endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
