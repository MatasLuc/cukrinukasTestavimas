<?php
require_once __DIR__ . '/env.php';
loadEnvFile();
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/security.php';

// Shared PDO connection helper for MySQL-backed auth pages and store features.
function getPdo(): PDO {
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = requireEnv('DB_HOST');
    $db   = requireEnv('DB_NAME');
    $user = requireEnv('DB_USER');
    $pass = requireEnv('DB_PASS');
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);
    return $pdo;
}

function ensureUploadsDir(): string {
    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    $htaccess = $uploadDir . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "<Files *.php>\n    Require all denied\n</Files>\n\nOptions -ExecCGI\nSetHandler none\n");
    }
    return $uploadDir;
}

function detectMimeType(array $file): string {
    if (empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
        return '';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $mime = finfo_file($finfo, $file['tmp_name']) ?: '';
        finfo_close($finfo);
        return is_string($mime) ? $mime : '';
    }

    $mime = mime_content_type($file['tmp_name']);
    return is_string($mime) ? $mime : '';
}

function saveUploadedFile(array $file, array $allowedMimeMap, string $prefix = 'upload_'): ?string {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $mime = detectMimeType($file);
    if ($mime === '' || !isset($allowedMimeMap[$mime])) {
        return null;
    }

    $uploadDir = ensureUploadsDir();
    $targetName = uniqid($prefix, true) . '.webp';
    $destination = $uploadDir . '/' . $targetName;

    if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
        try {
            $image = null;
            switch ($mime) {
                case 'image/jpeg': $image = @imagecreatefromjpeg($file['tmp_name']); break;
                case 'image/png':  $image = @imagecreatefrompng($file['tmp_name']); break;
                case 'image/gif':  $image = @imagecreatefromgif($file['tmp_name']); break;
                case 'image/webp': $image = @imagecreatefromwebp($file['tmp_name']); break;
            }

            if ($image) {
                $width = imagesx($image);
                $height = imagesy($image);
                $maxWidth = 1200;
                
                if ($width > $maxWidth) {
                    $newWidth = $maxWidth;
                    $newHeight = floor($height * ($maxWidth / $width));
                    $newImage = imagecreatetruecolor($newWidth, $newHeight);
                    imagealphablending($newImage, false);
                    imagesavealpha($newImage, true);
                    imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    imagedestroy($image);
                    $image = $newImage;
                }

                imagewebp($image, $destination, 80);
                imagedestroy($image);
                return '/uploads/' . $targetName;
            }
        } catch (Throwable $e) {
            // Fallback
        }
    }

    $extension = $allowedMimeMap[$mime];
    $targetNameOriginal = uniqid($prefix, true) . '.' . $extension;
    $destinationOriginal = $uploadDir . '/' . $targetNameOriginal;
    
    if (!move_uploaded_file($file['tmp_name'], $destinationOriginal)) {
        return null;
    }

    return '/uploads/' . $targetNameOriginal;
}

