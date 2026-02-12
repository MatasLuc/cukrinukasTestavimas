<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: /login.php');
    exit;
}

$pdo = getPdo();
ensureUsersTable($pdo);
ensureCategoriesTable($pdo);
ensureProductsTable($pdo);
ensureOrdersTables($pdo);
ensureCartTables($pdo);
ensureAdminAccount($pdo);
ensureFeaturedProductsTable($pdo);
ensureNavigationTable($pdo);
ensureFooterLinksTable($pdo);
ensureNewsTable($pdo);
ensureRecipesTable($pdo);
ensureDiscountTables($pdo);
ensureCategoryDiscounts($pdo);
ensureShippingSettings($pdo);
ensureLockerTables($pdo);
ensureCommunityTables($pdo);

$messages = [];
$errors = [];
$view = $_GET['view'] ?? 'dashboard';

function setPrimaryImage(PDO $pdo, int $productId, int $imageId): void {
    $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?')->execute([$imageId, $productId]);
    $path = $pdo->prepare('SELECT path FROM product_images WHERE id = ? AND product_id = ?');
    $path->execute([$imageId, $productId]);
    $file = $path->fetchColumn();
    if ($file) {
        $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute([$file, $productId]);
    }
}

function deleteProductImage(PDO $pdo, int $productId, int $imageId): void {
    $stmt = $pdo->prepare('SELECT path, is_primary FROM product_images WHERE id = ? AND product_id = ?');
    $stmt->execute([$imageId, $productId]);
    $image = $stmt->fetch();
    if (!$image) {
        return;
    }
    $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
    $filePath = __DIR__ . '/' . ltrim($image['path'], '/');
    if (is_file($filePath)) {
        @unlink($filePath);
    }
    if ((int)$image['is_primary'] === 1) {
        $fallback = $pdo->prepare('SELECT id, path FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id DESC LIMIT 1');
        $fallback->execute([$productId]);
        $newMain = $fallback->fetch();
        if ($newMain) {
            $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?')->execute([$newMain['id']]);
            $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute([$newMain['path'], $productId]);
        } else {
            $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute(['https://placehold.co/600x400?text=Preke', $productId]);
        }
    }
}

function storeUploads(PDO $pdo, int $productId, array $files): void {
    if (empty($files['name'][0])) {
        return;
    }
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_primary = 1');
    $countStmt->execute([$productId]);
    $hasPrimary = (int)$countStmt->fetchColumn();

    $allowedMimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $file = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i] ?? 0,
        ];

        $relativePath = saveUploadedFile($file, $allowedMimeMap, 'img_');
        if (!$relativePath) {
            continue;
        }

        $isPrimary = ($hasPrimary === 0 && $i === 0) ? 1 : 0;
        $stmt = $pdo->prepare('INSERT INTO product_images (product_id, path, is_primary) VALUES (?, ?, ?)');
        $stmt->execute([$productId, $relativePath, $isPrimary]);
        if ($isPrimary) {
            $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute([$relativePath, $productId]);
            $hasPrimary = 1;
        }
    }
}

function readXlsxRows(string $filePath): array {
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return [];
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();

    if ($sheetXml === false) {
        return [];
    }

    $sharedStrings = [];
    if ($sharedXml !== false) {
        $shared = simplexml_load_string($sharedXml);
        if ($shared && isset($shared->si)) {
            foreach ($shared->si as $si) {
                $sharedStrings[] = (string)$si->t;
            }
        }
    }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) {
        return [];
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            $type = (string)$cell['t'];
            $value = (string)$cell->v;
            if ($type === 's') {
                $value = $sharedStrings[(int)$value] ?? $value;
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $value = (string)$cell->is->t;
            }
            $cells[] = trim((string)$value);
        }
        if ($cells) {
            $rows[] = $cells;
        }
    }

    return $rows;
}