function getUnreadDirectMessagesCount(PDO $pdo, int $userId): int {
    ensureDirectMessages($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM direct_messages WHERE recipient_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function markDirectMessagesRead(PDO $pdo, int $userId, int $partnerId): void {
    ensureDirectMessages($pdo);
    $stmt = $pdo->prepare('UPDATE direct_messages SET read_at = NOW() WHERE recipient_id = ? AND sender_id = ? AND read_at IS NULL');
    $stmt->execute([$userId, $partnerId]);
}

function isCommunityBlocked(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM community_blocks WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (empty($row['banned_until'])) {
        return $row;
    }
    $until = strtotime($row['banned_until']);
    return ($until && $until > time()) ? $row : null;
}

function getSiteContent(PDO $pdo): array {
    ensureSiteContentTable($pdo);
    $rows = $pdo->query('SELECT `key`, `value` FROM site_content')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows;
}

function saveSiteContent(PDO $pdo, array $data): void {
    ensureSiteContentTable($pdo);
    $stmt = $pdo->prepare('REPLACE INTO site_content (`key`, `value`) VALUES (?, ?)');
    foreach ($data as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function getFooterLinks(PDO $pdo): array {
    ensureFooterLinksTable($pdo);
    $rows = $pdo->query('SELECT id, label, url, section, sort_order FROM footer_links ORDER BY sort_order ASC, id ASC')->fetchAll();
    $grouped = ['quick' => [], 'help' => []];
    foreach ($rows as $row) {
        $section = $row['section'];
        if (!isset($grouped[$section])) {
            $grouped[$section] = [];
        }
        $grouped[$section][] = $row;
    }
    return $grouped;
}

function saveFooterLink(PDO $pdo, ?int $id, string $label, string $url, string $section, int $sortOrder): void {
    ensureFooterLinksTable($pdo);
    $section = $section === 'help' ? 'help' : 'quick';
    if ($id) {
        $stmt = $pdo->prepare('UPDATE footer_links SET label = ?, url = ?, section = ?, sort_order = ? WHERE id = ?');
        $stmt->execute([$label, $url, $section, $sortOrder, $id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO footer_links (label, url, section, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->execute([$label, $url, $section, $sortOrder]);
    }
}

function deleteFooterLink(PDO $pdo, int $id): void {
    ensureFooterLinksTable($pdo);
    $stmt = $pdo->prepare('DELETE FROM footer_links WHERE id = ?');
    $stmt->execute([$id]);
}

function getShippingSettings(PDO $pdo): array {
    ensureShippingSettings($pdo);
    $row = $pdo->query('SELECT base_price, courier_price, locker_price, free_over FROM shipping_settings WHERE id = 1')->fetch();
    return $row ?: ['base_price' => 3.99, 'courier_price' => 3.99, 'locker_price' => 2.49, 'free_over' => null];
}

function saveShippingSettings(PDO $pdo, float $base, float $courier, float $locker, ?float $freeOver): void {
    ensureShippingSettings($pdo);
    $stmt = $pdo->prepare('REPLACE INTO shipping_settings (id, base_price, courier_price, locker_price, free_over) VALUES (1, ?, ?, ?, ?)');
    $stmt->execute([$base, $courier, $locker, $freeOver]);
}

function saveParcelLocker(PDO $pdo, string $provider, string $title, string $address, ?string $note = null): void {
    ensureLockerTables($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO parcel_lockers (provider, title, address, note) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE title = VALUES(title), address = VALUES(address), note = VALUES(note)'
    );
    $stmt->execute([$provider, $title, $address, $note]);
}

function updateParcelLocker(PDO $pdo, int $id, string $provider, string $title, string $address, ?string $note = null): void {
    ensureLockerTables($pdo);
    $stmt = $pdo->prepare('UPDATE parcel_lockers SET provider = ?, title = ?, address = ?, note = ? WHERE id = ?');
    $stmt->execute([$provider, $title, $address, $note, $id]);
}

function bulkSaveParcelLockers(PDO $pdo, string $provider, array $lockers): void {
    ensureLockerTables($pdo);
    if (!$lockers) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO parcel_lockers (provider, title, address, note) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), address = VALUES(address), note = VALUES(note)'
        );
        foreach ($lockers as $locker) {
            $stmt->execute([
                $provider,
                $locker['title'],
                $locker['address'],
                $locker['note'] ?? null,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function getLockerNetworks(PDO $pdo): array {
    ensureLockerTables($pdo);
    $stmt = $pdo->query('SELECT id, provider, title, address, note FROM parcel_lockers ORDER BY provider, title');
    $rows = $stmt->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['provider']][] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'address' => $row['address'],
            'note' => $row['note'],
        ];
    }
    return $grouped;
}

function getLockerById(PDO $pdo, int $id): ?array {
    ensureLockerTables($pdo);
    $stmt = $pdo->prepare('SELECT id, provider, title, address, note FROM parcel_lockers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? [
        'id' => (int)$row['id'],
        'provider' => $row['provider'],
        'title' => $row['title'],
        'address' => $row['address'],
        'note' => $row['note'],
    ] : null;
}

function getFreeShippingProductIds(PDO $pdo): array {
    ensureFreeShippingProducts($pdo);
    $rows = $pdo->query('SELECT product_id FROM shipping_free_products ORDER BY position ASC LIMIT 4')->fetchAll();
    return array_map('intval', array_column($rows, 'product_id'));
}

function getFreeShippingProducts(PDO $pdo): array {
    ensureFreeShippingProducts($pdo);
    $stmt = $pdo->query(
        'SELECT s.product_id, s.position, p.title, p.price, p.sale_price, p.image_url, p.category_id,
                (SELECT path FROM product_images WHERE product_id = p.id AND is_primary = 1 ORDER BY id DESC LIMIT 1) AS primary_image
         FROM shipping_free_products s
         JOIN products p ON p.id = s.product_id
         ORDER BY s.position ASC
         LIMIT 4'
    );
    return $stmt->fetchAll();
}

function saveFreeShippingProducts(PDO $pdo, array $productIds): void {
    ensureFreeShippingProducts($pdo);
    $clean = [];
    foreach ($productIds as $pid) {
        $id = (int)$pid;
        if ($id > 0 && !in_array($id, $clean, true)) {
            $clean[] = $id;
        }
        if (count($clean) >= 4) {
            break;
        }
    }

    $pdo->exec('DELETE FROM shipping_free_products');
    if (!$clean) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO shipping_free_products (product_id, position) VALUES (?, ?)');
    $pos = 1;
    foreach ($clean as $pid) {
        $stmt->execute([$pid, $pos]);
        $pos++;
    }
}

function saveItemForUser(PDO $pdo, int $userId, string $type, int $itemId): void {
    ensureSavedContentTables($pdo);
    $stmt = $pdo->prepare('INSERT IGNORE INTO saved_items (user_id, item_type, item_id) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $type, $itemId]);
}

function removeSavedItem(PDO $pdo, int $userId, string $type, int $itemId): void {
    ensureSavedContentTables($pdo);
    $stmt = $pdo->prepare('DELETE FROM saved_items WHERE user_id = ? AND item_type = ? AND item_id = ?');
    $stmt->execute([$userId, $type, $itemId]);
}

function getSavedItems(PDO $pdo, int $userId): array {
    ensureSavedContentTables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM saved_items WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getCartData(PDO $pdo, array $cartSession, array $variationSelections = []): array {
    $items = [];
    $baseTotal = 0;
    $finalTotal = 0;
    $globalAmount = 0;
    $categoryAmount = 0;
    $count = 0;
    $freeShippingIds = getFreeShippingProductIds($pdo);

    if (!$cartSession) {
        return [
            'items' => $items,
            'total' => $finalTotal,
            'count' => $count,
            'base_total' => $baseTotal,
            'global_amount' => $globalAmount,
            'category_amount' => $categoryAmount,
            'global_discount' => getGlobalDiscount($pdo),
            'category_discounts' => getCategoryDiscounts($pdo),
            'free_shipping_ids' => $freeShippingIds,
        ];
    }

    $productIdsToFetch = [];
    foreach (array_keys($cartSession) as $key) {
        $parts = explode('_', $key);
        $pid = (int)$parts[0];
        if ($pid > 0) {
            $productIdsToFetch[$pid] = true;
        }
    }

    $fetchedProducts = [];
    if (!empty($productIdsToFetch)) {
        $placeholders = implode(',', array_fill(0, count($productIdsToFetch), '?'));
        $stmt = $pdo->prepare("SELECT id, title, price, sale_price, image_url, category_id FROM products WHERE id IN ($placeholders)");
        $stmt->execute(array_keys($productIdsToFetch));
        while ($row = $stmt->fetch()) {
            $fetchedProducts[$row['id']] = $row;
        }
    }

    $globalDiscount = getGlobalDiscount($pdo);
    $categoryDiscounts = getCategoryDiscounts($pdo);

    foreach ($cartSession as $key => $qty) {
        $parts = explode('_', $key);
        $pid = (int)$parts[0];
        
        if (!isset($fetchedProducts[$pid])) continue;
        $product = $fetchedProducts[$pid];
        $qty = (int)$qty;
        
        $currentVariations = $variationSelections[$key] ?? [];
        if (!empty($currentVariations) && !isset($currentVariations[0])) {
            $currentVariations = [$currentVariations];
        }
        
        $variationDelta = 0;
        foreach ($currentVariations as $cv) {
            $variationDelta += (float)($cv['delta'] ?? 0);
        }
        
        $basePrice = (float)$product['price'] + $variationDelta;
        $salePrice = ($product['sale_price'] !== null) ? ((float)$product['sale_price'] + $variationDelta) : null;
        
        $baseUnit = ($salePrice !== null) ? $salePrice : $basePrice;
        $baseOriginal = $basePrice;
        
        $afterGlobal = applyGlobalDiscount($baseUnit, $globalDiscount);
        $catDiscount = $categoryDiscounts[$product['category_id']] ?? null;
        $finalUnit = applyCategoryDiscount($afterGlobal, $catDiscount);

        $baseLine = $qty * $baseOriginal;
        $finalLine = $qty * $finalUnit;

        $baseTotal += $baseLine;
        $finalTotal += $finalLine;
        $globalAmount += ($baseUnit - $afterGlobal) * $qty;
        $categoryAmount += ($afterGlobal - $finalUnit) * $qty;
        $count += $qty;
        
        $items[] = [
            'id' => $pid,
            'cart_key' => $key,
            'title' => $product['title'],
            'price' => $finalUnit,
            'original_unit' => $baseOriginal,
            'image_url' => $product['image_url'],
            'quantity' => $qty,
            'line_total' => $finalLine,
            'line_base' => $baseLine,
            'category_id' => $product['category_id'],
            'variation' => $currentVariations,
            'variation_features' => $currentVariations,
            'free_shipping_gift' => in_array((int)$pid, $freeShippingIds, true),
        ];
    }

    return [
        'items' => $items,
        'total' => $finalTotal,
        'count' => $count,
        'base_total' => $baseTotal,
        'global_amount' => $globalAmount,
        'category_amount' => $categoryAmount,
        'global_discount' => $globalDiscount,
        'category_discounts' => $categoryDiscounts,
        'free_shipping_ids' => $freeShippingIds,
    ];
}

function getNavigationTree(PDO $pdo): array {
    ensureNavigationTable($pdo);
    $rows = $pdo->query('SELECT id, label, url, parent_id FROM navigation_items ORDER BY sort_order ASC, id ASC')->fetchAll();
    $children = [];
    foreach ($rows as $row) {
        $row['children'] = [];
        $parentKey = $row['parent_id'] ?? 0;
        if (!isset($children[$parentKey])) {
            $children[$parentKey] = [];
        }
        $children[$parentKey][] = $row;
    }

    $build = function($parentId) use (&$build, &$children): array {
        $branch = [];
        foreach ($children[$parentId] ?? [] as $item) {
            $item['children'] = $build($item['id']);
            $branch[] = $item;
        }
        return $branch;
    };

    return $build(0);
}

function getAllDiscountCodes(PDO $pdo): array {
    ensureDiscountTables($pdo);
    $stmt = $pdo->query('SELECT * FROM discount_codes ORDER BY created_at DESC');
    return $stmt->fetchAll();
}

function getCategoryDiscounts(PDO $pdo): array {
    ensureCategoryDiscounts($pdo);
    $stmt = $pdo->query('SELECT * FROM category_discounts WHERE active = 1');
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        if (($row['type'] ?? '') === 'free_shipping') {
            $row['free_shipping'] = 1;
            $row['value'] = 0;
        }
        $map[(int)$row['category_id']] = $row;
    }
    return $map;
}

function saveCategoryDiscount(PDO $pdo, int $categoryId, string $type, float $value, bool $freeShipping, bool $active): void {
    ensureCategoryDiscounts($pdo);
    $allowedType = in_array($type, ['none', 'percent', 'amount', 'free_shipping'], true) ? $type : 'none';
    $val = $allowedType === 'free_shipping' ? 0 : max(0, $value);
    $free = ($allowedType === 'free_shipping' || $freeShipping) ? 1 : 0;
    $activeFlag = $active ? 1 : 0;
    $stmt = $pdo->prepare('REPLACE INTO category_discounts (category_id, type, value, free_shipping, active) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$categoryId, $allowedType, $val, $free, $activeFlag]);
}

function deleteCategoryDiscount(PDO $pdo, int $categoryId): void {
    ensureCategoryDiscounts($pdo);
    $stmt = $pdo->prepare('DELETE FROM category_discounts WHERE category_id = ?');
    $stmt->execute([$categoryId]);
}

function saveDiscountCodeEntry(PDO $pdo, ?int $id, string $code, string $type, float $value, int $usageLimit, bool $active, bool $freeShipping = false): void {
    ensureDiscountTables($pdo);
    $allowedType = in_array($type, ['percent', 'amount', 'free_shipping'], true) ? $type : 'percent';
    $code = trim($code);
    $value = $allowedType === 'free_shipping' ? 0 : max(0, $value);
    $usageLimit = max(0, $usageLimit);
    $activeFlag = $active ? 1 : 0;
    $free = ($allowedType === 'free_shipping' || $freeShipping) ? 1 : 0;

    if ($id) {
        $stmt = $pdo->prepare('UPDATE discount_codes SET code = ?, type = ?, value = ?, usage_limit = ?, free_shipping = ?, active = ? WHERE id = ?');
        $stmt->execute([$code, $allowedType, $value, $usageLimit, $free, $activeFlag, $id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO discount_codes (code, type, value, usage_limit, free_shipping, active) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$code, $allowedType, $value, $usageLimit, $free, $activeFlag]);
    }
}

function deleteDiscountCode(PDO $pdo, int $id): void {
    ensureDiscountTables($pdo);
    $stmt = $pdo->prepare('DELETE FROM discount_codes WHERE id = ?');
    $stmt->execute([$id]);
}

function getFeaturedProductIds(PDO $pdo): array {
    ensureFeaturedProductsTable($pdo);
    $rows = $pdo->query('SELECT product_id FROM featured_products ORDER BY position ASC LIMIT 3')->fetchAll();
    return array_map(fn($r) => (int)$r['product_id'], $rows);
}

function saveFeaturedProductIds(PDO $pdo, array $productIds): void {
    ensureFeaturedProductsTable($pdo);
    $pdo->exec('TRUNCATE TABLE featured_products');
    $stmt = $pdo->prepare('INSERT INTO featured_products (product_id, position) VALUES (?, ?)');
    $pos = 1;
    foreach ($productIds as $pid) {
        if ($pos > 3) { break; }
        $stmt->execute([(int)$pid, $pos]);
        $pos++;
    }
}

function saveCartItem(PDO $pdo, int $userId, int $productId, int $qty): void {
    ensureCartTables($pdo);
    $stmt = $pdo->prepare('INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)');
    $stmt->execute([$userId, $productId, $qty]);
}

function deleteCartItem(PDO $pdo, int $userId, int $productId): void {
    ensureCartTables($pdo);
    $stmt = $pdo->prepare('DELETE FROM cart_items WHERE user_id = ? AND product_id = ?');
    $stmt->execute([$userId, $productId]);
}

function clearUserCart(PDO $pdo, int $userId): void {
    ensureCartTables($pdo);
    $stmt = $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?');
    $stmt->execute([$userId]);
}

function getGlobalDiscount(PDO $pdo): array {
    ensureDiscountTables($pdo);
    $row = $pdo->query('SELECT type, value, free_shipping FROM discount_settings WHERE id = 1')->fetch();
    if ($row && ($row['type'] ?? '') === 'free_shipping') {
        $row['free_shipping'] = 1;
        $row['value'] = 0;
    }
    return $row ?: ['type' => 'none', 'value' => 0, 'free_shipping' => 0];
}

function applyGlobalDiscount(float $amount, array $globalDiscount): float {
    $amount = max(0, $amount);
    $type = $globalDiscount['type'] ?? 'none';
    $value = (float)($globalDiscount['value'] ?? 0);

    if ($type === 'percent' && $value > 0) {
        return max(0, $amount - ($amount * ($value / 100)));
    }

    if ($type === 'amount' && $value > 0) {
        return max(0, $amount - $value);
    }

    return $amount;
}

function applyCategoryDiscount(float $amount, ?array $categoryDiscount): float {
    if (!$categoryDiscount) {
        return max(0, $amount);
    }
    $amount = max(0, $amount);
    $type = $categoryDiscount['type'] ?? 'none';
    $value = (float)($categoryDiscount['value'] ?? 0);

    if ($type === 'percent' && $value > 0) {
        return max(0, $amount - ($amount * ($value / 100)));
    }

    if ($type === 'amount' && $value > 0) {
        return max(0, $amount - $value);
    }

    return $amount;
}

function buildPriceDisplay(array $product, array $globalDiscount, array $categoryDiscounts = []): array {
    $baseOriginal = (float)($product['price'] ?? 0);
    $baseEffective = $product['sale_price'] !== null ? (float)$product['sale_price'] : $baseOriginal;
    $afterGlobal = applyGlobalDiscount($baseEffective, $globalDiscount);
    $catDiscount = null;
    if (isset($product['category_id']) && $product['category_id']) {
        $catDiscount = $categoryDiscounts[(int)$product['category_id']] ?? null;
    }
    $final = applyCategoryDiscount($afterGlobal, $catDiscount);

    $hasGlobal = ($globalDiscount['type'] ?? 'none') !== 'none' && ($globalDiscount['value'] ?? 0) > 0;
    $hasCategory = $catDiscount && (($catDiscount['type'] ?? 'none') !== 'none') && (($catDiscount['value'] ?? 0) > 0);
    $hasSale = $product['sale_price'] !== null;

    $hasDiscount = $hasSale || $hasGlobal || $hasCategory;
    $originalToShow = $hasDiscount ? $baseOriginal : $final;

    return [
        'current' => $final,
        'original' => $originalToShow,
        'has_discount' => $hasDiscount && $final < $originalToShow,
    ];
}

function saveGlobalDiscount(PDO $pdo, string $type, float $value, bool $freeShipping = false): void {
    ensureDiscountTables($pdo);
    $allowed = in_array($type, ['none', 'percent', 'amount', 'free_shipping'], true) ? $type : 'none';
    $val = $allowed === 'free_shipping' ? 0 : max(0, $value);
    $freeFlag = $allowed === 'free_shipping' ? 1 : ($freeShipping ? 1 : 0);
    $stmt = $pdo->prepare('REPLACE INTO discount_settings (id, type, value, free_shipping) VALUES (1, ?, ?, ?)');
    $stmt->execute([$allowed, $val, $freeFlag]);
}

function findDiscountCode(PDO $pdo, string $code): ?array {
    ensureDiscountTables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM discount_codes WHERE code = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row || !(int)$row['active']) {
        return null;
    }
    if ((int)$row['usage_limit'] > 0 && (int)$row['used_count'] >= (int)$row['usage_limit']) {
        return null;
    }
    return $row;
}

function incrementDiscountUsage(PDO $pdo, string $code): void {
    $stmt = $pdo->prepare('UPDATE discount_codes SET used_count = used_count + 1 WHERE code = ?');
    $stmt->execute([$code]);
}

function getUserCartSnapshot(PDO $pdo, int $userId): array {
    ensureCartTables($pdo);
    $stmt = $pdo->prepare('SELECT c.product_id, c.quantity, p.title, p.price, p.image_url FROM cart_items c JOIN products p ON p.id = c.product_id WHERE c.user_id = ? ORDER BY c.updated_at DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getRecipeRatingStats(PDO $pdo, int $recipeId): array {
    ensureRecipeRatingsTable($pdo);
    $stmt = $pdo->prepare('SELECT AVG(rating) as average, COUNT(*) as count FROM recipe_ratings WHERE recipe_id = ?');
    $stmt->execute([$recipeId]);
    $stats = $stmt->fetch();
    
    return [
        'average' => $stats['average'] ? round((float)$stats['average'], 1) : 0,
        'count' => (int)$stats['count']
    ];
}

function getUserRecipeRating(PDO $pdo, int $userId, int $recipeId): int {
    ensureRecipeRatingsTable($pdo);
    $stmt = $pdo->prepare('SELECT rating FROM recipe_ratings WHERE user_id = ? AND recipe_id = ?');
    $stmt->execute([$userId, $recipeId]);
    return (int)$stmt->fetchColumn();
}

function rateRecipe(PDO $pdo, int $userId, int $recipeId, int $rating): void {
    ensureRecipeRatingsTable($pdo);
    $rating = max(1, min(5, $rating));
    
    $stmt = $pdo->prepare('
        INSERT INTO recipe_ratings (recipe_id, user_id, rating) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE rating = VALUES(rating), created_at = NOW()
    ');
    $stmt->execute([$recipeId, $userId, $rating]);
}

// --- LOGIN/AUTH SISTEMOS FUNKCIJOS ---
// Apgaubtos if (!function_exists(...)), kad būtų išvengta "Fatal error: Cannot redeclare function"

/**
 * Sukuria saugų prisijungimo tokeną ir įrašo į DB.
 * Nustato slapuką 'remember_me' 30 dienų.
 */
if (!function_exists('setRememberMe')) {
    function setRememberMe(PDO $pdo, int $userId): void {
        ensureUsersTable($pdo);
        // 1. Sukuriame tokeną
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        // Galioja 30 dienų
        $expires = date('Y-m-d H:i:s', time() + 30 * 24 * 3600);

        // 2. Įrašome į DB
        $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, remember_expires = ? WHERE id = ?");
        try {
            $stmt->execute([$hash, $expires, $userId]);
        } catch (PDOException $e) {
            // Jei nepavyko (pvz., nėra stulpelio), bandome pridėti stulpelį ir kartojame
            $pdo->exec("ALTER TABLE users ADD COLUMN remember_expires DATETIME NULL AFTER remember_token");
            $stmt->execute([$hash, $expires, $userId]);
        }

        // 3. Nustatome HTTP slapuką
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('remember_me', $userId . ':' . $token, time() + 30 * 24 * 3600, '/', '', $secure, true);
    }
}

/**
 * Bandymas prisijungti automatiškai, jei yra 'remember_me' slapukas.
 */
if (!function_exists('tryAutoLogin')) {
    function tryAutoLogin(PDO $pdo): void {
        if (isset($_SESSION['user_id']) || !isset($_COOKIE['remember_me'])) {
            return;
        }
        
        list($userId, $token) = explode(':', $_COOKIE['remember_me'], 2) + [null, null];
        
        if (!$userId || !$token) {
            return;
        }

        try {
            ensureUsersTable($pdo);
            $stmt = $pdo->prepare("SELECT id, name, is_admin, remember_token, remember_expires FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if ($user && $user['remember_token'] && isset($user['remember_expires'])) {
                if (hash('sha256', $token) === $user['remember_token'] && strtotime($user['remember_expires']) > time()) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['is_admin'] = (int)$user['is_admin'];
                }
            }
        } catch (Exception $e) {
            // Ignoruojame klaidą
        }
    }
}

/**
 * Išvalo 'remember_me' duomenis atsijungiant.
 */
if (!function_exists('clearRememberMe')) {
    function clearRememberMe(PDO $pdo): void {
        if (isset($_SESSION['user_id'])) {
            try {
                ensureUsersTable($pdo);
                $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL, remember_expires = NULL WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
            } catch (Exception $e) {}
        }
        setcookie('remember_me', '', time() - 3600, '/');
    }
}

// BACKWARD COMPATIBILITY
// Prijungiame setup.php, kad visos "ensure..." funkcijos būtų pasiekiamos.
require_once __DIR__ . '/setup.php';
?>