function parseLockerFile(string $provider, string $filePath): array {
    $rows = readXlsxRows($filePath);
    if (!$rows) {
        return [];
    }

    $parsed = [];
    foreach ($rows as $index => $row) {
        // Skip header row if it contains the column names.
        $maybeTitle = strtolower((string)($row[1] ?? ''));
        if ($index === 0 && (str_contains($maybeTitle, 'pavadinimas') || str_contains($maybeTitle, 'name'))) {
            continue;
        }

        if ($provider === 'omniva') {
            $title = trim($row[1] ?? '');
            $city = trim($row[5] ?? '');
            $street = trim($row[6] ?? '');
            $house = trim($row[7] ?? '');
            $note = trim($row[10] ?? '');
            if ($title === '') {
                continue;
            }
            $addressParts = [];
            if ($city !== '') {
                $addressParts[] = $city;
            }
            $streetLine = trim($street . ($house !== '' ? ' ' . $house : ''));
            if ($streetLine !== '') {
                $addressParts[] = $streetLine;
            }
            $address = implode(', ', $addressParts);
            $parsed[] = [
                'title' => $title,
                'address' => $address ?: ($row[0] ?? $title),
                'note' => $note ?: null,
            ];
        } elseif ($provider === 'lpexpress') {
            $title = trim($row[2] ?? '');
            $city = trim($row[0] ?? '');
            $addressLine = trim($row[3] ?? '');
            $postcode = trim($row[4] ?? '');
            $note = trim($row[7] ?? '');
            if ($title === '') {
                continue;
            }
            $addressParts = array_filter([$city, $addressLine, $postcode]);
            $address = implode(', ', $addressParts);
            $parsed[] = [
                'title' => $title,
                'address' => $address ?: $addressLine,
                'note' => $note ?: null,
            ];
        }
    }

    return $parsed;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'save_global_discount') {
        $type = $_POST['discount_type'] ?? 'none';
        $value = (float)($_POST['discount_value'] ?? 0);
        saveGlobalDiscount($pdo, $type, $value, $type === 'free_shipping');
        $messages[] = 'Bendra nuolaida išsaugota';
        $view = 'discounts';
    }

    if ($action === 'save_discount_code') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $code = trim($_POST['code'] ?? '');
        $type = $_POST['type'] ?? 'percent';
        $value = (float)($_POST['value'] ?? 0);
        $usageLimit = (int)($_POST['usage_limit'] ?? 0);
        $active = isset($_POST['active']);
        if ($code === '') {
            $errors[] = 'Įveskite nuolaidos kodą.';
        } else {
            $freeShipping = ($type === 'free_shipping');
            saveDiscountCodeEntry($pdo, $id ?: null, strtoupper($code), $type, $value, $usageLimit, $active, $freeShipping);
            $messages[] = $id ? 'Nuolaidos kodas atnaujintas' : 'Nuolaidos kodas sukurtas';
        }
        $view = 'discounts';
    }

    if ($action === 'save_category_discount') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $type = $_POST['category_type'] ?? 'none';
        $value = (float)($_POST['category_value'] ?? 0);
        $freeShipping = ($type === 'free_shipping');
        $active = isset($_POST['category_active']);
        if ($categoryId > 0) {
            saveCategoryDiscount($pdo, $categoryId, $type, $value, $freeShipping, $active);
            $messages[] = 'Kategorijos nuolaida išsaugota';
        } else {
            $errors[] = 'Pasirinkite kategoriją.';
        }
        $view = 'discounts';
    }

    if ($action === 'delete_category_discount') {
        $catId = (int)($_POST['category_id'] ?? 0);
        if ($catId) {
            deleteCategoryDiscount($pdo, $catId);
            $messages[] = 'Kategorijos nuolaida pašalinta';
        }
        $view = 'discounts';
    }

    if ($action === 'delete_discount_code') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            deleteDiscountCode($pdo, $id);
            $messages[] = 'Nuolaidos kodas pašalintas';
        }
        $view = 'discounts';
    }
    if ($action === 'new_category') {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        if ($name && $slug) {
            $stmt = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)');
            $stmt->execute([$name, $slug]);
            $messages[] = 'Kategorija pridėta';
        } else {
            $errors[] = 'Įveskite kategorijos pavadinimą ir nuorodą.';
        }
        $view = 'categories';
    }

    if ($action === 'edit_category') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        if ($id && $name && $slug) {
            $stmt = $pdo->prepare('UPDATE categories SET name = ?, slug = ? WHERE id = ?');
            $stmt->execute([$name, $slug, $id]);
            $messages[] = 'Kategorija atnaujinta';
        }
        $view = 'categories';
    }

    if ($action === 'delete_category') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            $messages[] = 'Kategorija ištrinta';
        }
        $view = 'categories';
    }

    if ($action === 'new_thread_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $stmt = $pdo->prepare('INSERT INTO community_thread_categories (name) VALUES (?)');
            $stmt->execute([$name]);
            $messages[] = 'Diskusijų kategorija pridėta';
        } else {
            $errors[] = 'Įveskite kategorijos pavadinimą.';
        }
        $view = 'community';
    }

    if ($action === 'delete_thread_category') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM community_thread_categories WHERE id = ?')->execute([$id]);
            $messages[] = 'Diskusijų kategorija ištrinta';
        }
        $view = 'community';
    }

    if ($action === 'new_listing_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $stmt = $pdo->prepare('INSERT INTO community_listing_categories (name) VALUES (?)');
            $stmt->execute([$name]);
            $messages[] = 'Turgus kategorija pridėta';
        } else {
            $errors[] = 'Įveskite kategorijos pavadinimą.';
        }
        $view = 'community';
    }

    if ($action === 'delete_listing_category') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM community_listing_categories WHERE id = ?')->execute([$id]);
            $messages[] = 'Turgus kategorija ištrinta';
        }
        $view = 'community';
    }

    if ($action === 'new_product') {
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $ribbon = trim($_POST['ribbon_text'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $salePrice = isset($_POST['sale_price']) && $_POST['sale_price'] !== '' ? (float)$_POST['sale_price'] : null;
        $qty = (int)($_POST['quantity'] ?? 0);
        $catId = (int)($_POST['category_id'] ?? 0);
        if ($title && $description) {
            $pdo->prepare('INSERT INTO products (category_id, title, subtitle, description, ribbon_text, image_url, price, sale_price, quantity, meta_tags) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $catId ?: null,
                    $title,
                    $subtitle ?: null,
                    $description,
                    $ribbon ?: null,
                    'https://placehold.co/600x400?text=Preke',
                    $price,
                    $salePrice,
                    $qty,
                    trim($_POST['meta_tags'] ?? '') ?: null,
                ]);
            $productId = (int)$pdo->lastInsertId();
            storeUploads($pdo, $productId, $_FILES['images'] ?? []);

            // Related products
            $related = array_filter(array_map('intval', $_POST['related_products'] ?? []));
            if ($related) {
                $insertRel = $pdo->prepare('INSERT IGNORE INTO product_related (product_id, related_product_id) VALUES (?, ?)');
                foreach ($related as $rel) {
                    if ($rel !== $productId) {
                        $insertRel->execute([$productId, $rel]);
                    }
                }
            }

            // Custom attributes
            $attrNames = $_POST['attr_label'] ?? [];
            $attrValues = $_POST['attr_value'] ?? [];
            if ($attrNames) {
                $insertAttr = $pdo->prepare('INSERT INTO product_attributes (product_id, label, value) VALUES (?, ?, ?)');
                foreach ($attrNames as $idx => $label) {
                    $label = trim($label);
                    $val = trim($attrValues[$idx] ?? '');
                    if ($label && $val) {
                        $insertAttr->execute([$productId, $label, $val]);
                    }
                }
            }

            // Variations
            $varNames = $_POST['variation_name'] ?? [];
            $varPrices = $_POST['variation_price'] ?? [];
            if ($varNames) {
                $insertVar = $pdo->prepare('INSERT INTO product_variations (product_id, name, price_delta) VALUES (?, ?, ?)');
                foreach ($varNames as $idx => $vName) {
                    $vName = trim($vName);
                    $delta = isset($varPrices[$idx]) ? (float)$varPrices[$idx] : 0;
                    if ($vName !== '') {
                        $insertVar->execute([$productId, $vName, $delta]);
                    }
                }
            }
            $messages[] = 'Prekė sukurta';
        } else {
            $errors[] = 'Užpildykite prekės pavadinimą ir aprašymą.';
        }
        $view = 'products';
    }

    if ($action === 'featured_add') {
        $query = trim($_POST['featured_query'] ?? '');
        $current = getFeaturedProductIds($pdo);
        if (count($current) >= 3) {
            $errors[] = 'Jau parinktos 3 prekės. Panaikinkite vieną, kad pridėtumėte kitą.';
        } elseif ($query !== '') {
            $stmt = $pdo->prepare('SELECT id, title FROM products WHERE title LIKE ? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute(['%' . $query . '%']);
            $product = $stmt->fetch();
            if ($product) {
                if (!in_array((int)$product['id'], $current, true)) {
                    $current[] = (int)$product['id'];
                    saveFeaturedProductIds($pdo, $current);
                    $messages[] = 'Prekė „' . $product['title'] . '“ pridėta prie pagrindinių.';
                } else {
                    $errors[] = 'Ši prekė jau pažymėta.';
                }
            } else {
                $errors[] = 'Prekė pagal įvestą tekstą nerasta.';
            }
        } else {
            $errors[] = 'Įveskite prekės pavadinimą ar jo dalį.';
        }
        $view = 'products';
    }

    if ($action === 'featured_remove') {
        $removeId = (int)($_POST['remove_id'] ?? 0);
        $current = array_filter(getFeaturedProductIds($pdo), fn($id) => $id !== $removeId);
        saveFeaturedProductIds($pdo, $current);
        $messages[] = 'Prekė nuimta nuo pagrindinių.';
        $view = 'products';
    }

    if ($action === 'toggle_admin') {
        $userId = (int)$_POST['user_id'];
        $pdo->prepare('UPDATE users SET is_admin = IF(is_admin=1,0,1) WHERE id = ?')->execute([$userId]);
        $messages[] = 'Vartotojo teisės atnaujintos';
        $view = 'users';
    }

    if ($action === 'edit_user') {
        $userId = (int)$_POST['user_id'];
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($userId && $name && $email) {
            $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $userId]);
            $messages[] = 'Vartotojas atnaujintas';
        }
        $view = 'users';
    }

    if ($action === 'order_status') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $allowed = ["laukiama","apdorojama","išsiųsta","įvykdyta","atšaukta"];
        if ($orderId && in_array($status, $allowed, true)) {
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
            $messages[] = 'Užsakymo būsena atnaujinta';
        }
        $view = 'orders';
    }

    if ($action === 'nav_new') {
        $label = trim($_POST['label'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $parentId = $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
        $sort = (int)($pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM navigation_items')->fetchColumn());
        if ($label && $url) {
            $stmt = $pdo->prepare('INSERT INTO navigation_items (label, url, parent_id, sort_order) VALUES (?, ?, ?, ?)');
            $stmt->execute([$label, $url, $parentId, $sort]);
            $messages[] = 'Meniu punktas sukurtas';
        } else {
            $errors[] = 'Įveskite pavadinimą ir nuorodą.';
        }
        $view = 'menus';
    }

    if ($action === 'nav_update') {
        $id = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $parentId = $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
        if ($id && $label && $url) {
            $currentSort = $pdo->prepare('SELECT sort_order FROM navigation_items WHERE id = ?');
            $currentSort->execute([$id]);
            $sort = (int)($currentSort->fetchColumn() ?: 0);
            $stmt = $pdo->prepare('UPDATE navigation_items SET label = ?, url = ?, parent_id = ?, sort_order = ? WHERE id = ?');
            $stmt->execute([$label, $url, $parentId, $sort, $id]);
            $messages[] = 'Meniu atnaujintas';
        }
        $view = 'menus';
    }

    if ($action === 'nav_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM navigation_items WHERE id = ?')->execute([$id]);
            $messages[] = 'Meniu punktas pašalintas';
        }
        $view = 'menus';
    }

    if ($action === 'nav_reorder') {
        if (!empty($_POST['order']) && is_array($_POST['order'])) {
            $stmt = $pdo->prepare('UPDATE navigation_items SET sort_order = ? WHERE id = ?');
            foreach ($_POST['order'] as $id => $sort) {
                $stmt->execute([(int)$sort, (int)$id]);
            }
            $messages[] = 'Rikiavimas atnaujintas';
        }
        $view = 'menus';
    }

    if ($action === 'footer_content') {
        $footerData = [
            'footer_brand_title' => trim($_POST['footer_brand_title'] ?? ''),
            'footer_brand_body' => trim($_POST['footer_brand_body'] ?? ''),
            'footer_brand_pill' => trim($_POST['footer_brand_pill'] ?? ''),
            'footer_quick_title' => trim($_POST['footer_quick_title'] ?? ''),
            'footer_help_title' => trim($_POST['footer_help_title'] ?? ''),
            'footer_contact_title' => trim($_POST['footer_contact_title'] ?? ''),
            'footer_contact_email' => trim($_POST['footer_contact_email'] ?? ''),
            'footer_contact_phone' => trim($_POST['footer_contact_phone'] ?? ''),
            'footer_contact_hours' => trim($_POST['footer_contact_hours'] ?? ''),
        ];
        saveSiteContent($pdo, $footerData);
        $messages[] = 'Poraštės tekstas atnaujintas';
        $view = 'design';
    }

    if ($action === 'footer_link_save') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $label = trim($_POST['label'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $section = $_POST['section'] ?? 'quick';
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        if ($label && $url) {
            saveFooterLink($pdo, $id ?: null, $label, $url, $section, $sortOrder);
            $messages[] = $id ? 'Nuoroda atnaujinta' : 'Nuoroda pridėta';
        } else {
            $errors[] = 'Įveskite pavadinimą ir nuorodą.';
        }
        $view = 'design';
    }

    if ($action === 'footer_link_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            deleteFooterLink($pdo, $id);
            $messages[] = 'Nuoroda pašalinta';
        }
        $view = 'design';
    }

    if ($action === 'hero_copy') {
        $hero = [
            'hero_title' => trim($_POST['hero_title'] ?? ''),
            'hero_body' => trim($_POST['hero_body'] ?? ''),
            'hero_cta_label' => trim($_POST['hero_cta_label'] ?? ''),
            'hero_cta_url' => trim($_POST['hero_cta_url'] ?? ''),
        ];
        saveSiteContent($pdo, $hero);
        $messages[] = 'Hero tekstas atnaujintas';
        $view = 'design';
    }

    if ($action === 'page_hero_update') {
        $fields = [
            'news_hero_pill', 'news_hero_title', 'news_hero_body', 'news_hero_cta_label', 'news_hero_cta_url', 'news_hero_card_meta', 'news_hero_card_title', 'news_hero_card_body',
            'recipes_hero_pill', 'recipes_hero_title', 'recipes_hero_body', 'recipes_hero_cta_label', 'recipes_hero_cta_url', 'recipes_hero_card_meta', 'recipes_hero_card_title', 'recipes_hero_card_body',
            'faq_hero_pill', 'faq_hero_title', 'faq_hero_body',
            'contact_hero_pill', 'contact_hero_title', 'contact_hero_body', 'contact_cta_primary_label', 'contact_cta_primary_url', 'contact_cta_secondary_label', 'contact_cta_secondary_url', 'contact_card_pill', 'contact_card_title', 'contact_card_body',
        ];
        $payload = [];
        foreach ($fields as $field) {
            $payload[$field] = trim($_POST[$field] ?? '');
        }
        saveSiteContent($pdo, $payload);
        $messages[] = 'Hero sekcijos atnaujintos';
        $view = 'design';
    }

    if ($action === 'hero_media_update') {
        $type = $_POST['hero_media_type'] ?? 'image';
        $color = trim($_POST['hero_media_color'] ?? '#829ed6');
        $shadow = max(0, min(100, (int)($_POST['hero_shadow_intensity'] ?? 70)));
        $imagePath = trim($_POST['hero_media_image_existing'] ?? '');
        $videoPath = trim($_POST['hero_media_video_existing'] ?? '');
        $posterPath = trim($_POST['hero_media_poster_existing'] ?? '');
        $alt = trim($_POST['hero_media_alt'] ?? '');

        $imageMimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $videoMimeMap = [
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
        ];

        if (!empty($_FILES['hero_media_image']['name'])) {
            $uploaded = saveUploadedFile($_FILES['hero_media_image'], $imageMimeMap, 'hero_img_');
            if ($uploaded) {
                $imagePath = $uploaded;
            }
        }

        if (!empty($_FILES['hero_media_video']['name'])) {
            $uploaded = saveUploadedFile($_FILES['hero_media_video'], $videoMimeMap, 'hero_vid_');
            if ($uploaded) {
                $videoPath = $uploaded;
            }
        }

        if (!empty($_FILES['hero_media_poster']['name'])) {
            $uploaded = saveUploadedFile($_FILES['hero_media_poster'], $imageMimeMap, 'hero_poster_');
            if ($uploaded) {
                $posterPath = $uploaded;
            }
        }

        $payload = [
            'hero_media_type' => $type,
            'hero_media_color' => $color,
            'hero_media_image' => $imagePath,
            'hero_media_video' => $videoPath,
            'hero_media_poster' => $posterPath,
            'hero_media_alt' => $alt,
            'hero_shadow_intensity' => (string)$shadow,
        ];

        saveSiteContent($pdo, $payload);
        $messages[] = 'Hero fonas atnaujintas';
        $view = 'design';
    }

    if ($action === 'promo_update') {
        $payload = [];
        for ($i = 1; $i <= 3; $i++) {
            $payload['promo_' . $i . '_icon'] = trim($_POST['promo_' . $i . '_icon'] ?? '');
            $payload['promo_' . $i . '_title'] = trim($_POST['promo_' . $i . '_title'] ?? '');
            $payload['promo_' . $i . '_body'] = trim($_POST['promo_' . $i . '_body'] ?? '');
        }
        saveSiteContent($pdo, $payload);
        $messages[] = 'Promo kortelės atnaujintos';
        $view = 'design';
    }

    if ($action === 'storyband_update') {
        $payload = [
            'storyband_badge' => trim($_POST['storyband_badge'] ?? ''),
            'storyband_title' => trim($_POST['storyband_title'] ?? ''),
            'storyband_body' => trim($_POST['storyband_body'] ?? ''),
            'storyband_cta_label' => trim($_POST['storyband_cta_label'] ?? ''),
            'storyband_cta_url' => trim($_POST['storyband_cta_url'] ?? ''),
            'storyband_card_eyebrow' => trim($_POST['storyband_card_eyebrow'] ?? ''),
            'storyband_card_title' => trim($_POST['storyband_card_title'] ?? ''),
            'storyband_card_body' => trim($_POST['storyband_card_body'] ?? ''),
        ];

        for ($i = 1; $i <= 3; $i++) {
            $payload['storyband_metric_' . $i . '_value'] = trim($_POST['storyband_metric_' . $i . '_value'] ?? '');
            $payload['storyband_metric_' . $i . '_label'] = trim($_POST['storyband_metric_' . $i . '_label'] ?? '');
        }

        saveSiteContent($pdo, $payload);
        $messages[] = 'Storyband turinys atnaujintas';
        $view = 'design';
    }

    if ($action === 'storyrow_update') {
        $payload = [
            'storyrow_eyebrow' => trim($_POST['storyrow_eyebrow'] ?? ''),
            'storyrow_title' => trim($_POST['storyrow_title'] ?? ''),
            'storyrow_body' => trim($_POST['storyrow_body'] ?? ''),
            'storyrow_bubble_meta' => trim($_POST['storyrow_bubble_meta'] ?? ''),
            'storyrow_bubble_title' => trim($_POST['storyrow_bubble_title'] ?? ''),
            'storyrow_bubble_body' => trim($_POST['storyrow_bubble_body'] ?? ''),
            'storyrow_floating_meta' => trim($_POST['storyrow_floating_meta'] ?? ''),
            'storyrow_floating_title' => trim($_POST['storyrow_floating_title'] ?? ''),
            'storyrow_floating_body' => trim($_POST['storyrow_floating_body'] ?? ''),
        ];

        for ($i = 1; $i <= 3; $i++) {
            $payload['storyrow_pill_' . $i] = trim($_POST['storyrow_pill_' . $i] ?? '');
        }

        saveSiteContent($pdo, $payload);
        $messages[] = 'Story-row turinys atnaujintas';
        $view = 'design';
    }

    if ($action === 'support_update') {
        $payload = [
            'support_meta' => trim($_POST['support_meta'] ?? ''),
            'support_title' => trim($_POST['support_title'] ?? ''),
            'support_body' => trim($_POST['support_body'] ?? ''),
            'support_card_meta' => trim($_POST['support_card_meta'] ?? ''),
            'support_card_title' => trim($_POST['support_card_title'] ?? ''),
            'support_card_body' => trim($_POST['support_card_body'] ?? ''),
            'support_card_cta_label' => trim($_POST['support_card_cta_label'] ?? ''),
            'support_card_cta_url' => trim($_POST['support_card_cta_url'] ?? ''),
        ];

        for ($i = 1; $i <= 3; $i++) {
            $payload['support_chip_' . $i] = trim($_POST['support_chip_' . $i] ?? '');
        }

        saveSiteContent($pdo, $payload);
        $messages[] = 'Support band turinys atnaujintas';
        $view = 'design';
    }

    if ($action === 'banner_update') {
        $banner = [
            'banner_enabled' => isset($_POST['banner_enabled']) ? '1' : '0',
            'banner_text' => trim($_POST['banner_text'] ?? ''),
            'banner_link' => trim($_POST['banner_link'] ?? ''),
            'banner_background' => trim($_POST['banner_background'] ?? '#829ed6'),
        ];
        saveSiteContent($pdo, $banner);
        $messages[] = 'Reklamjuostė atnaujinta';
        $view = 'design';
    }

    if ($action === 'testimonial_update') {
        $payload = [];
        for ($i = 1; $i <= 3; $i++) {
            $payload['testimonial_' . $i . '_name'] = trim($_POST['testimonial_' . $i . '_name'] ?? '');
            $payload['testimonial_' . $i . '_role'] = trim($_POST['testimonial_' . $i . '_role'] ?? '');
            $payload['testimonial_' . $i . '_text'] = trim($_POST['testimonial_' . $i . '_text'] ?? '');
        }
        saveSiteContent($pdo, $payload);
        $messages[] = 'Atsiliepimai atnaujinti';
        $view = 'design';
    }

    if ($action === 'shipping_save') {
        $courier = (float)($_POST['shipping_courier'] ?? 3.99);
        $locker = (float)($_POST['shipping_locker'] ?? 2.49);
        $free = $_POST['shipping_free_over'] !== '' ? (float)$_POST['shipping_free_over'] : null;
        saveShippingSettings($pdo, $courier, $courier, $locker, $free);
        $messages[] = 'Pristatymo nustatymai išsaugoti';
        $view = 'shipping';
    }

    if ($action === 'locker_new') {
        $provider = $_POST['locker_provider'] ?? '';
        $title = trim($_POST['locker_title'] ?? '');
        $address = trim($_POST['locker_address'] ?? '');
        $note = trim($_POST['locker_note'] ?? '');

        if (!in_array($provider, ['omniva', 'lpexpress'], true)) {
            $errors[] = 'Pasirinkite tinkamą paštomatų tinklą.';
        }
        if ($title === '' || $address === '') {
            $errors[] = 'Įveskite paštomato pavadinimą ir adresą.';
        }

        if (!$errors) {
            saveParcelLocker($pdo, $provider, $title, $address, $note ?: null);
            $messages[] = 'Paštomatas išsaugotas';
        }
        $view = 'shipping';
    }

    if ($action === 'locker_update') {
        $lockerId = (int)($_POST['locker_id'] ?? 0);
        $provider = $_POST['locker_provider'] ?? '';
        $title = trim($_POST['locker_title'] ?? '');
        $address = trim($_POST['locker_address'] ?? '');
        $note = trim($_POST['locker_note'] ?? '');

        if (!in_array($provider, ['omniva', 'lpexpress'], true)) {
            $errors[] = 'Pasirinkite tinkamą paštomatų tinklą.';
        }
        if ($title === '' || $address === '') {
            $errors[] = 'Įveskite paštomato pavadinimą ir adresą.';
        }
        if ($lockerId <= 0) {
            $errors[] = 'Pasirinkite paštomatą redagavimui.';
        }

        if (!$errors) {
            updateParcelLocker($pdo, $lockerId, $provider, $title, $address, $note ?: null);
            $messages[] = 'Paštomatas atnaujintas';
        }
        $view = 'shipping';
    }

    if ($action === 'locker_import') {
        $provider = $_POST['locker_provider'] ?? '';
        $view = 'shipping';

        if (!in_array($provider, ['omniva', 'lpexpress'], true)) {
            $errors[] = 'Pasirinkite tinkamą paštomatų tinklą importui.';
        }

        if (empty($_FILES['locker_file']) || ($_FILES['locker_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Įkelkite .xlsx failą su paštomatais.';
        }

        $allowedMimeMap = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        ];

        $uploadedLockerPath = null;
        if (($_FILES['locker_file']['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK) {
            $uploadedLockerPath = saveUploadedFile($_FILES['locker_file'], $allowedMimeMap, 'lockers_');
        }

        if (!$uploadedLockerPath) {
            $errors[] = 'Leidžiami tik .xlsx failai.';
        }

        if (!$errors && $uploadedLockerPath) {
            $parsed = parseLockerFile($provider, __DIR__ . $uploadedLockerPath);
            if (!$parsed) {
                $errors[] = 'Nepavyko nuskaityti paštomatų iš failo.';
            } else {
                bulkSaveParcelLockers($pdo, $provider, $parsed);
                $messages[] = 'Importuota paštomatų: ' . count($parsed);
            }
        }
    }

    if ($action === 'shipping_free_products') {
        $selected = $_POST['promo_products'] ?? [];
        saveFreeShippingProducts($pdo, is_array($selected) ? $selected : []);
        $messages[] = 'Nemokamo pristatymo pasiūlymai atnaujinti';
        $view = 'shipping';
    }

    if ($action === 'community_block') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $until = trim($_POST['banned_until'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        if ($userId) {
            $pdo->prepare('REPLACE INTO community_blocks (user_id, banned_until, reason) VALUES (?, ?, ?)')->execute([$userId, $until ?: null, $reason ?: null]);
            $messages[] = 'Vartotojas apribotas';
        }
        $view = 'community';
    }

    if ($action === 'community_unblock') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            $pdo->prepare('DELETE FROM community_blocks WHERE user_id = ?')->execute([$uid]);
            $messages[] = 'Apribojimas pašalintas';
        }
        $view = 'community';
    }

    if ($action === 'community_order_status') {
        $id = (int)($_POST['order_id'] ?? 0);
        $status = $_POST['status'] ?? 'laukiama';
        if ($id) {
            $pdo->prepare('UPDATE community_orders SET status = ? WHERE id = ?')->execute([$status, $id]);
            $messages[] = 'Užklausos statusas atnaujintas';
        }
        $view = 'community';
    }

    if ($action === 'community_listing_status') {
        $id = (int)($_POST['listing_id'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        if ($id) {
            $pdo->prepare('UPDATE community_listings SET status = ? WHERE id = ?')->execute([$status, $id]);
            $messages[] = 'Skelbimo statusas atnaujintas';
        }
        $view = 'community';
    }
}

$categoryCounts = $pdo->query('SELECT c.*, COUNT(p.id) AS product_count FROM categories c LEFT JOIN products p ON p.category_id = c.id GROUP BY c.id ORDER BY c.name')->fetchAll();
$products = $pdo->query('SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id ORDER BY p.created_at DESC')->fetchAll();
$users = $pdo->query('SELECT id, name, email, is_admin, created_at FROM users ORDER BY created_at DESC')->fetchAll();
$ordersCount = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$productCount = $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$categoryCount = $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
$userCount = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalSales = (float)$pdo->query('SELECT COALESCE(SUM(total), 0) FROM orders')->fetchColumn();
$averageOrder = $ordersCount > 0 ? $totalSales / $ordersCount : 0;
$latestOrders = $pdo->query('SELECT id, customer_name, total, status, created_at FROM orders ORDER BY created_at DESC LIMIT 5')->fetchAll();
$siteContent = getSiteContent($pdo);
$allOrders = $pdo->query('SELECT o.*, u.name AS user_name, u.email AS user_email FROM orders o LEFT JOIN users u ON u.id = o.user_id ORDER BY o.created_at DESC')->fetchAll();
$globalDiscount = getGlobalDiscount($pdo);
$discountCodes = getAllDiscountCodes($pdo);
$categoryDiscounts = getCategoryDiscounts($pdo);
$allCategoriesSimple = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$orderItemsStmt = $pdo->prepare('SELECT oi.*, p.title, p.image_url FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE order_id = ?');
$newsList = $pdo->query('SELECT id, title, created_at FROM news ORDER BY created_at DESC')->fetchAll();
$recipeList = $pdo->query('SELECT id, title, created_at FROM recipes ORDER BY created_at DESC')->fetchAll();
$featuredIds = getFeaturedProductIds($pdo);
$freeShippingProductIds = getFreeShippingProductIds($pdo);
$communityThreads = $pdo->query('SELECT t.*, u.name AS author FROM community_threads t JOIN users u ON u.id = t.user_id ORDER BY t.created_at DESC')->fetchAll();
$communityComments = $pdo->query('SELECT thread_id, COUNT(*) AS total FROM community_comments GROUP BY thread_id')->fetchAll();
$commentCounts = [];
foreach ($communityComments as $c) { $commentCounts[$c['thread_id']] = $c['total']; }
$communityBlocks = $pdo->query('SELECT b.*, u.name, u.email FROM community_blocks b JOIN users u ON u.id = b.user_id')->fetchAll();
$communityListings = $pdo->query('SELECT l.*, u.name FROM community_listings l JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC')->fetchAll();
$communityOrders = $pdo->query('SELECT co.*, l.title AS listing_title, u.name AS buyer_name FROM community_orders co JOIN community_listings l ON l.id = co.listing_id JOIN users u ON u.id = co.buyer_id ORDER BY co.created_at DESC')->fetchAll();
$threadCategories = $pdo->query('SELECT * FROM community_thread_categories ORDER BY name ASC')->fetchAll();
$listingCategories = $pdo->query('SELECT * FROM community_listing_categories ORDER BY name ASC')->fetchAll();
$featuredProducts = [];
if ($featuredIds) {
    $placeholders = implode(',', array_fill(0, count($featuredIds), '?'));
    $stmt = $pdo->prepare("SELECT id, title, price FROM products WHERE id IN ($placeholders)");
    $stmt->execute($featuredIds);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) { $map[$row['id']] = $row; }
    foreach ($featuredIds as $fid) {
        if (!empty($map[$fid])) { $featuredProducts[] = $map[$fid]; }
    }
}
$navItems = $pdo->query('SELECT id, label, url, parent_id, sort_order FROM navigation_items ORDER BY sort_order ASC, id ASC')->fetchAll();
$footerLinks = getFooterLinks($pdo);
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Administravimas | E-kolekcija</title>
  <?php echo headerStyles(); ?>
  <style>
    :root {
      --color-bg: #f7f7fb;
      --color-surface: #ffffff;
      --color-border: #e1e3ef;
      --color-primary: #0b0b0b;
      --color-accent: #4f46e5;
      --color-muted: #6b6b7a;
    }

    * { box-sizing: border-box; }
    body { margin:0; background:var(--color-bg); color:var(--color-primary); }
    a { color:inherit; text-decoration:none; }
    .page { max-width:1200px; margin:0 auto; padding:32px 24px 48px; }

    .hero {
      position: relative;
      background: radial-gradient(circle at 20% 20%, rgba(79,70,229,0.12), transparent 32%),
                  radial-gradient(circle at 80% 0%, rgba(16,185,129,0.12), transparent 30%),
                  #f7f7fb;
      border:1px solid var(--color-border);
      border-radius:24px;
      padding:24px;
      box-shadow:0 12px 40px rgba(15, 23, 42, 0.08);
      display:flex;
      gap:24px;
      align-items:flex-start;
      margin-bottom:18px;
    }
    .hero h1 { margin:8px 0 6px; font-size:28px; }
    .hero .eyebrow { text-transform:uppercase; letter-spacing:0.08em; font-weight:700; font-size:12px; color:var(--color-accent); }
    .hero p { margin:4px 0; color:var(--color-muted); }
    .hero-actions { display:flex; gap:10px; margin-top:10px; }
    .hero-actions a { font-weight:600; padding:10px 14px; border-radius:12px; border:1px solid var(--color-border); background:rgba(255,255,255,0.9); }

    .stat-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:12px; flex:1; }
    .stat-card { background:rgba(255,255,255,0.9); border:1px solid var(--color-border); border-radius:14px; padding:12px 14px; box-shadow:0 10px 24px rgba(15,23,42,0.05); }
    .stat-label { font-size:12px; text-transform:uppercase; letter-spacing:0.05em; color:var(--color-muted); font-weight:700; }
    .stat-value { font-size:26px; font-weight:800; margin-top:4px; }
    .stat-sub { font-size:12px; color:var(--color-muted); }

    .nav { display:flex; gap:10px; row-gap:8px; margin:12px 0 18px; flex-wrap:wrap; }
    .nav a { padding:10px 14px; border-radius:12px; border:1px solid var(--color-border); background:var(--color-surface); font-weight:700; box-shadow:0 8px 16px rgba(15,23,42,0.04); }
    .nav a.active { background:linear-gradient(135deg, #111827, #4338ca); color:#fff; border-color:#111827; box-shadow:0 12px 28px rgba(67,56,202,0.25); }

    .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:18px; }
    .section-stack { display:flex; flex-direction:column; gap:16px; margin-top:12px; }
    .card { background:var(--color-surface); border-radius:18px; padding:18px; border:1px solid var(--color-border); box-shadow:0 10px 32px rgba(15, 23, 42, 0.06); }
    .card h3 { margin-top:0; margin-bottom:10px; }

    .btn { padding:10px 14px; border-radius:12px; border:1px solid #0b0b0b; background:#0b0b0b; color:#fff; font-weight:700; cursor:pointer; transition:transform 0.1s ease, box-shadow 0.2s ease; }
    .btn:hover { transform:translateY(-1px); box-shadow:0 10px 20px rgba(15,23,42,0.12); }
    .btn.secondary { background:#f7f7fb; color:var(--color-primary); border-color:var(--color-border); }

    input, textarea, select { width:100%; padding:11px 12px; border-radius:12px; border:1px solid var(--color-border); margin-bottom:8px; background:#fff; }
    input:focus, textarea:focus, select:focus { outline:2px solid rgba(79,70,229,0.2); border-color:#4338ca; }

    table { width:100%; border-collapse: collapse; }
    th, td { padding:10px 8px; border-bottom:1px solid #edf0f6; text-align:left; font-size:14px; word-break:break-word; }
    th { text-transform:uppercase; letter-spacing:0.05em; font-size:12px; color:var(--color-muted); }
    tr:hover td { background:#fafbff; }

    .table-form td { vertical-align:middle; }
    .table-form input,
    .table-form select { margin:0; min-width:120px; width:100%; padding:9px 10px; }
    .inline-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .table-note { color:var(--color-muted); font-size:13px; margin:-4px 0 8px; }

    .muted { color:var(--color-muted); }
    .image-list { display:flex; gap:10px; flex-wrap:wrap; }
    .image-tile { border:1px solid #e6e6ef; border-radius:12px; padding:8px; width:140px; text-align:center; background:#f9f9ff; }
    .image-tile img { width:100%; height:90px; object-fit:cover; border-radius:10px; }
    .input-row { display:flex; gap:10px; flex-wrap:wrap; }
    .chip-input { border:1px solid #e6e6ef; border-radius:12px; padding:8px 10px; background:#f7f7fb; display:inline-flex; gap:6px; align-items:center; }
    .pill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; background:#eef2ff; color:#4338ca; font-weight:600; font-size:12px; }
    .alert { border-radius:14px; padding:12px 14px; border:1px solid; box-shadow:0 8px 16px rgba(15,23,42,0.06); }
    .alert.success { background:#edf9f0; border-color:#b8e2c4; color:#0f5132; }
    .alert.error { background:#fff1f1; border-color:#f3b7b7; color:#991b1b; }
    @media (max-width: 920px) {
      .page { padding:22px 16px 40px; }
      .hero { flex-direction:column; align-items:stretch; gap:16px; }
      .hero-actions { flex-wrap:wrap; }
      .stat-grid { width:100%; }
      .nav { overflow-x:auto; padding-bottom:6px; }
      .nav a { white-space:nowrap; }
      .card { padding:16px; }
      .grid { grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); }
      table { display:block; overflow-x:auto; width:100%; }
      th, td { white-space:nowrap; }
    }
    @media (max-width: 640px) {
      .page { padding:18px 12px 32px; }
      .hero { padding:18px; }
      .input-row { flex-direction:column; }
      .hero-actions { gap:8px; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'admin'); ?>
  <div class="page">
    <div class="hero">
      <div style="flex:1; min-width:260px;">
        <div class="eyebrow">Kontrolės centras</div>
        <h1>Administravimo skydelis</h1>
        <p>Patogiai valdykite pardavimus, turinį ir bendruomenę vienoje vietoje.</p>
        <div class="hero-actions">
          <a href="/" aria-label="Grįžti į pagrindinį puslapį">↩ Pagrindinis</a>
          <span class="pill">Realaus laiko apžvalga</span>
        </div>
      </div>
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-label">VISO PARDAVIMŲ</div>
          <div class="stat-value"><?php echo number_format($totalSales, 2); ?> €</div>
          <div class="stat-sub">Apima visus užsakymus</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">UŽSAKYMAI</div>
          <div class="stat-value"><?php echo (int)$ordersCount; ?></div>
          <div class="stat-sub">Šioje parduotuvėje</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">VID. UŽSAKYMAS</div>
          <div class="stat-value"><?php echo number_format($averageOrder, 2); ?> €</div>
          <div class="stat-sub">Pastaruosius visus</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">VARTOTOJAI</div>
          <div class="stat-value"><?php echo (int)$userCount; ?></div>
          <div class="stat-sub">Bendruomenės nariai</div>
        </div>
      </div>
    </div>

    <?php foreach ($messages as $msg): ?>
      <div class="alert success" style="margin-bottom:10px;">&check; <?php echo htmlspecialchars($msg); ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $err): ?>
      <div class="alert error" style="margin-bottom:10px;">&times; <?php echo htmlspecialchars($err); ?></div>
    <?php endforeach; ?>

    <div class="nav">
      <a class="<?php echo $view === 'dashboard' ? 'active' : ''; ?>" href="?view=dashboard">Skydelis</a>
      <a class="<?php echo $view === 'products' ? 'active' : ''; ?>" href="?view=products">Prekės</a>
      <a class="<?php echo $view === 'categories' ? 'active' : ''; ?>" href="?view=categories">Kategorijos</a>
      <a class="<?php echo $view === 'content' ? 'active' : ''; ?>" href="?view=content">Turinys</a>
      <a class="<?php echo $view === 'design' ? 'active' : ''; ?>" href="?view=design">Dizainas</a>
      <a class="<?php echo $view === 'shipping' ? 'active' : ''; ?>" href="?view=shipping">Pristatymas</a>
      <a class="<?php echo $view === 'discounts' ? 'active' : ''; ?>" href="?view=discounts">Nuolaidos</a>
      <a class="<?php echo $view === 'community' ? 'active' : ''; ?>" href="?view=community">Bendruomenė</a>
      <a class="<?php echo $view === 'menus' ? 'active' : ''; ?>" href="?view=menus">Meniu</a>
      <a class="<?php echo $view === 'users' ? 'active' : ''; ?>" href="?view=users">Vartotojai</a>
      <a class="<?php echo $view === 'orders' ? 'active' : ''; ?>" href="?view=orders">Užsakymai</a>
    </div>

    <?php if ($view === 'dashboard'): ?>
      <div class="section-stack">
        <div class="grid" style="margin-top:4px;">
          <div class="card"><h3>VISO PARDAVIMŲ</h3><p style="font-size:32px; font-weight:700;"><?php echo number_format($totalSales, 2); ?> €</p></div>
          <div class="card"><h3>VISO UŽSAKYMŲ</h3><p style="font-size:32px; font-weight:700;"><?php echo (int)$ordersCount; ?></p></div>
          <div class="card"><h3>VIDUTINĖ UŽSAKYMO VERTĖ</h3><p style="font-size:32px; font-weight:700;"><?php echo number_format($averageOrder, 2); ?> €</p></div>
          <div class="card"><h3>Vartotojai</h3><p style="font-size:32px; font-weight:700;"><?php echo (int)$userCount; ?></p></div>
        </div>
        <div class="grid" style="grid-template-columns:2fr 1fr; gap:16px;">
          <div class="card">
            <h3>Naujausi užsakymai</h3>
            <table>
              <thead><tr><th>#</th><th>Vardas</th><th>Suma</th><th>Statusas</th><th>Data</th></tr></thead>
              <tbody>
                <?php foreach ($latestOrders as $o): ?>
                  <tr>
                    <td><?php echo (int)$o['id']; ?></td>
                    <td><?php echo htmlspecialchars($o['customer_name']); ?></td>
                    <td><?php echo number_format((float)$o['total'], 2); ?> €</td>
                    <td><?php echo htmlspecialchars($o['status']); ?></td>
                    <td><?php echo htmlspecialchars($o['created_at']); ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$latestOrders): ?>
                  <tr><td colspan="5" class="muted">Užsakymų dar nėra.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="card">
            <h3>Produktai pagal kategoriją</h3>
            <table>
              <thead><tr><th>Kategorija</th><th>Prekių skaičius</th></tr></thead>
              <tbody>
                <?php foreach ($categoryCounts as $cat): ?>
                  <tr><td><?php echo htmlspecialchars($cat['name']); ?></td><td><?php echo (int)$cat['product_count']; ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($view === 'community'): ?>
      <div class="grid" style="margin-top:10px; grid-template-columns:2fr 1fr;">
        <div class="card">
          <h3>Diskusijos</h3>
          <table>
            <thead><tr><th>Pavadinimas</th><th>Autorius</th><th>Komentarai</th><th>Data</th></tr></thead>
            <tbody>
              <?php foreach ($communityThreads as $t): ?>
                <tr>
                  <td><?php echo htmlspecialchars($t['title']); ?></td>
                  <td><?php echo htmlspecialchars($t['author']); ?></td>
                  <td><?php echo isset($commentCounts[$t['id']]) ? (int)$commentCounts[$t['id']] : 0; ?></td>
                  <td><?php echo htmlspecialchars($t['created_at']); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$communityThreads): ?><tr><td colspan="4" class="muted">Diskusijų nėra.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="card">
          <h3>Blokavimai</h3>
          <form method="post" style="display:flex;flex-direction:column;gap:8px;">
            <?php echo csrfField(); ?>
<input type="hidden" name="action" value="community_block">
            <label>Vartotojas</label>
            <select name="user_id" required>
              <option value="">Pasirinkite</option>
              <?php foreach ($users as $u): ?>
                <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['name'] . ' (' . $u['email'] . ')'); ?></option>
              <?php endforeach; ?>
            </select>
            <label>Blokuotas iki (palikite tuščią neterminuotai)</label>
            <input type="datetime-local" name="banned_until">
            <label>Priežastis</label>
            <input name="reason">
            <button class="btn" type="submit">Išsaugoti</button>
          </form>
          <div style="margin-top:10px;">
            <?php foreach ($communityBlocks as $block): ?>
              <div style="border:1px solid #e6e6ef; border-radius:10px; padding:8px; margin-bottom:8px;">
                <strong><?php echo htmlspecialchars($block['name']); ?></strong>
                <div class="muted" style="font-size:13px;">Iki: <?php echo htmlspecialchars($block['banned_until'] ?? 'neribotai'); ?></div>
                <?php if ($block['reason']): ?><div style="font-size:13px; margin-top:4px;">Priežastis: <?php echo htmlspecialchars($block['reason']); ?></div><?php endif; ?>
                <form method="post" style="margin-top:6px;">
                  <?php echo csrfField(); ?>
<input type="hidden" name="action" value="community_unblock">
                  <input type="hidden" name="user_id" value="<?php echo (int)$block['user_id']; ?>">
                  <button class="btn" type="submit" style="background:#f1f1f5; color:#0b0b0b; border-color:#e0e0ea;">Nuimti bloką</button>
                </form>
              </div>
            <?php endforeach; ?>
            <?php if (!$communityBlocks): ?><div class="muted">Apribojimų nėra.</div><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="grid" style="margin-top:14px; grid-template-columns:1.6fr 1.4fr;">
        <div class="card">
          <h3>Diskusijų kategorijos</h3>
          <form method="post" style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
            <?php echo csrfField(); ?>
<input type="hidden" name="action" value="new_thread_category">
            <input name="name" placeholder="Pvz. Mityba" required>
            <button class="btn" type="submit">Pridėti</button>
          </form>
          <div style="display:flex;flex-direction:column;gap:6px;">
            <?php foreach ($threadCategories as $cat): ?>
              <form method="post" style="display:flex;gap:8px;align-items:center;border:1px solid #e6e6ef;padding:8px;border-radius:10px;">
                <?php echo csrfField(); ?>
<span><?php echo htmlspecialchars($cat['name']); ?></span>
                <input type="hidden" name="action" value="delete_thread_category">
                <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                <button class="btn" type="submit" style="background:#fff;color:#0b0b0b;border-color:#e0e0ea;">Šalinti</button>
              </form>
            <?php endforeach; ?>
            <?php if (!$threadCategories): ?><div class="muted">Kategorijų dar nėra.</div><?php endif; ?>
          </div>
        </div>
        <div class="card">
          <h3>Turgus kategorijos</h3>
          <form method="post" style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
            <?php echo csrfField(); ?>
<input type="hidden" name="action" value="new_listing_category">
            <input name="name" placeholder="Pvz. Technika" required>
            <button class="btn" type="submit">Pridėti</button>
          </form>
          <div style="display:flex;flex-direction:column;gap:6px;">
            <?php foreach ($listingCategories as $cat): ?>
              <form method="post" style="display:flex;gap:8px;align-items:center;border:1px solid #e6e6ef;padding:8px;border-radius:10px;">
                <?php echo csrfField(); ?>
<span><?php echo htmlspecialchars($cat['name']); ?></span>
                <input type="hidden" name="action" value="delete_listing_category">
                <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                <button class="btn" type="submit" style="background:#fff;color:#0b0b0b;border-color:#e0e0ea;">Šalinti</button>
              </form>
            <?php endforeach; ?>
            <?php if (!$listingCategories): ?><div class="muted">Kategorijų dar nėra.</div><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="grid" style="margin-top:14px; grid-template-columns:1.6fr 1.4fr;">
        <div class="card">
          <h3>Skelbimai</h3>
          <table>
            <thead><tr><th>Pavadinimas</th><th>Pardavėjas</th><th>Kaina</th><th>Statusas</th><th>Veiksmai</th></tr></thead>
            <tbody>
              <?php foreach ($communityListings as $l): ?>
                <tr>
                  <td><?php echo htmlspecialchars($l['title']); ?></td>
                  <td><?php echo htmlspecialchars($l['name']); ?></td>
                  <td>€<?php echo number_format((float)$l['price'],2); ?></td>
                  <td><?php echo htmlspecialchars($l['status']); ?></td>
                  <td>
                    <form method="post" style="display:flex; gap:6px; align-items:center;">
                      <?php echo csrfField(); ?>
<input type="hidden" name="action" value="community_listing_status">
                      <input type="hidden" name="listing_id" value="<?php echo (int)$l['id']; ?>">
                      <select name="status" style="margin:0;">
                        <option value="active" <?php echo $l['status']==='active'?'selected':''; ?>>Aktyvi</option>
                        <option value="sold" <?php echo $l['status']==='sold'?'selected':''; ?>>Parduota</option>
                      </select>
                      <button class="btn" type="submit" style="background:#fff; color:#0b0b0b;">Išsaugoti</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$communityListings): ?><tr><td colspan="5" class="muted">Skelbimų nėra.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="card">
          <h3>Pirkėjų užklausos</h3>
          <table>
            <thead><tr><th>Skelbimas</th><th>Pirkėjas</th><th>Statusas</th><th>Data</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($communityOrders as $co): ?>
                <tr>
                  <td><?php echo htmlspecialchars($co['listing_title']); ?></td>
                  <td><?php echo htmlspecialchars($co['buyer_name']); ?></td>
                  <td>
                    <form method="post" style="display:flex; gap:6px; align-items:center;">
                      <?php echo csrfField(); ?>
<input type="hidden" name="action" value="community_order_status">
                      <input type="hidden" name="order_id" value="<?php echo (int)$co['id']; ?>">
                      <select name="status" style="margin:0;">
                        <?php foreach (["laukiama","patvirtinta","įvykdyta","atšaukta"] as $s): ?>
                          <option value="<?php echo $s; ?>" <?php echo $co['status'] === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn" type="submit" style="background:#fff; color:#0b0b0b;">Atnaujinti</button>
                    </form>
                  </td>
                  <td><?php echo htmlspecialchars($co['created_at']); ?></td>
                  <td><?php echo htmlspecialchars($co['note']); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$communityOrders): ?><tr><td colspan="5" class="muted">Užklausų nėra.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($view === 'orders'): ?>
      <div class="card">
        <h3>Visi užsakymai</h3>
        <table>
          <thead><tr><th>#</th><th>Vartotojas</th><th>Suma</th><th>Statusas</th><th>Data</th><th>Adresas</th><th>Veiksmai</th></tr></thead>
          <tbody>
            <?php foreach ($allOrders as $order): ?>
              <tr>
                <td><?php echo (int)$order['id']; ?></td>
                <td>
                  <?php echo htmlspecialchars($order['customer_name']); ?><br>
                  <span class="muted"><?php echo htmlspecialchars($order['customer_email']); ?></span>
                  <?php if (!empty($order['customer_phone'])): ?><br><span class="muted">Tel.: <?php echo htmlspecialchars($order['customer_phone']); ?></span><?php endif; ?>
                </td>
                <td><?php echo number_format((float)$order['total'], 2); ?> €</td>
                <td>
                  <form method="post" style="display:flex; gap:6px; align-items:center;">
                    <?php echo csrfField(); ?>
<input type="hidden" name="action" value="order_status">
                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                    <select name="status" style="margin:0;">
                      <?php foreach (["laukiama","apdorojama","išsiųsta","įvykdyta","atšaukta"] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $order['status'] === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn" type="submit" style="background:#fff; color:#0b0b0b;">Atnaujinti</button>
                  </form>
                </td>
                <td><?php echo htmlspecialchars($order['created_at']); ?></td>
                <td style="max-width:200px;"> <?php echo nl2br(htmlspecialchars($order['customer_address'])); ?> </td>
                <td>
                  <?php $orderItemsStmt->execute([$order['id']]); $items = $orderItemsStmt->fetchAll(); ?>
                  <details>
                    <summary style="cursor:pointer; font-weight:600;">Peržiūrėti</summary>
                    <div class="items" style="margin-top:8px;">
                      <?php foreach ($items as $item): ?>
                        <div style="display:flex; align-items:center; gap:8px; padding:6px 0; border-bottom:1px dashed #eaeaea;">
                          <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="" style="width:48px; height:48px; object-fit:cover; border-radius:8px;">
                          <div style="flex:1;">
                            <div style="font-weight:600;"><?php echo htmlspecialchars($item['title']); ?></div>
                            <div class="muted">Kiekis: <?php echo (int)$item['quantity']; ?> × <?php echo number_format((float)$item['price'], 2); ?> €</div>
                          </div>
                          <div style="font-weight:700;"><?php echo number_format((float)$item['price'] * (int)$item['quantity'], 2); ?> €</div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$allOrders): ?>
              <tr><td colspan="7" class="muted">Užsakymų nėra.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card" style="margin-top:16px;">
        <h3>Aktyvios kategorijų nuolaidos</h3>
        <table>
          <thead><tr><th>Kategorija</th><th>Tipas</th><th>Reikšmė</th><th>Nemokamas pristatymas</th><th>Aktyvi</th><th>Veiksmai</th></tr></thead>
          <tbody>
            <?php foreach ($categoryDiscounts as $catId => $disc): ?>
              <tr>
                <td><?php echo htmlspecialchars($allCategoriesSimple[array_search($catId, array_column($allCategoriesSimple, 'id'))]['name'] ?? ('ID ' . $catId)); ?></td>
                <?php
                  $typeLabel = 'Išjungta';
                  if ($disc['type'] === 'percent') { $typeLabel = 'Procentai'; }
                  elseif ($disc['type'] === 'amount') { $typeLabel = 'Suma'; }
                  elseif ($disc['type'] === 'free_shipping') { $typeLabel = 'Nemokamas pristatymas'; }
                ?>
                <td><?php echo htmlspecialchars($typeLabel); ?></td>
                <td><?php echo $disc['type'] === 'free_shipping' ? '–' : number_format((float)$disc['value'], 2) . ($disc['type'] === 'percent' ? ' %' : ' €'); ?></td>
                <td><?php echo (!empty($disc['free_shipping']) || $disc['type'] === 'free_shipping') ? 'Taip' : 'Ne'; ?></td>
                <td><?php echo (int)$disc['active'] ? 'Taip' : 'Ne'; ?></td>
                <td>
                  <form method="post" style="display:inline-block;">
                    <?php echo csrfField(); ?>
<input type="hidden" name="action" value="delete_category_discount">
                    <input type="hidden" name="category_id" value="<?php echo (int)$catId; ?>">
                    <button class="btn" type="submit" style="background:#f1f1f5; color:#0b0b0b; border-color:#e0e0ea; padding:8px 10px;">Šalinti</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$categoryDiscounts): ?>
              <tr><td colspan="6" class="muted">Kategorijų nuolaidų nėra.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($view === 'discounts'): ?>
      <div class="grid" style="margin-top:12px; grid-template-columns: repeat(auto-fit, minmax(320px,1fr));">
        <div class="card">
          <h3>Bendra nuolaida</h3>
          <form method="post">
            <?php echo csrfField(); ?>
<input type="hidden" name="action" value="save_global_discount">
            <label>Tipas</label>
            <select name="discount_type">
              <option value="none" <?php echo $globalDiscount['type'] === 'none' ? 'selected' : ''; ?>>Išjungta</option>
              <option value="percent" <?php echo $globalDiscount['type'] === 'percent' ? 'selected' : ''; ?>>Procentai (%)</option>
              <option value="amount" <?php echo $globalDiscount['type'] === 'amount' ? 'selected' : ''; ?>>Suma (€)</option>
              <option value="free_shipping" <?php echo $globalDiscount['type'] === 'free_shipping' ? 'selected' : ''; ?>>Nemokamas pristatymas</option>
            </select>
            <label>Reikšmė</label>
            <input class="discount-value" data-toggle-select="discount_type" type="number" step="0.01" name="discount_value" value="<?php echo htmlspecialchars($globalDiscount['value']); ?>">
            <button class="btn" type="submit">Išsaugoti</button>
          </form>
        </div>
        <div class="card">
          <h3>Naujas nuolaidos kodas</h3>
          <form method="post" class="input-row" style="flex-direction:column;">
            <?php echo csrfField(); ?>
<input type="hidden" name="action" value="save_discount_code">
            <label>Kodas</label>
            <input name="code" placeholder="BLACKFRIDAY" required>
            <div class="input-row">
              <div style="flex:1; min-width:140px;">
                <label>Tipas</label>
                <select name="type">
                  <option value="percent">Procentai (%)</option>
                  <option value="amount">Suma (€)</option>
                  <option value="free_shipping">Nemokamas pristatymas</option>
                </select>
              </div>
              <div style="flex:1; min-width:140px;">
                <label>Reikšmė</label>
                <input class="discount-value" data-toggle-select="type" name="value" type="number" step="0.01" min="0" required>
              </div>
            </div>
            <div class="input-row">
              <div style="flex:1; min-width:140px;">
                <label>Panaudojimų limitas (0 – neribota)</label>
                <input name="usage_limit" type="number" min="0" value="0">
              </div>
              <div style="flex:1; min-width:140px; display:flex; flex-direction:column; gap:8px;">
                <label class="checkbox-row"><input type="checkbox" id="code_active_new" name="active" checked> Aktyvus</label>
              </div>
            </div>
            <button class="btn" type="submit">Sukurti kodą</button>
          </form>
        </div>
        <div class="card">
          <h3>Kategorijų nuolaidos</h3>
          <form method="post" class="input-row" style="flex-direction:column;">
            <?php echo csrfField(); ?>
<input type="hidden" name="action" value="save_category_discount">
            <label>Kategorija</label>
            <select name="category_id" required>
              <option value="">Pasirinkti</option>
              <?php foreach ($allCategoriesSimple as $cat): ?>
                <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="input-row">
              <div style="flex:1;">
                <label>Tipas</label>
                <select name="category_type">
                  <option value="none">Išjungta</option>
                  <option value="percent">Procentai (%)</option>
                  <option value="amount">Suma (€)</option>
                  <option value="free_shipping">Nemokamas pristatymas</option>
                </select>
              </div>
              <div style="flex:1;">
                <label>Reikšmė</label>
                <input class="discount-value" data-toggle-select="category_type" name="category_value" type="number" step="0.01" min="0" value="0">
              </div>
            </div>
            <label class="checkbox-row"><input type="checkbox" name="category_active" checked> Aktyvuota</label>
            <button class="btn" type="submit">Išsaugoti</button>
          </form>
        </div>
      </div>

      <div class="card" style="margin-top:16px;">
        <h3>Nuolaidų kodai</h3>
        <p class="table-note">Kiekviena eilutė redaguojama vietoje – vertės automatiškai pritaikomos.</p>
        <table class="table-form">
          <thead><tr><th>Kodas</th><th>Tipas</th><th>Reikšmė</th><th>Panaudojimų limitas</th><th>Panaudota</th><th>Aktyvus</th><th>Veiksmai</th></tr></thead>
          <tbody>
            <?php foreach ($discountCodes as $code): ?>
              <?php $formId = 'codeform' . (int)$code['id']; ?>
              <form id="<?php echo $formId; ?>" method="post"></form>
              <tr>
                <td>
                  <input type="hidden" form="<?php echo $formId; ?>" name="action" value="save_discount_code">
                  <input type="hidden" form="<?php echo $formId; ?>" name="id" value="<?php echo (int)$code['id']; ?>">
                  <input form="<?php echo $formId; ?>" name="code" value="<?php echo htmlspecialchars($code['code']); ?>">
                </td>
                <td>
                  <select form="<?php echo $formId; ?>" name="type">
                    <option value="percent" <?php echo $code['type'] === 'percent' ? 'selected' : ''; ?>>%</option>
                    <option value="amount" <?php echo $code['type'] === 'amount' ? 'selected' : ''; ?>>€</option>
                    <option value="free_shipping" <?php echo $code['type'] === 'free_shipping' ? 'selected' : ''; ?>>Nemokamas pristatymas</option>
                  </select>
                </td>
                <td><input form="<?php echo $formId; ?>" data-toggle-select="type" name="value" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars($code['value']); ?>"></td>
                <td><input form="<?php echo $formId; ?>" name="usage_limit" type="number" min="0" value="<?php echo (int)$code['usage_limit']; ?>"></td>
                <td class="muted" style="min-width:80px;"><?php echo (int)$code['used_count']; ?></td>
                <td style="text-align:center; min-width:140px;">
                  <label class="checkbox-row" style="justify-content:center;"><input form="<?php echo $formId; ?>" type="checkbox" name="active" <?php echo (int)$code['active'] ? 'checked' : ''; ?>> Aktyvus</label>
                </td>
                <td class="inline-actions">
                  <button class="btn" form="<?php echo $formId; ?>" type="submit" style="padding:8px 12px;">Išsaugoti</button>
                  <form method="post" style="margin:0;">
                    <?php echo csrfField(); ?>
<input type="hidden" name="action" value="delete_discount_code">
                    <input type="hidden" name="id" value="<?php echo (int)$code['id']; ?>">
                    <button class="btn" type="submit" style="background:#f1f1f5; color:#0b0b0b; border-color:#e0e0ea; padding:8px 12px;">Šalinti</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$discountCodes): ?>
              <tr><td colspan="7" class="muted">Kodu dar nėra.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($view === 'categories'): ?>
      <div class="grid">
        <div class="card">
          <h3>Nauja kategorija</h3>
          <form method="post">
            <?php echo csrfField(); ?>
<input type="hidden" name="action" value="new_category">
            <input name="name" placeholder="Pavadinimas" required>
            <input name="slug" placeholder="Nuoroda (slug)" required>
            <button class="btn" type="submit">Išsaugoti</button>
          </form>
        </div>
        <div class="card" style="grid-column: span 2;">
          <h3>Visos kategorijos</h3>
          <table class="table-form">
            <thead><tr><th>Pavadinimas</th><th>Slug</th><th>Prekės</th><th>Veiksmai</th></tr></thead>
            <tbody>
              <?php foreach ($categoryCounts as $cat): ?>
                <tr>
                  <td><?php echo htmlspecialchars($cat['name']); ?></td>
                  <td><?php echo htmlspecialchars($cat['slug']); ?></td>
                  <td><?php echo (int)$cat['product_count']; ?></td>
                  <td class="inline-actions">
                    <a class="btn" href="/category_edit.php?id=<?php echo (int)$cat['id']; ?>" style="padding:8px 12px;">Redaguoti</a>
                    <form method="post" onsubmit="return confirm('Ištrinti kategoriją?');" style="margin:0;">
                      <?php echo csrfField(); ?>
<input type="hidden" name="action" value="delete_category">
                      <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                      <button class="btn" type="submit" style="background:#fff; color:#0b0b0b; padding:8px 12px;">Trinti</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($view === 'design'): ?>
      <div class="card" style="margin-bottom:18px;">
        <h3>Pagrindinio hero tekstai</h3>
        <p class="muted" style="margin-top:-4px;">Atnaujinkite titulinio puslapio antraštę, aprašymą ir mygtuko nuorodą.</p>
        <form method="post">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="hero_copy">
          <input name="hero_title" value="<?php echo htmlspecialchars($siteContent['hero_title'] ?? ''); ?>" placeholder="Antraštė">
          <textarea name="hero_body" rows="3" placeholder="Aprašymas"><?php echo htmlspecialchars($siteContent['hero_body'] ?? ''); ?></textarea>
          <div class="input-row">
            <input name="hero_cta_label" style="flex:1; min-width:200px;" value="<?php echo htmlspecialchars($siteContent['hero_cta_label'] ?? ''); ?>" placeholder="Mygtuko tekstas">
            <input name="hero_cta_url" style="flex:1; min-width:200px;" value="<?php echo htmlspecialchars($siteContent['hero_cta_url'] ?? ''); ?>" placeholder="Mygtuko nuoroda">
          </div>
          <button class="btn" type="submit">Išsaugoti</button>
        </form>
      </div>

      <div class="card" style="margin-bottom:18px;">
        <h3>Hero fonas ir media</h3>
        <p class="muted" style="margin-top:-4px;">Pasirinkite ar hero naudos spalvą, nuotrauką ar video. Įkeltos bylos saugomos /uploads aplanke.</p>
        <form method="post" enctype="multipart/form-data" class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="hero_media_update">
          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <label style="margin-top:0;">Fono tipas</label>
            <select name="hero_media_type">
              <?php $selectedType = $siteContent['hero_media_type'] ?? 'image'; ?>
              <option value="color" <?php echo $selectedType === 'color' ? 'selected' : ''; ?>>Spalva</option>
              <option value="image" <?php echo $selectedType === 'image' ? 'selected' : ''; ?>>Nuotrauka</option>
              <option value="video" <?php echo $selectedType === 'video' ? 'selected' : ''; ?>>Video</option>
            </select>
            <label>Spalva</label>
            <input name="hero_media_color" type="color" value="<?php echo htmlspecialchars($siteContent['hero_media_color'] ?? '#829ed6'); ?>">
            <label>Overlay (šešėlis) intensyvumas</label>
            <input name="hero_shadow_intensity" type="range" min="0" max="100" value="<?php echo (int)($siteContent['hero_shadow_intensity'] ?? 70); ?>" oninput="this.nextElementSibling.value=this.value">
            <output style="display:block; margin-top:4px; font-weight:600;"><?php echo (int)($siteContent['hero_shadow_intensity'] ?? 70); ?></output>
            <label>Alternatyvus tekstas</label>
            <input name="hero_media_alt" value="<?php echo htmlspecialchars($siteContent['hero_media_alt'] ?? ''); ?>" placeholder="Trumpas aprašymas">
          </div>

          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <p style="margin-top:0; font-weight:600;">Nuotrauka</p>
            <input type="hidden" name="hero_media_image_existing" value="<?php echo htmlspecialchars($siteContent['hero_media_image'] ?? ''); ?>">
            <input type="file" name="hero_media_image" accept="image/*">
            <?php if (!empty($siteContent['hero_media_image'])): ?>
              <p class="muted" style="margin:6px 0 0;">Dabartinis kelias: <?php echo htmlspecialchars($siteContent['hero_media_image']); ?></p>
            <?php endif; ?>
          </div>

          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <p style="margin-top:0; font-weight:600;">Video</p>
            <input type="hidden" name="hero_media_video_existing" value="<?php echo htmlspecialchars($siteContent['hero_media_video'] ?? ''); ?>">
            <input type="file" name="hero_media_video" accept="video/mp4,video/webm,video/quicktime">
            <?php if (!empty($siteContent['hero_media_video'])): ?>
              <p class="muted" style="margin:6px 0 0;">Dabartinis kelias: <?php echo htmlspecialchars($siteContent['hero_media_video']); ?></p>
            <?php endif; ?>
            <label style="margin-top:12px;">Plakatas (poster)</label>
            <input type="hidden" name="hero_media_poster_existing" value="<?php echo htmlspecialchars($siteContent['hero_media_poster'] ?? ''); ?>">
            <input type="file" name="hero_media_poster" accept="image/*">
            <?php if (!empty($siteContent['hero_media_poster'])): ?>
              <p class="muted" style="margin:6px 0 0;">Dabartinis plakatas: <?php echo htmlspecialchars($siteContent['hero_media_poster']); ?></p>
            <?php endif; ?>
          </div>

          <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Išsaugoti hero foną</button>
          </div>
        </form>
      </div>

      <div class="card" style="margin-bottom:18px;">
        <h3>Hero sekcijos</h3>
        <p class="muted" style="margin-top:-4px;">Tvarkykite kiekvieno puslapio hero tekstus ir, jei yra, kortelę.</p>
        <form method="post" class="grid" style="grid-template-columns: repeat(auto-fit, minmax(280px,1fr)); gap:12px;">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="page_hero_update">

          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <h4 style="margin:0 0 6px;">Naujienos</h4>
            <input name="news_hero_pill" value="<?php echo htmlspecialchars($siteContent['news_hero_pill'] ?? ''); ?>" placeholder="Piliulė">
            <input name="news_hero_title" value="<?php echo htmlspecialchars($siteContent['news_hero_title'] ?? ''); ?>" placeholder="Antraštė" style="margin-top:8px;">
            <textarea name="news_hero_body" rows="3" placeholder="Aprašymas" style="margin-top:8px;"><?php echo htmlspecialchars($siteContent['news_hero_body'] ?? ''); ?></textarea>
            <div class="input-row">
              <input name="news_hero_cta_label" value="<?php echo htmlspecialchars($siteContent['news_hero_cta_label'] ?? ''); ?>" placeholder="Mygtuko tekstas">
              <input name="news_hero_cta_url" value="<?php echo htmlspecialchars($siteContent['news_hero_cta_url'] ?? ''); ?>" placeholder="Nuoroda">
            </div>
            <label style="margin-top:10px;">Kortelė</label>
            <input name="news_hero_card_meta" value="<?php echo htmlspecialchars($siteContent['news_hero_card_meta'] ?? ''); ?>" placeholder="Meta">
            <input name="news_hero_card_title" value="<?php echo htmlspecialchars($siteContent['news_hero_card_title'] ?? ''); ?>" placeholder="Kortelės antraštė" style="margin-top:6px;">
            <textarea name="news_hero_card_body" rows="2" placeholder="Kortelės tekstas" style="margin-top:6px;"><?php echo htmlspecialchars($siteContent['news_hero_card_body'] ?? ''); ?></textarea>
          </div>

          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <h4 style="margin:0 0 6px;">Receptai</h4>
            <input name="recipes_hero_pill" value="<?php echo htmlspecialchars($siteContent['recipes_hero_pill'] ?? ''); ?>" placeholder="Piliulė">
            <input name="recipes_hero_title" value="<?php echo htmlspecialchars($siteContent['recipes_hero_title'] ?? ''); ?>" placeholder="Antraštė" style="margin-top:8px;">
            <textarea name="recipes_hero_body" rows="3" placeholder="Aprašymas" style="margin-top:8px;"><?php echo htmlspecialchars($siteContent['recipes_hero_body'] ?? ''); ?></textarea>
            <div class="input-row">
              <input name="recipes_hero_cta_label" value="<?php echo htmlspecialchars($siteContent['recipes_hero_cta_label'] ?? ''); ?>" placeholder="Mygtuko tekstas">
              <input name="recipes_hero_cta_url" value="<?php echo htmlspecialchars($siteContent['recipes_hero_cta_url'] ?? ''); ?>" placeholder="Nuoroda">
            </div>
            <label style="margin-top:10px;">Kortelė</label>
            <input name="recipes_hero_card_meta" value="<?php echo htmlspecialchars($siteContent['recipes_hero_card_meta'] ?? ''); ?>" placeholder="Meta">
            <input name="recipes_hero_card_title" value="<?php echo htmlspecialchars($siteContent['recipes_hero_card_title'] ?? ''); ?>" placeholder="Kortelės antraštė" style="margin-top:6px;">
            <textarea name="recipes_hero_card_body" rows="2" placeholder="Kortelės tekstas" style="margin-top:6px;"><?php echo htmlspecialchars($siteContent['recipes_hero_card_body'] ?? ''); ?></textarea>
          </div>

          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <h4 style="margin:0 0 6px;">DUK</h4>
            <input name="faq_hero_pill" value="<?php echo htmlspecialchars($siteContent['faq_hero_pill'] ?? ''); ?>" placeholder="Piliulė">
            <input name="faq_hero_title" value="<?php echo htmlspecialchars($siteContent['faq_hero_title'] ?? ''); ?>" placeholder="Antraštė" style="margin-top:8px;">
            <textarea name="faq_hero_body" rows="4" placeholder="Aprašymas" style="margin-top:8px;"><?php echo htmlspecialchars($siteContent['faq_hero_body'] ?? ''); ?></textarea>
          </div>

          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <h4 style="margin:0 0 6px;">Kontaktai</h4>
            <input name="contact_hero_pill" value="<?php echo htmlspecialchars($siteContent['contact_hero_pill'] ?? ''); ?>" placeholder="Piliulė">
            <input name="contact_hero_title" value="<?php echo htmlspecialchars($siteContent['contact_hero_title'] ?? ''); ?>" placeholder="Antraštė" style="margin-top:8px;">
            <textarea name="contact_hero_body" rows="3" placeholder="Aprašymas" style="margin-top:8px;"><?php echo htmlspecialchars($siteContent['contact_hero_body'] ?? ''); ?></textarea>
            <div class="input-row">
              <input name="contact_cta_primary_label" value="<?php echo htmlspecialchars($siteContent['contact_cta_primary_label'] ?? ''); ?>" placeholder="Pirmo mygtuko tekstas">
              <input name="contact_cta_primary_url" value="<?php echo htmlspecialchars($siteContent['contact_cta_primary_url'] ?? ''); ?>" placeholder="Nuoroda">
            </div>
            <div class="input-row" style="margin-top:6px;">
              <input name="contact_cta_secondary_label" value="<?php echo htmlspecialchars($siteContent['contact_cta_secondary_label'] ?? ''); ?>" placeholder="Antro mygtuko tekstas">
              <input name="contact_cta_secondary_url" value="<?php echo htmlspecialchars($siteContent['contact_cta_secondary_url'] ?? ''); ?>" placeholder="Nuoroda">
            </div>
            <label style="margin-top:10px;">Kortelė</label>
            <input name="contact_card_pill" value="<?php echo htmlspecialchars($siteContent['contact_card_pill'] ?? ''); ?>" placeholder="Piliulė">
            <input name="contact_card_title" value="<?php echo htmlspecialchars($siteContent['contact_card_title'] ?? ''); ?>" placeholder="Kortelės antraštė" style="margin-top:6px;">
            <textarea name="contact_card_body" rows="2" placeholder="Kortelės tekstas" style="margin-top:6px;"><?php echo htmlspecialchars($siteContent['contact_card_body'] ?? ''); ?></textarea>
          </div>

          <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Išsaugoti hero sekcijas</button>
          </div>
        </form>
      </div>

      <div class="card" style="margin-bottom:18px;">
        <h3>Promo kortelės</h3>
        <p class="muted" style="margin-top:-4px;">Redaguokite tris akcentus po hero: ikoną, pavadinimą ir tekstą.</p>
        <form method="post" class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); gap:12px;">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="promo_update">
          <?php for ($i = 1; $i <= 3; $i++): ?>
            <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
              <label style="margin-top:0;">Ikona #<?php echo $i; ?></label>
              <input name="promo_<?php echo $i; ?>_icon" value="<?php echo htmlspecialchars($siteContent['promo_' . $i . '_icon'] ?? ''); ?>" placeholder="Pvz. 24/7 arba ★">
              <label>Pavadinimas</label>
              <input name="promo_<?php echo $i; ?>_title" value="<?php echo htmlspecialchars($siteContent['promo_' . $i . '_title'] ?? ''); ?>" placeholder="Antraštė">
              <label>Aprašymas</label>
              <textarea name="promo_<?php echo $i; ?>_body" rows="3" placeholder="Tekstas"><?php echo htmlspecialchars($siteContent['promo_' . $i . '_body'] ?? ''); ?></textarea>
            </div>
          <?php endfor; ?>
          <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Išsaugoti promo korteles</button>
          </div>
        </form>
      </div>

      <div class="card" style="margin-bottom:18px;">
        <h3>Storyband</h3>
        <p class="muted" style="margin-top:-4px;">Tvarkykite titulinės juostos ženkliuką, tekstus, mygtuką ir tris metrinius rodiklius.</p>
        <form method="post" class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="storyband_update">
          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <label style="margin-top:0;">Ženkliukas</label>
            <input name="storyband_badge" value="<?php echo htmlspecialchars($siteContent['storyband_badge'] ?? ''); ?>" placeholder="Storyband ženkliukas">
            <label>Antraštė</label>
            <input name="storyband_title" value="<?php echo htmlspecialchars($siteContent['storyband_title'] ?? ''); ?>" placeholder="Antraštė">
            <label>Aprašymas</label>
            <textarea name="storyband_body" rows="4" placeholder="Tekstas"><?php echo htmlspecialchars($siteContent['storyband_body'] ?? ''); ?></textarea>
            <div class="input-row">
              <input name="storyband_cta_label" value="<?php echo htmlspecialchars($siteContent['storyband_cta_label'] ?? ''); ?>" placeholder="Mygtuko tekstas">
              <input name="storyband_cta_url" value="<?php echo htmlspecialchars($siteContent['storyband_cta_url'] ?? ''); ?>" placeholder="Mygtuko nuoroda">
            </div>
          </div>
          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <label style="margin-top:0;">Kortelės meta</label>
            <input name="storyband_card_eyebrow" value="<?php echo htmlspecialchars($siteContent['storyband_card_eyebrow'] ?? ''); ?>" placeholder="Reklaminis akcentas">
            <label>Kortelės antraštė</label>
            <input name="storyband_card_title" value="<?php echo htmlspecialchars($siteContent['storyband_card_title'] ?? ''); ?>" placeholder="„Cukrinukas“ rinkiniai">
            <label>Kortelės tekstas</label>
            <textarea name="storyband_card_body" rows="4" placeholder="Tekstas"><?php echo htmlspecialchars($siteContent['storyband_card_body'] ?? ''); ?></textarea>
          </div>
          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <label style="margin-top:0;">Metrika #1</label>
            <div class="input-row">
              <input name="storyband_metric_1_value" style="flex:1;" value="<?php echo htmlspecialchars($siteContent['storyband_metric_1_value'] ?? ''); ?>" placeholder="Reikšmė">
              <input name="storyband_metric_1_label" style="flex:1;" value="<?php echo htmlspecialchars($siteContent['storyband_metric_1_label'] ?? ''); ?>" placeholder="Pavadinimas">
            </div>
            <label>Metrika #2</label>
            <div class="input-row">
              <input name="storyband_metric_2_value" style="flex:1;" value="<?php echo htmlspecialchars($siteContent['storyband_metric_2_value'] ?? ''); ?>" placeholder="Reikšmė">
              <input name="storyband_metric_2_label" style="flex:1;" value="<?php echo htmlspecialchars($siteContent['storyband_metric_2_label'] ?? ''); ?>" placeholder="Pavadinimas">
            </div>
            <label>Metrika #3</label>
            <div class="input-row">
              <input name="storyband_metric_3_value" style="flex:1;" value="<?php echo htmlspecialchars($siteContent['storyband_metric_3_value'] ?? ''); ?>" placeholder="Reikšmė">
              <input name="storyband_metric_3_label" style="flex:1;" value="<?php echo htmlspecialchars($siteContent['storyband_metric_3_label'] ?? ''); ?>" placeholder="Pavadinimas">
            </div>
          </div>
          <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Išsaugoti storyband</button>
          </div>
        </form>
      </div>

      <div class="card" style="margin-bottom:18px;">
        <h3>Story-row</h3>
        <p class="muted" style="margin-top:-4px;">Valdykite eilutės turinį: tekstus, tris piliules ir abi dešinės pusės korteles.</p>
        <form method="post" class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="storyrow_update">
          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <label style="margin-top:0;">Antantraštė</label>
            <input name="storyrow_eyebrow" value="<?php echo htmlspecialchars($siteContent['storyrow_eyebrow'] ?? ''); ?>" placeholder="Dienos rutina">
            <label>Antraštė</label>
            <input name="storyrow_title" value="<?php echo htmlspecialchars($siteContent['storyrow_title'] ?? ''); ?>" placeholder="Stebėjimas, užkandžiai ir ramybė">
            <label>Aprašymas</label>
            <textarea name="storyrow_body" rows="4" placeholder="Tekstas"><?php echo htmlspecialchars($siteContent['storyrow_body'] ?? ''); ?></textarea>
            <label>Piliulės</label>
            <input name="storyrow_pill_1" value="<?php echo htmlspecialchars($siteContent['storyrow_pill_1'] ?? ''); ?>" placeholder="Pirmas punktas">
            <input name="storyrow_pill_2" value="<?php echo htmlspecialchars($siteContent['storyrow_pill_2'] ?? ''); ?>" placeholder="Antras punktas" style="margin-top:6px;">
            <input name="storyrow_pill_3" value="<?php echo htmlspecialchars($siteContent['storyrow_pill_3'] ?? ''); ?>" placeholder="Trečias punktas" style="margin-top:6px;">
          </div>
          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <label style="margin-top:0;">Dešinės kortelės meta</label>
            <input name="storyrow_bubble_meta" value="<?php echo htmlspecialchars($siteContent['storyrow_bubble_meta'] ?? ''); ?>" placeholder="Rekomendacija">
            <label>Kortelės antraštė</label>
            <input name="storyrow_bubble_title" value="<?php echo htmlspecialchars($siteContent['storyrow_bubble_title'] ?? ''); ?>" placeholder="„Cukrinukas“ specialistai">
            <label>Kortelės tekstas</label>
            <textarea name="storyrow_bubble_body" rows="4" placeholder="Tekstas"><?php echo htmlspecialchars($siteContent['storyrow_bubble_body'] ?? ''); ?></textarea>
          </div>
          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <label style="margin-top:0;">Plūduriuojančios kortelės meta</label>
            <input name="storyrow_floating_meta" value="<?php echo htmlspecialchars($siteContent['storyrow_floating_meta'] ?? ''); ?>" placeholder="Greitas pristatymas">
            <label>Kortelės antraštė</label>
            <input name="storyrow_floating_title" value="<?php echo htmlspecialchars($siteContent['storyrow_floating_title'] ?? ''); ?>" placeholder="1-2 d.d.">
            <label>Kortelės tekstas</label>
            <textarea name="storyrow_floating_body" rows="4" placeholder="Tekstas"><?php echo htmlspecialchars($siteContent['storyrow_floating_body'] ?? ''); ?></textarea>
          </div>
          <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Išsaugoti story-row</button>
          </div>
        </form>
      </div>

      <div class="card" style="margin-bottom:18px;">
        <h3>Support band</h3>
        <p class="muted" style="margin-top:-4px;">Atnaujinkite bendruomenės juostos tekstus, ženkliukus ir veiksmo mygtuką.</p>
        <form method="post" class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="support_update">
          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <label style="margin-top:0;">Meta</label>
            <input name="support_meta" value="<?php echo htmlspecialchars($siteContent['support_meta'] ?? ''); ?>" placeholder="Bendruomenė">
            <label>Antraštė</label>
            <input name="support_title" value="<?php echo htmlspecialchars($siteContent['support_title'] ?? ''); ?>" placeholder="Pagalba jums ir šeimai">
            <label>Aprašymas</label>
            <textarea name="support_body" rows="4" placeholder="Tekstas"><?php echo htmlspecialchars($siteContent['support_body'] ?? ''); ?></textarea>
            <label>Temos</label>
            <input name="support_chip_1" value="<?php echo htmlspecialchars($siteContent['support_chip_1'] ?? ''); ?>" placeholder="Pirmas akcentas">
            <input name="support_chip_2" value="<?php echo htmlspecialchars($siteContent['support_chip_2'] ?? ''); ?>" placeholder="Antras akcentas" style="margin-top:6px;">
            <input name="support_chip_3" value="<?php echo htmlspecialchars($siteContent['support_chip_3'] ?? ''); ?>" placeholder="Trečias akcentas" style="margin-top:6px;">
          </div>
          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <label style="margin-top:0;">Kortelės meta</label>
            <input name="support_card_meta" value="<?php echo htmlspecialchars($siteContent['support_card_meta'] ?? ''); ?>" placeholder="Gyva konsultacija">
            <label>Kortelės antraštė</label>
            <input name="support_card_title" value="<?php echo htmlspecialchars($siteContent['support_card_title'] ?? ''); ?>" placeholder="5 d. per savaitę">
            <label>Kortelės tekstas</label>
            <textarea name="support_card_body" rows="4" placeholder="Tekstas"><?php echo htmlspecialchars($siteContent['support_card_body'] ?? ''); ?></textarea>
            <div class="input-row" style="margin-top:6px;">
              <input name="support_card_cta_label" value="<?php echo htmlspecialchars($siteContent['support_card_cta_label'] ?? ''); ?>" placeholder="Mygtuko tekstas">
              <input name="support_card_cta_url" value="<?php echo htmlspecialchars($siteContent['support_card_cta_url'] ?? ''); ?>" placeholder="Mygtuko nuoroda">
            </div>
          </div>
          <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Išsaugoti support band</button>
          </div>
        </form>
      </div>

      <div class="card" style="margin-bottom:18px;">
        <h3>Reklamjuostė</h3>
        <p class="muted" style="margin-top:-4px;">Įjunkite viršutinę juostą ir suredaguokite tekstą, spalvą bei nuorodą.</p>
        <form method="post" class="input-row" style="flex-direction:column; gap:10px;">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="banner_update">
          <label style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="banner_enabled" <?php echo !empty($siteContent['banner_enabled']) && $siteContent['banner_enabled'] !== '0' ? 'checked' : ''; ?>>
            Rodyti reklamjuostę
          </label>
          <input name="banner_text" value="<?php echo htmlspecialchars($siteContent['banner_text'] ?? ''); ?>" placeholder="Tekstas">
          <input name="banner_link" value="<?php echo htmlspecialchars($siteContent['banner_link'] ?? ''); ?>" placeholder="Nuoroda (neprivaloma)">
          <label>Fono spalva</label>
          <input type="color" name="banner_background" value="<?php echo htmlspecialchars($siteContent['banner_background'] ?? '#829ed6'); ?>" style="width:120px; height:42px; padding:0;">
          <button class="btn" type="submit">Išsaugoti</button>
        </form>
      </div>

      <div class="card" style="margin-bottom:18px;">
        <h3>Atsiliepimai</h3>
        <p class="muted" style="margin-top:-4px;">Redaguokite 3 klientų istorijas, kurios rodomos tituliniame puslapyje.</p>
        <form method="post" class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); gap:12px;">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="testimonial_update">
          <?php for ($i = 1; $i <= 3; $i++): ?>
            <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
              <label style="margin-top:0;">Vardas/pareigos</label>
              <input name="testimonial_<?php echo $i; ?>_name" value="<?php echo htmlspecialchars($siteContent['testimonial_' . $i . '_name'] ?? ''); ?>" placeholder="Vardas">
              <label>Pozicija</label>
              <input name="testimonial_<?php echo $i; ?>_role" value="<?php echo htmlspecialchars($siteContent['testimonial_' . $i . '_role'] ?? ''); ?>" placeholder="Rolė">
              <label>Atsiliepimas</label>
              <textarea name="testimonial_<?php echo $i; ?>_text" rows="4" placeholder="Tekstas"><?php echo htmlspecialchars($siteContent['testimonial_' . $i . '_text'] ?? ''); ?></textarea>
            </div>
          <?php endfor; ?>
          <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Išsaugoti atsiliepimus</button>
          </div>
        </form>
      </div>

      <div class="card" style="grid-column: span 3;">
        <h3>Poraštės tekstas</h3>
        <p class="muted" style="margin-top:-4px;">Atnaujinkite trumpą aprašą, skilties pavadinimus ir kontaktinę informaciją.</p>
        <form method="post" class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); gap:12px;">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="footer_content">
          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <label>Pavadinimas</label>
            <input name="footer_brand_title" value="<?php echo htmlspecialchars($siteContent['footer_brand_title'] ?? ''); ?>" placeholder="Cukrinukas.lt">
            <label>Aprašas</label>
            <textarea name="footer_brand_body" rows="3" placeholder="Trumpas tekstas apie parduotuvę."><?php echo htmlspecialchars($siteContent['footer_brand_body'] ?? ''); ?></textarea>
            <label>Ženkliukas</label>
            <input name="footer_brand_pill" value="<?php echo htmlspecialchars($siteContent['footer_brand_pill'] ?? ''); ?>" placeholder="Kasdienė priežiūra">
          </div>
          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <label>Greitų nuorodų pavadinimas</label>
            <input name="footer_quick_title" value="<?php echo htmlspecialchars($siteContent['footer_quick_title'] ?? ''); ?>">
            <label>Pagalbos pavadinimas</label>
            <input name="footer_help_title" value="<?php echo htmlspecialchars($siteContent['footer_help_title'] ?? ''); ?>">
            <label>Kontaktų pavadinimas</label>
            <input name="footer_contact_title" value="<?php echo htmlspecialchars($siteContent['footer_contact_title'] ?? ''); ?>">
          </div>
          <div class="card" style="box-shadow:none; border:1px solid #e9e9f3;">
            <label>El. paštas</label>
            <input name="footer_contact_email" value="<?php echo htmlspecialchars($siteContent['footer_contact_email'] ?? ''); ?>" placeholder="info@cukrinukas.lt">
            <label>Tel.</label>
            <input name="footer_contact_phone" value="<?php echo htmlspecialchars($siteContent['footer_contact_phone'] ?? ''); ?>" placeholder="+370...">
            <label>Darbo laikas</label>
            <input name="footer_contact_hours" value="<?php echo htmlspecialchars($siteContent['footer_contact_hours'] ?? ''); ?>" placeholder="I–V 09:00–18:00">
          </div>
          <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Išsaugoti poraštę</button>
          </div>
        </form>
      </div>

      <div class="card" style="grid-column: span 3;">
        <h3>Poraštės nuorodos</h3>
        <p class="muted" style="margin-top:-4px;">Pridėkite arba redaguokite greitas nuorodas ir pagalbos meniu.</p>
        <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; align-items:flex-end;">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="footer_link_save">
          <div style="flex:1 1 160px; min-width:180px;">
            <label style="margin-top:0;">Pavadinimas</label>
            <input name="label" required placeholder="Pvz., Pristatymas">
          </div>
          <div style="flex:2 1 240px; min-width:220px;">
            <label style="margin-top:0;">Nuoroda</label>
            <input name="url" required placeholder="/shipping.php">
          </div>
          <div style="flex:1 1 140px; min-width:140px;">
            <label style="margin-top:0;">Skiltis</label>
            <select name="section">
              <option value="quick">Greitos nuorodos</option>
              <option value="help">Pagalba</option>
            </select>
          </div>
          <div style="flex:0 0 100px;">
            <label style="margin-top:0;">Eilė</label>
            <input name="sort_order" type="number" value="0" style="width:100%;">
          </div>
          <button class="btn" type="submit">Pridėti nuorodą</button>
        </form>
        <table>
          <thead><tr><th>Pavadinimas</th><th>Nuoroda</th><th>Skiltis</th><th>Eilė</th><th>Veiksmai</th></tr></thead>
          <tbody>
            <?php foreach (['quick' => 'Greitos nuorodos', 'help' => 'Pagalba'] as $sectionKey => $sectionLabel): ?>
              <?php foreach ($footerLinks[$sectionKey] ?? [] as $link): ?>
                <tr>
                  <td><?php echo htmlspecialchars($link['label']); ?></td>
                  <td><?php echo htmlspecialchars($link['url']); ?></td>
                  <td><?php echo htmlspecialchars($sectionLabel); ?></td>
                  <td><?php echo (int)$link['sort_order']; ?></td>
                  <td style="display:flex; gap:6px; flex-wrap:wrap;">
                    <form method="post" style="display:flex; gap:6px; flex-wrap:wrap;">
                      <?php echo csrfField(); ?>
<input type="hidden" name="action" value="footer_link_save">
                      <input type="hidden" name="id" value="<?php echo (int)$link['id']; ?>">
                      <input name="label" value="<?php echo htmlspecialchars($link['label']); ?>" style="width:140px; margin:0;">
                      <input name="url" value="<?php echo htmlspecialchars($link['url']); ?>" style="width:200px; margin:0;">
                      <select name="section" style="margin:0;">
                        <option value="quick" <?php echo $link['section'] === 'quick' ? 'selected' : ''; ?>>Greitos nuorodos</option>
                        <option value="help" <?php echo $link['section'] === 'help' ? 'selected' : ''; ?>>Pagalba</option>
                      </select>
                      <input type="number" name="sort_order" value="<?php echo (int)$link['sort_order']; ?>" style="width:80px;">
                      <button class="btn" type="submit">Atnaujinti</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Trinti nuorodą?');">
                      <?php echo csrfField(); ?>
<input type="hidden" name="action" value="footer_link_delete">
                      <input type="hidden" name="id" value="<?php echo (int)$link['id']; ?>">
                      <button class="btn" type="submit" style="background:#fff; color:#0b0b0b;">Trinti</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>

    <?php if ($view === 'content'): ?>
      <div class="card">
        <h3>Naujienos</h3>
        <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom:10px;">
          <p style="margin:0; color:#6b6b7a;">Redaguokite diabeto naujienų įrašus.</p>
          <a class="btn" href="/news_create.php">+ Nauja naujiena</a>
        </div>
        <table>
          <thead><tr><th>Pavadinimas</th><th>Data</th><th>Veiksmai</th></tr></thead>
          <tbody>
            <?php foreach ($newsList as $n): ?>
              <tr>
                <td><?php echo htmlspecialchars($n['title']); ?></td>
                <td><?php echo date('Y-m-d', strtotime($n['created_at'])); ?></td>
                <td><a class="btn" href="/news_edit.php?id=<?php echo (int)$n['id']; ?>">Redaguoti</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card" style="margin-top:18px;">
        <h3>Receptai</h3>
        <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom:10px;">
          <p style="margin:0; color:#6b6b7a;">Prižiūrėkite receptus ir jų turinį.</p>
          <a class="btn" href="/recipe_create.php">+ Naujas receptas</a>
        </div>
        <table>
          <thead><tr><th>Pavadinimas</th><th>Data</th><th>Veiksmai</th></tr></thead>
          <tbody>
            <?php foreach ($recipeList as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars($r['title']); ?></td>
                <td><?php echo date('Y-m-d', strtotime($r['created_at'])); ?></td>
                <td><a class="btn" href="/recipe_edit.php?id=<?php echo (int)$r['id']; ?>">Redaguoti</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($view === 'shipping'): ?>
      <?php $shipping = getShippingSettings($pdo); $lockerNetworks = getLockerNetworks($pdo); ?>
      <div class="card" style="max-width:640px;">
        <h3>Pristatymo kainos</h3>
        <p class="muted" style="margin-top:-4px;">Nustatykite atskiras kainas kurjeriui ir paštomatams bei ribą, nuo kurios pristatymas nemokamas.</p>
        <form method="post" class="input-row" style="flex-direction:column; gap:10px;">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="shipping_save">
          <label>Kurjerio kaina (€)</label>
          <input name="shipping_courier" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars($shipping['courier_price'] ?? $shipping['base_price'] ?? 3.99); ?>">
          <label>Paštomato kaina (€)</label>
          <input name="shipping_locker" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars($shipping['locker_price'] ?? 2.49); ?>">
          <label>Nemokamas pristatymas nuo sumos (€) (pasirinktinai)</label>
          <input name="shipping_free_over" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars($shipping['free_over'] ?? ''); ?>" placeholder="Palikite tuščią jei netaikoma">
          <button class="btn" type="submit">Išsaugoti</button>
        </form>
      </div>
      <div class="card" style="margin-top:16px;">
        <h3>Paštomatų tinklai</h3>
        <p class="muted" style="margin-top:-4px;">Pridėkite Omniva arba LP Express paštomatus rankiniu būdu arba importuokite iš .xlsx.</p>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:12px;">
          <form method="post" class="card" style="box-shadow:none; border:1px solid #ebeaf5;" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
<h4 style="margin-top:0;">Rankinis pridėjimas</h4>
            <input type="hidden" name="action" value="locker_new">
            <label>Tinklas</label>
            <select name="locker_provider" required>
              <option value="">Pasirinkite</option>
              <option value="omniva">Omniva</option>
              <option value="lpexpress">LP Express</option>
            </select>
            <label>Pavadinimas</label>
            <input name="locker_title" placeholder="Pvz. Vilnius Akropolis" required>
            <label>Adresas</label>
            <input name="locker_address" placeholder="Pvz. Ozo g. 25, Vilnius" required>
            <label>Pastabos (pasirinktinai)</label>
            <textarea name="locker_note" rows="2" placeholder="Papildoma informacija pirkėjui."></textarea>
            <button class="btn" type="submit">Pridėti paštomatą</button>
          </form>

          <form method="post" class="card" style="box-shadow:none; border:1px solid #ebeaf5;" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
<h4 style="margin-top:0;">Importas iš .xlsx</h4>
            <input type="hidden" name="action" value="locker_import">
            <label>Tinklas</label>
            <select name="locker_provider" required>
              <option value="">Pasirinkite</option>
              <option value="omniva">Omniva</option>
              <option value="lpexpress">LP Express</option>
            </select>
            <label>.xlsx failas</label>
            <input type="file" name="locker_file" accept=".xlsx" required>
            <p class="muted" style="font-size:13px;">Omniva stulpeliai: Pašto kodas, Pavadinimas, Šalis, Apskritis, Savivaldybė, Miestas, Gatvė, Namo nr, X, Y, Papildomai. LP Express stulpeliai: Miestas, ID, Pavadinimas, Adresas, Pašto kodas, Platuma, Ilguma, Pastabos. Išsaugomi tik pavadinimai, adresai ir pastabos.</p>
            <button class="btn" type="submit">Importuoti paštomatus</button>
          </form>
        </div>
      </div>
      <div class="card" style="margin-top:16px;">
        <h3>Nemokamo pristatymo pasiūlymai</h3>
        <p class="muted" style="margin-top:-4px;">Pasirinkite iki 4 prekių, kurių įsigijus pirkėjui automatiškai suteikiamas nemokamas pristatymas.</p>
        <form method="post" class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="shipping_free_products">
          <?php for ($i = 0; $i < 4; $i++): $current = $freeShippingProductIds[$i] ?? ''; ?>
            <label style="display:flex; flex-direction:column; gap:8px;">
              <span style="font-weight:600; color:#0f172a;">Prekė #<?php echo $i + 1; ?></span>
              <select name="promo_products[]" style="padding:10px 12px; border-radius:12px; border:1px solid #e6e6ef;">
                <option value="">— Nepasirinkta —</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?php echo (int)$p['id']; ?>" <?php echo (int)$current === (int)$p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['title']); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php endfor; ?>
          <div style="grid-column: 1/-1; display:flex; justify-content: flex-end;">
            <button class="btn" type="submit">Išsaugoti pasiūlymus</button>
          </div>
        </form>
      </div>
      <div class="card" style="margin-top:16px;">
        <h3>Esami paštomatai</h3>
        <?php if (!$lockerNetworks): ?>
          <p class="muted">Paštomatų dar nėra. Įkelkite failą arba pridėkite rankiniu būdu.</p>
        <?php else: ?>
          <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:12px;">
            <?php foreach ($lockerNetworks as $providerKey => $list): ?>
              <div class="card" style="box-shadow:none; border:1px solid #ebeaf5;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                  <strong style="text-transform:uppercase; font-size:13px; letter-spacing:0.04em; color:#4b5563;">
                    <?php echo htmlspecialchars($providerKey === 'omniva' ? 'Omniva' : 'LP Express'); ?>
                  </strong>
                  <span class="muted" style="font-size:12px;">Iš viso: <?php echo count($list); ?></span>
                </div>
                  <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:6px; max-height:260px; overflow:auto;">
                    <?php foreach ($list as $loc): ?>
                      <li style="padding:8px; border:1px solid #f0f0f5; border-radius:10px;">
                        <form method="post" style="display:flex; flex-direction:column; gap:6px;">
                          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="locker_update">
                          <input type="hidden" name="locker_id" value="<?php echo (int)$loc['id']; ?>">
                          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap:6px; align-items:center;">
                            <select name="locker_provider" style="padding:8px 10px; border-radius:8px; border:1px solid #e6e6ef; background:#fff;">
                              <option value="omniva" <?php echo $loc['provider'] === 'omniva' ? 'selected' : ''; ?>>Omniva</option>
                              <option value="lpexpress" <?php echo $loc['provider'] === 'lpexpress' ? 'selected' : ''; ?>>LP Express</option>
                            </select>
                            <input name="locker_title" value="<?php echo htmlspecialchars($loc['title']); ?>" placeholder="Pavadinimas" style="padding:8px 10px; border-radius:8px; border:1px solid #e6e6ef;">
                          </div>
                          <input name="locker_address" value="<?php echo htmlspecialchars($loc['address']); ?>" placeholder="Adresas" style="padding:8px 10px; border-radius:8px; border:1px solid #e6e6ef;">
                          <textarea name="locker_note" rows="2" placeholder="Pastabos (pasirinktinai)" style="padding:8px 10px; border-radius:8px; border:1px solid #e6e6ef; resize:vertical;"><?php echo htmlspecialchars($loc['note'] ?? ''); ?></textarea>
                          <div style="display:flex; justify-content:flex-end;">
                            <button class="btn" type="submit" style="width:auto; padding:8px 14px; font-size:13px;">Atnaujinti</button>
                          </div>
                        </form>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endforeach; ?>
            </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($view === 'menus'): ?>
      <div class="grid">
        <div class="card">
          <h3>Naujas meniu punktas</h3>
          <form method="post">
            <?php echo csrfField(); ?>
<input type="hidden" name="action" value="nav_new">
            <input name="label" placeholder="Pavadinimas" required>
            <input name="url" placeholder="Nuoroda" required>
            <select name="parent_id">
              <option value="">Be tėvinio</option>
              <?php foreach ($navItems as $item): if ($item['parent_id']) continue; ?>
                <option value="<?php echo (int)$item['id']; ?>"><?php echo htmlspecialchars($item['label']); ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">Išsaugoti</button>
          </form>
        </div>
        <div class="card" style="grid-column: span 2;">
          <h3>Visi meniu punktai</h3>
          <table>
            <thead><tr><th>Pavadinimas</th><th>Nuoroda</th><th>Tėvinis</th><th>Veiksmai</th></tr></thead>
            <tbody>
              <?php foreach ($navItems as $item): ?>
                <tr>
                  <td><?php echo htmlspecialchars($item['label']); ?></td>
                  <td><?php echo htmlspecialchars($item['url']); ?></td>
                  <td>
                    <?php
                      $parent = null;
                      foreach ($navItems as $p) { if ($p['id'] == $item['parent_id']) { $parent = $p; break; } }
                      echo $parent ? htmlspecialchars($parent['label']) : '—';
                    ?>
                  </td>
                  <td style="display:flex; gap:6px;">
                    <form method="post" style="display:flex; gap:6px; flex-wrap:wrap;">
                      <?php echo csrfField(); ?>
<input type="hidden" name="action" value="nav_update">
                      <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                      <input name="label" value="<?php echo htmlspecialchars($item['label']); ?>" style="width:140px; margin:0;">
                      <input name="url" value="<?php echo htmlspecialchars($item['url']); ?>" style="width:200px; margin:0;">
                      <select name="parent_id" style="margin:0;">
                        <option value="">Be tėvinio</option>
                        <?php foreach ($navItems as $p): if ($p['parent_id']) continue; ?>
                          <option value="<?php echo (int)$p['id']; ?>" <?php echo $item['parent_id'] == $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['label']); ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn" type="submit">Atnaujinti</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Trinti meniu punktą?');" style="margin:0;">
                      <?php echo csrfField(); ?>
<input type="hidden" name="action" value="nav_delete">
                      <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                      <button class="btn" type="submit" style="background:#fff; color:#0b0b0b;">Trinti</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="card" style="grid-column: span 2;">
          <h3>Bendras rikiavimas</h3>
          <form method="post" style="display:flex;flex-direction:column;gap:12px;">
            <?php echo csrfField(); ?>
<input type="hidden" name="action" value="nav_reorder">
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;align-items:center;">
              <strong>Pavadinimas</strong><strong>Tėvas</strong><strong>Eilė</strong>
              <?php foreach ($navItems as $item): ?>
                <div><?php echo htmlspecialchars($item['label']); ?></div>
                <div>
                  <?php
                    $parent = null;
                    foreach ($navItems as $p) { if ($p['id'] == $item['parent_id']) { $parent = $p; break; } }
                    echo $parent ? htmlspecialchars($parent['label']) : '—';
                  ?>
                </div>
                <input type="number" name="order[<?php echo (int)$item['id']; ?>]" value="<?php echo (int)$item['sort_order']; ?>" style="width:80px;">
              <?php endforeach; ?>
            </div>
            <button class="btn" type="submit">Išsaugoti rikiavimą</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($view === 'products'): ?>
      <div class="card">
        <h3>Nauja prekė</h3>
        <form method="post" enctype="multipart/form-data">
          <?php echo csrfField(); ?>
<input type="hidden" name="action" value="new_product">
          <input name="title" placeholder="Pavadinimas" required>
          <input name="subtitle" placeholder="Paantraštė">
          <textarea name="description" placeholder="Aprašymas" rows="3" required></textarea>
          <input name="ribbon_text" placeholder="Juostelė ant nuotraukos (nebūtina)">
          <input name="price" type="number" step="0.01" placeholder="Kaina" required>
          <input name="sale_price" type="number" step="0.01" placeholder="Kaina su nuolaida (nebūtina)">
          <input name="quantity" type="number" min="0" placeholder="Kiekis" required>
          <select name="category_id">
            <option value="">Be kategorijos</option>
            <?php foreach ($categoryCounts as $cat): ?>
              <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <input name="meta_tags" placeholder="Žymės / SEO tagai">
          <label>Susijusios prekės</label>
          <select name="related_products[]" multiple size="4">
            <?php foreach ($products as $product): ?>
              <option value="<?php echo (int)$product['id']; ?>"><?php echo htmlspecialchars($product['title']); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="card" style="margin-top:10px;">
            <h4>Papildomi laukeliai</h4>
            <div id="attrs-create" class="input-row">
              <input class="chip-input" name="attr_label[]" placeholder="Laukelio pavadinimas">
              <input class="chip-input" name="attr_value[]" placeholder="Aprašymas">
            </div>
            <button type="button" class="btn" style="margin-top:8px; background:#fff; color:#0b0b0b; border-color:#d7d7e2;" onclick="addAttrRow('attrs-create')">+ Pridėti laukelį</button>
          </div>
          <div class="card" style="margin-top:10px;">
            <h4>Variacijos</h4>
            <div id="vars-create" class="input-row">
              <input class="chip-input" name="variation_name[]" placeholder="Variacijos pavadinimas">
              <input class="chip-input" name="variation_price[]" type="number" step="0.01" placeholder="Kainos pokytis">
            </div>
            <button type="button" class="btn" style="margin-top:8px; background:#fff; color:#0b0b0b; border-color:#d7d7e2;" onclick="addVarRow('vars-create')">+ Pridėti variaciją</button>
          </div>
          <label>Nuotraukos (galite pasirinkti kelias)</label>
          <input type="file" name="images[]" multiple accept="image/*">
          <button class="btn" type="submit">Sukurti</button>
        </form>
      </div>
      <div class="card" style="margin-top:18px;">
        <h3>Prekių sąrašas</h3>
        <table>
          <thead><tr><th>Pavadinimas</th><th>Kategorija</th><th>Kaina</th><th>Kiekis</th><th>Veiksmai</th></tr></thead>
          <tbody>
            <?php foreach ($products as $product): ?>
              <tr>
                <td><?php echo htmlspecialchars($product['title']); ?></td>
                <td><?php echo htmlspecialchars($product['category_name'] ?? ''); ?></td>
                <td><?php echo number_format((float)$product['price'], 2); ?> €</td>
                <td><?php echo (int)$product['quantity']; ?> vnt</td>
                <td style="display:flex; gap:8px;">
                  <a class="btn" href="/product_edit.php?id=<?php echo (int)$product['id']; ?>">Redaguoti</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:16px; display:grid; gap:12px;">
          <h4>Parinkite 3 prekes pagrindiniam puslapiui</h4>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <?php foreach ($featuredProducts as $fp): ?>
              <div style="border:1px solid #e6e6ef; border-radius:12px; padding:10px 12px; background:#f9f9ff; display:flex; align-items:center; gap:10px;">
                <div>
                  <strong><?php echo htmlspecialchars($fp['title']); ?></strong><br>
                  <span class="muted"><?php echo number_format((float)$fp['price'], 2); ?> €</span>
                </div>
                <form method="post" style="margin:0;">
                  <?php echo csrfField(); ?>
<input type="hidden" name="action" value="featured_remove">
                  <input type="hidden" name="remove_id" value="<?php echo (int)$fp['id']; ?>">
                  <button class="btn" type="submit" style="background:#fff; color:#0b0b0b;">Atžymėti</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if (count($featuredProducts) < 3): ?>
            <form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
              <?php echo csrfField(); ?>
<input type="hidden" name="action" value="featured_add">
              <input name="featured_query" list="products-list" placeholder="Įveskite prekės pavadinimą" style="flex:1; min-width:240px; margin:0;">
              <datalist id="products-list">
                <?php foreach ($products as $product): ?>
                  <option value="<?php echo htmlspecialchars($product['title']); ?>"></option>
                <?php endforeach; ?>
              </datalist>
              <button class="btn" type="submit">Pridėti</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($view === 'users'): ?>
      <div class="card">
        <h3>Vartotojų valdymas</h3>
        <table class="table-form">
          <thead><tr><th>Vardas</th><th>El. paštas</th><th>Rolė</th><th>Užsakymai</th><th>Krepšelis</th><th>Veiksmai</th></tr></thead>
          <tbody>
            <?php foreach ($users as $user):
              $orderCountStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
              $orderCountStmt->execute([$user['id']]);
              $orderCount = (int)$orderCountStmt->fetchColumn();
              $ordersMini = $pdo->prepare('SELECT id, total, status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 3');
              $ordersMini->execute([$user['id']]);
              $orderRows = $ordersMini->fetchAll();
              $cartSnapshot = getUserCartSnapshot($pdo, (int)$user['id']);
              $userFormId = 'userform' . (int)$user['id'];
            ?>
              <form id="<?php echo $userFormId; ?>" method="post"></form>
              <tr>
                <td>
                  <input type="hidden" form="<?php echo $userFormId; ?>" name="action" value="edit_user">
                  <input type="hidden" form="<?php echo $userFormId; ?>" name="user_id" value="<?php echo (int)$user['id']; ?>">
                  <input form="<?php echo $userFormId; ?>" name="name" value="<?php echo htmlspecialchars($user['name']); ?>">
                </td>
                <td><input form="<?php echo $userFormId; ?>" name="email" value="<?php echo htmlspecialchars($user['email']); ?>"></td>
                <td style="min-width:120px;"><?php echo $user['is_admin'] ? 'Admin' : 'Vartotojas'; ?></td>
                <td>
                  <div><strong><?php echo $orderCount; ?></strong> vnt.</div>
                  <?php if ($orderRows): ?>
                    <ul style="margin:4px 0 0; padding-left:18px;">
                      <?php foreach ($orderRows as $o): ?>
                        <li>#<?php echo (int)$o['id']; ?> — <?php echo number_format((float)$o['total'], 2); ?> € (<?php echo htmlspecialchars($o['status']); ?>)</li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($cartSnapshot): ?>
                    <ul style="margin:0; padding-left:18px;">
                      <?php foreach ($cartSnapshot as $c): ?>
                        <li><?php echo htmlspecialchars($c['title']); ?> (<?php echo $c['quantity']; ?> vnt)</li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <span class="muted">Tuščias</span>
                  <?php endif; ?>
                </td>
                <td class="inline-actions">
                  <button class="btn" form="<?php echo $userFormId; ?>" type="submit" style="padding:8px 12px;">Išsaugoti</button>
                  <?php if ($user['id'] !== ($_SESSION['user_id'] ?? null)): ?>
                    <form method="post" style="margin:0;">
                      <?php echo csrfField(); ?>
<input type="hidden" name="action" value="toggle_admin">
                      <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                      <button class="btn" type="submit" style="background:#fff; color:#0b0b0b; padding:8px 12px;">Perjungti admin</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <script>
    function addAttrRow(targetId){
      const wrap = document.getElementById(targetId);
      if(!wrap) return;
      const name = document.createElement('input');
      name.name = 'attr_label[]';
      name.className = 'chip-input';
      name.placeholder = 'Laukelio pavadinimas';
      const val = document.createElement('input');
      val.name = 'attr_value[]';
      val.className = 'chip-input';
      val.placeholder = 'Aprašymas';
      wrap.appendChild(name);
      wrap.appendChild(val);
    }
    function addVarRow(targetId){
      const wrap = document.getElementById(targetId);
      if(!wrap) return;
      const name = document.createElement('input');
      name.name = 'variation_name[]';
      name.className = 'chip-input';
      name.placeholder = 'Variacijos pavadinimas';
      const price = document.createElement('input');
      price.name = 'variation_price[]';
      price.type = 'number';
      price.step = '0.01';
      price.className = 'chip-input';
      price.placeholder = 'Kainos pokytis';
      wrap.appendChild(name);
      wrap.appendChild(price);
    }
    document.querySelectorAll('[data-toggle-select]').forEach(function(input){
      const selectName = input.getAttribute('data-toggle-select');
      let select = input.closest('form')?.querySelector('select[name="' + selectName + '"]');
      if (!select && input.getAttribute('form')) {
        const f = document.getElementById(input.getAttribute('form'));
        if (f && f.elements[selectName]) {
          select = f.elements[selectName];
        }
      }
      if (!select) {
        select = document.querySelector('select[name="' + selectName + '"]');
      }
      const toggle = function(){
        if (!select) return;
        const v = select.value;
        const disable = (v === 'free_shipping' || v === 'none');
        input.disabled = disable;
        if (disable) { input.value = '0'; }
      };
      if (select) {
        select.addEventListener('change', toggle);
        toggle();
      }
    });
  </script>

  <?php renderFooter($pdo); ?>
</body>
</html>
