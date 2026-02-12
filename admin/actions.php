<?php
// admin/actions.php

// Pagalbinė funkcija nukreipimui
if (!function_exists('redirectWithMsg')) {
    function redirectWithMsg($view, $msg, $type = 'success') {
        if ($type === 'success') {
            $_SESSION['flash_success'] = $msg;
        } else {
            $_SESSION['flash_error'] = $msg;
        }
        header("Location: /admin.php?view=" . $view);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // CSRF apsauga
    if (function_exists('validateCsrfToken')) {
        validateCsrfToken();
    }
    
    $action = $_POST['action'] ?? '';

    // --- NUOLAIDOS IR AKCIJOS ---
    if ($action === 'save_global_discount') {
        $type = $_POST['type'] ?? 'none';
        $value = (float)($_POST['value'] ?? 0);
        saveGlobalDiscount($pdo, $type, $value, $type === 'free_shipping');
        redirectWithMsg('discounts', 'Bendra nuolaida išsaugota');
    }

    if ($action === 'save_discount_code') {
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
        $code = trim($_POST['code'] ?? '');
        $type = $_POST['type'] ?? 'percent';
        $value = (float)($_POST['value'] ?? 0);
        $usageLimit = (int)($_POST['usage_limit'] ?? 0);
        $active = isset($_POST['active']);

        if ($code === '') {
            redirectWithMsg('discounts', 'Įveskite nuolaidos kodą.', 'error');
        }
        
        $freeShipping = ($type === 'free_shipping');
        saveDiscountCodeEntry($pdo, $id, strtoupper($code), $type, $value, $usageLimit, $active, $freeShipping);
        redirectWithMsg('discounts', $id ? 'Kodas atnaujintas' : 'Kodas sukurtas');
    }

    if ($action === 'delete_discount_code') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) deleteDiscountCode($pdo, $id);
        redirectWithMsg('discounts', 'Kodas pašalintas');
    }

    if ($action === 'save_category_discount') {
        $catId = (int)$_POST['category_id'];
        $type = $_POST['discount_type'];
        $value = (float)$_POST['discount_value'];
        $freeShipping = ($type === 'free_shipping');
        
        if ($catId) {
            saveCategoryDiscount($pdo, $catId, $type, $value, $freeShipping, true);
            redirectWithMsg('discounts', 'Kategorijos akcija išsaugota');
        } else {
            redirectWithMsg('discounts', 'Pasirinkite kategoriją', 'error');
        }
    }

    if ($action === 'remove_category_discount') {
        $catId = (int)$_POST['category_id'];
        if ($catId) deleteCategoryDiscount($pdo, $catId);
        redirectWithMsg('discounts', 'Nuolaida pašalinta');
    }

    // --- KATEGORIJOS ---
    if ($action === 'new_category') {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        if ($name && $slug) {
            $stmt = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)');
            $stmt->execute([$name, $slug]);
            redirectWithMsg('categories', 'Kategorija pridėta');
        }
        redirectWithMsg('categories', 'Įveskite kategorijos pavadinimą ir nuorodą', 'error');
    }

    if ($action === 'edit_category') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        if ($id && $name && $slug) {
            $stmt = $pdo->prepare('UPDATE categories SET name = ?, slug = ? WHERE id = ?');
            $stmt->execute([$name, $slug, $id]);
            redirectWithMsg('categories', 'Kategorija atnaujinta');
        }
        redirectWithMsg('categories', 'Klaida atnaujinant kategoriją', 'error');
    }

    if ($action === 'delete_category') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            redirectWithMsg('categories', 'Kategorija ištrinta');
        }
        redirectWithMsg('categories', 'Neteisingas ID', 'error');
    }

    // --- PREKĖS IR FEATURED ---
    
    if ($action === 'toggle_featured') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $setFeatured = (int)($_POST['set_featured'] ?? 0);
        
        if ($pid) {
            if ($setFeatured == 0) {
                $pdo->prepare("DELETE FROM featured_products WHERE product_id = ?")->execute([$pid]);
                redirectWithMsg('products', 'Prekė pašalinta iš pagrindinio puslapio');
            } else {
                $count = $pdo->query("SELECT COUNT(*) FROM featured_products")->fetchColumn();
                $maxPos = (int)$pdo->query("SELECT MAX(position) FROM featured_products")->fetchColumn();
                
                $stmt = $pdo->prepare("SELECT id FROM featured_products WHERE product_id = ?");
                $stmt->execute([$pid]);
                if (!$stmt->fetch()) {
                    $pdo->prepare("INSERT INTO featured_products (product_id, position, created_at) VALUES (?, ?, NOW())")->execute([$pid, $maxPos + 1]);
                    redirectWithMsg('products', 'Prekė pažymėta kaip Featured');
                } else {
                    redirectWithMsg('products', 'Prekė jau yra sąraše', 'error');
                }
            }
        }
        redirectWithMsg('products', 'Prekė nerasta', 'error');
    }

    if ($action === 'add_featured_by_name') {
        $title = trim($_POST['featured_title'] ?? '');
        if ($title) {
            $stmt = $pdo->prepare("SELECT id FROM products WHERE title = ? LIMIT 1");
            $stmt->execute([$title]);
            $pid = $stmt->fetchColumn();
            
            if ($pid) {
                $stmtExists = $pdo->prepare("SELECT id FROM featured_products WHERE product_id = ?");
                $stmtExists->execute([$pid]);
                if (!$stmtExists->fetch()) {
                    $maxPos = (int)$pdo->query("SELECT MAX(position) FROM featured_products")->fetchColumn();
                    $pdo->prepare("INSERT INTO featured_products (product_id, position, created_at) VALUES (?, ?, NOW())")->execute([$pid, $maxPos + 1]);
                    redirectWithMsg('products', 'Prekė pridėta į pagrindinį puslapį');
                } else {
                    redirectWithMsg('products', 'Ši prekė jau yra sąraše', 'error');
                }
            } else {
                redirectWithMsg('products', 'Prekė nerasta. Patikrinkite pavadinimą', 'error');
            }
        }
        redirectWithMsg('products', 'Įveskite pavadinimą', 'error');
    }

    if ($action === 'save_product') {
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
        
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $ribbon = trim($_POST['ribbon_text'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $salePrice = isset($_POST['sale_price']) && $_POST['sale_price'] !== '' ? (float)$_POST['sale_price'] : null;
        $qty = (int)($_POST['quantity'] ?? 0);
        $metaTags = trim($_POST['meta_tags'] ?? '');
        
        $isFeatured = isset($_POST['is_featured']) ? true : false;
        
        $cats = $_POST['categories'] ?? [];
        $primaryCatId = !empty($cats) ? (int)$cats[0] : null;

        if (!$title) redirectWithMsg('products', 'Trūksta prekės pavadinimo', 'error');

        try {
            if ($id) {
                // Update PRODUCTS
                $stmt = $pdo->prepare('UPDATE products SET category_id=?, title=?, subtitle=?, description=?, ribbon_text=?, price=?, sale_price=?, quantity=?, meta_tags=? WHERE id=?');
                $stmt->execute([$primaryCatId, $title, $subtitle ?: null, $description, $ribbon ?: null, $price, $salePrice, $qty, $metaTags ?: null, $id]);
                $productId = $id;
                $msg = 'Prekė atnaujinta';
            } else {
                // Create PRODUCTS
                $stmt = $pdo->prepare('INSERT INTO products (category_id, title, subtitle, description, ribbon_text, image_url, price, sale_price, quantity, meta_tags) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $primaryCatId, $title, $subtitle ?: null, $description, $ribbon ?: null,
                    'https://placehold.co/600x400?text=Preke', 
                    $price, $salePrice, $qty, $metaTags ?: null
                ]);
                $productId = (int)$pdo->lastInsertId();
                $msg = 'Prekė sukurta';
            }

            // --- SYNC FEATURED ---
            if ($isFeatured) {
                $exists = $pdo->prepare("SELECT id FROM featured_products WHERE product_id = ?");
                $exists->execute([$productId]);
                if (!$exists->fetch()) {
                     $maxPos = (int)$pdo->query("SELECT MAX(position) FROM featured_products")->fetchColumn();
                     $pdo->prepare("INSERT INTO featured_products (product_id, position, created_at) VALUES (?, ?, NOW())")->execute([$productId, $maxPos + 1]);
                }
            } else {
                $pdo->prepare("DELETE FROM featured_products WHERE product_id = ?")->execute([$productId]);
            }

            // Categories relations
            $pdo->prepare("DELETE FROM product_category_relations WHERE product_id = ?")->execute([$productId]);
            if (!empty($cats)) {
                $insCat = $pdo->prepare("INSERT INTO product_category_relations (product_id, category_id) VALUES (?, ?)");
                foreach ($cats as $cid) {
                    $insCat->execute([$productId, (int)$cid]);
                }
            }

            // Images
            if (!empty($_FILES['images']['name'][0])) {
                if (function_exists('storeUploads')) {
                    storeUploads($pdo, $productId, $_FILES['images']);
                }
            }
            
            // Primary Image change
            if (isset($_POST['primary_image_id']) && function_exists('setPrimaryImage')) {
                setPrimaryImage($pdo, $productId, (int)$_POST['primary_image_id']);
            }

            // Delete images
            if (!empty($_POST['delete_images']) && function_exists('deleteProductImage')) {
                foreach ($_POST['delete_images'] as $delImgId) {
                    deleteProductImage($pdo, $productId, (int)$delImgId);
                }
            }

            // Related Products
            $pdo->prepare('DELETE FROM product_related WHERE product_id = ?')->execute([$productId]);
            $related = array_filter(array_map('intval', $_POST['related_products'] ?? []));
            if ($related) {
                $insertRel = $pdo->prepare('INSERT IGNORE INTO product_related (product_id, related_product_id) VALUES (?, ?)');
                foreach ($related as $rel) {
                    if ($rel !== $productId) $insertRel->execute([$productId, $rel]);
                }
            }

            // Attributes
            $pdo->prepare('DELETE FROM product_attributes WHERE product_id = ?')->execute([$productId]);
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

            // --- VARIATIONS ---
            $pdo->prepare('DELETE FROM product_variations WHERE product_id = ?')->execute([$productId]);
            
            $postedVariations = $_POST['variations'] ?? [];
            
            if (is_array($postedVariations)) {
                // ATNAUJINTA UŽKLAUSA SU TRACK_PRICE ir TRACK_STOCK
                $insertVar = $pdo->prepare('INSERT INTO product_variations (product_id, group_name, name, price_delta, quantity, image_id, track_price, track_stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                
                foreach ($postedVariations as $group) {
                    $groupName = trim($group['group_name'] ?? '');
                    
                    if(!empty($group['items']) && is_array($group['items'])) {
                        foreach($group['items'] as $item) {
                            $vName = trim($item['name'] ?? '');
                            if (!$vName) continue;
                            
                            $delta = isset($item['price']) && $item['price'] !== '' ? (float)$item['price'] : 0;
                            $vQty = isset($item['qty']) && $item['qty'] !== '' ? (int)$item['qty'] : 0;
                            $vImgId = !empty($item['image_id']) ? (int)$item['image_id'] : null;
                            
                            // Tikriname, ar pažymėti checkbox'ai
                            $trackPrice = isset($item['track_price']) ? 1 : 0;
                            $trackStock = isset($item['track_stock']) ? 1 : 0;
                            
                            $insertVar->execute([$productId, $groupName, $vName, $delta, $vQty, $vImgId, $trackPrice, $trackStock]);
                        }
                    }
                }
            }

            redirectWithMsg('products', $msg);
        
        } catch (Exception $e) {
            redirectWithMsg('products', 'Klaida saugant prekę: ' . $e->getMessage(), 'error');
        }
    }

    if ($action === 'bulk_delete_products') {
        $ids = $_POST['selected_ids'] ?? [];
        if (!empty($ids) && is_array($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM product_attributes WHERE product_id IN ($placeholders)")->execute($ids);
            $pdo->prepare("DELETE FROM product_variations WHERE product_id IN ($placeholders)")->execute($ids);
            $pdo->prepare("DELETE FROM product_category_relations WHERE product_id IN ($placeholders)")->execute($ids);
            $pdo->prepare("DELETE FROM featured_products WHERE product_id IN ($placeholders)")->execute($ids);
            $pdo->prepare("DELETE FROM product_related WHERE product_id IN ($placeholders)")->execute($ids);
            $pdo->prepare("DELETE FROM products WHERE id IN ($placeholders)")->execute($ids);
            
            redirectWithMsg('products', 'Ištrinta prekių: ' . count($ids));
        }
        redirectWithMsg('products', 'Nepasirinkote prekių', 'error');
    }

    // --- VARTOTOJAI IR UŽSAKYMAI ---
    if ($action === 'toggle_admin') {
        $userId = (int)$_POST['user_id'];
        $pdo->prepare('UPDATE users SET is_admin = IF(is_admin=1,0,1) WHERE id = ?')->execute([$userId]);
        redirectWithMsg('users', 'Vartotojo teisės atnaujintos');
    }

    if ($action === 'edit_user') {
        $userId = (int)$_POST['user_id'];
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($userId && $name && $email) {
            $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $userId]);
            redirectWithMsg('users', 'Vartotojas atnaujintas');
        }
        redirectWithMsg('users', 'Trūksta duomenų', 'error');
    }

    if ($action === 'order_status') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $allowed = ["laukiama", "apdorojama", "išsiųsta", "įvykdyta", "apmokėta", "atšaukta"];
        
        if ($orderId && in_array($status, $allowed, true)) {
            
            // Jei statusas keičiamas į apmokėta/įvykdyta, paleidžiam pilną procesą
            if ($status === 'apmokėta' || $status === 'įvykdyta') {
                require_once __DIR__ . '/../helpers.php'; // Užtikrinam, kad turim funkciją
                approveOrder($pdo, $orderId);
                
                // Jei pasirinkta 'įvykdyta', papildomai dar atnaujinam statusą (nes approveOrder nustato 'apmokėta')
                if ($status === 'įvykdyta') {
                    $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute(['įvykdyta', $orderId]);
                }
                
                redirectWithMsg('orders', 'Užsakymas patvirtintas, likučiai nurašyti ir laiškai išsiųsti.');
            } else {
                // Kitiems statusams (pvz. išsiųsta, atšaukta) tiesiog atnaujinam DB
                $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
                redirectWithMsg('orders', 'Užsakymo būsena atnaujinta');
            }
        }
        redirectWithMsg('orders', 'Klaida atnaujinant būseną', 'error');
    }

    // --- MENIU (NAVIGACIJA) ---
    if ($action === 'nav_new' || $action === 'save_menu_item') {
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        $label = trim($_POST['label'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        
        if ($id && $parentId == $id) $parentId = null;

        if ($label && $url) {
            if ($id) {
                $stmt = $pdo->prepare('UPDATE navigation_items SET label = ?, url = ?, parent_id = ? WHERE id = ?');
                $stmt->execute([$label, $url, $parentId, $id]);
                redirectWithMsg('menus', 'Meniu punktas atnaujintas');
            } else {
                $maxSort = 0;
                if ($parentId) {
                    $stmt = $pdo->prepare('SELECT MAX(sort_order) FROM navigation_items WHERE parent_id = ?');
                    $stmt->execute([$parentId]);
                    $maxSort = (int)$stmt->fetchColumn();
                } else {
                    $maxSort = (int)$pdo->query('SELECT MAX(sort_order) FROM navigation_items WHERE parent_id IS NULL')->fetchColumn();
                }
                $stmt = $pdo->prepare('INSERT INTO navigation_items (label, url, parent_id, sort_order) VALUES (?, ?, ?, ?)');
                $stmt->execute([$label, $url, $parentId, $maxSort + 1]);
                redirectWithMsg('menus', 'Meniu punktas sukurtas');
            }
        }
        redirectWithMsg('menus', 'Trūksta duomenų', 'error');
    }

    if ($action === 'nav_delete' || $action === 'delete_menu_item') {
        $id = (int)$_POST['id'] ?? 0;
        if ($id) {
            $pdo->prepare('DELETE FROM navigation_items WHERE id = ? OR parent_id = ?')->execute([$id, $id]);
            redirectWithMsg('menus', 'Meniu punktas pašalintas');
        }
        redirectWithMsg('menus', 'Klaida trinant', 'error');
    }

    if ($action === 'move_menu_item') {
        $id = (int)$_POST['id'];
        $direction = $_POST['direction']; // 'up' or 'down'
        
        $stmt = $pdo->prepare('SELECT id, parent_id, sort_order FROM navigation_items WHERE id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        
        if ($current) {
            $parentId = $current['parent_id'];
            $currentSort = $current['sort_order'];
            $neighbor = null;
            
            if ($direction === 'up') {
                $q = 'SELECT id, sort_order FROM navigation_items WHERE ';
                $q .= ($parentId ? 'parent_id = ?' : 'parent_id IS NULL');
                $q .= ' AND sort_order < ? ORDER BY sort_order DESC LIMIT 1';
                $stmt = $pdo->prepare($q);
                $args = $parentId ? [$parentId, $currentSort] : [$currentSort];
                $stmt->execute($args);
                $neighbor = $stmt->fetch();
            } elseif ($direction === 'down') {
                $q = 'SELECT id, sort_order FROM navigation_items WHERE ';
                $q .= ($parentId ? 'parent_id = ?' : 'parent_id IS NULL');
                $q .= ' AND sort_order > ? ORDER BY sort_order ASC LIMIT 1';
                $stmt = $pdo->prepare($q);
                $args = $parentId ? [$parentId, $currentSort] : [$currentSort];
                $stmt->execute($args);
                $neighbor = $stmt->fetch();
            }
            
            if ($neighbor) {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE navigation_items SET sort_order = ? WHERE id = ?')->execute([$neighbor['sort_order'], $current['id']]);
                $pdo->prepare('UPDATE navigation_items SET sort_order = ? WHERE id = ?')->execute([$current['sort_order'], $neighbor['id']]);
                $pdo->commit();
                redirectWithMsg('menus', 'Pozicija pakeista');
            }
        }
        redirectWithMsg('menus', 'Rikiavimas atnaujintas');
    }

    // --- DIZAINAS IR TURINYS ---
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
        redirectWithMsg('design', 'Poraštės tekstas atnaujintas');
    }

    if ($action === 'footer_link_save') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $label = trim($_POST['label'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $section = $_POST['section'] ?? 'quick';
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        if ($label && $url) {
            saveFooterLink($pdo, $id ?: null, $label, $url, $section, $sortOrder);
            redirectWithMsg('design', $id ? 'Nuoroda atnaujinta' : 'Nuoroda pridėta');
        }
        redirectWithMsg('design', 'Įveskite pavadinimą ir nuorodą', 'error');
    }

    if ($action === 'footer_link_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            deleteFooterLink($pdo, $id);
            redirectWithMsg('design', 'Nuoroda pašalinta');
        }
        redirectWithMsg('design', 'Klaida trinant', 'error');
    }

    if ($action === 'hero_copy' || $action === 'page_hero_update' || $action === 'promo_update' || $action === 'storyband_update' || $action === 'storyrow_update' || $action === 'support_update' || $action === 'testimonial_update') {
        $payload = [];
        foreach ($_POST as $key => $val) {
            if ($key !== 'action' && $key !== 'csrf_token') {
                $payload[$key] = trim($val);
            }
        }
        saveSiteContent($pdo, $payload);
        redirectWithMsg('design', 'Turinys atnaujintas');
    }

    if ($action === 'hero_media_update') {
        $type = $_POST['hero_media_type'] ?? 'image';
        $color = trim($_POST['hero_media_color'] ?? '#829ed6');
        $shadow = max(0, min(100, (int)($_POST['hero_shadow_intensity'] ?? 70)));
        $imagePath = trim($_POST['hero_media_image_existing'] ?? '');
        $videoPath = trim($_POST['hero_media_video_existing'] ?? '');
        $posterPath = trim($_POST['hero_media_poster_existing'] ?? '');
        $alt = trim($_POST['hero_media_alt'] ?? '');

        $imageMimeMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        $videoMimeMap = ['video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov'];

        if (!empty($_FILES['hero_media_image']['name'])) {
            $uploaded = saveUploadedFile($_FILES['hero_media_image'], $imageMimeMap, 'hero_img_');
            if ($uploaded) $imagePath = $uploaded;
        }
        if (!empty($_FILES['hero_media_video']['name'])) {
            $uploaded = saveUploadedFile($_FILES['hero_media_video'], $videoMimeMap, 'hero_vid_');
            if ($uploaded) $videoPath = $uploaded;
        }
        if (!empty($_FILES['hero_media_poster']['name'])) {
            $uploaded = saveUploadedFile($_FILES['hero_media_poster'], $imageMimeMap, 'hero_poster_');
            if ($uploaded) $posterPath = $uploaded;
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
        redirectWithMsg('design', 'Hero fonas atnaujintas');
    }

    if ($action === 'banner_update') {
        $banner = [
            'banner_enabled' => isset($_POST['banner_enabled']) ? '1' : '0',
            'banner_text' => trim($_POST['banner_text'] ?? ''),
            'banner_link' => trim($_POST['banner_link'] ?? ''),
            'banner_background' => trim($_POST['banner_background'] ?? '#829ed6'),
        ];
        saveSiteContent($pdo, $banner);
        redirectWithMsg('design', 'Reklamjuostė atnaujinta');
    }

    // --- PRISTATYMAS ---
    if ($action === 'shipping_save') {
        $courier = (float)($_POST['shipping_courier'] ?? 3.99);
        $locker = (float)($_POST['shipping_locker'] ?? 2.49);
        $free = $_POST['shipping_free_over'] !== '' ? (float)$_POST['shipping_free_over'] : null;
        saveShippingSettings($pdo, $courier, $courier, $locker, $free);
        redirectWithMsg('shipping', 'Pristatymo nustatymai išsaugoti');
    }

    if ($action === 'locker_new' || $action === 'locker_update') {
        $lockerId = (int)($_POST['locker_id'] ?? 0);
        $provider = $_POST['locker_provider'] ?? '';
        $title = trim($_POST['locker_title'] ?? '');
        $address = trim($_POST['locker_address'] ?? '');
        $note = trim($_POST['locker_note'] ?? '');

        if (!in_array($provider, ['omniva', 'lpexpress'], true)) redirectWithMsg('shipping', 'Netinkamas tiekėjas', 'error');
        if ($title === '' || $address === '') redirectWithMsg('shipping', 'Trūksta duomenų', 'error');

        if ($action === 'locker_update' && $lockerId > 0) {
            updateParcelLocker($pdo, $lockerId, $provider, $title, $address, $note ?: null);
            redirectWithMsg('shipping', 'Paštomatas atnaujintas');
        } else {
            saveParcelLocker($pdo, $provider, $title, $address, $note ?: null);
            redirectWithMsg('shipping', 'Paštomatas išsaugotas');
        }
    }

    if ($action === 'locker_import') {
        $provider = $_POST['locker_provider'] ?? '';
        if (!in_array($provider, ['omniva', 'lpexpress'], true)) redirectWithMsg('shipping', 'Netinkamas tiekėjas', 'error');

        if (empty($_FILES['locker_file']) || ($_FILES['locker_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
             redirectWithMsg('shipping', 'Įkelkite .xlsx failą', 'error');
        }

        $allowedMimeMap = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'];
        $uploadedLockerPath = saveUploadedFile($_FILES['locker_file'], $allowedMimeMap, 'lockers_');

        if ($uploadedLockerPath) {
            $parsed = parseLockerFile($provider, __DIR__ . '/../' . ltrim($uploadedLockerPath, '/'));
            if ($parsed) {
                bulkSaveParcelLockers($pdo, $provider, $parsed);
                redirectWithMsg('shipping', 'Importuota paštomatų: ' . count($parsed));
            }
            redirectWithMsg('shipping', 'Nepavyko nuskaityti failo', 'error');
        }
        redirectWithMsg('shipping', 'Klaida įkeliant failą', 'error');
    }

    if ($action === 'shipping_free_products') {
        $selected = $_POST['promo_products'] ?? [];
        saveFreeShippingProducts($pdo, is_array($selected) ? $selected : []);
        redirectWithMsg('shipping', 'Nemokamo pristatymo pasiūlymai atnaujinti');
    }

    // --- BENDRUOMENĖ ---
    if ($action === 'new_thread_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $pdo->prepare('INSERT INTO community_thread_categories (name) VALUES (?)')->execute([$name]);
            redirectWithMsg('community', 'Diskusijų kategorija sukurta');
        }
        redirectWithMsg('community', 'Įveskite pavadinimą', 'error');
    }
    if ($action === 'save_community_category') {
        $id = $_POST['id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        if ($name) {
             if ($id) $pdo->prepare('UPDATE community_thread_categories SET name = ? WHERE id = ?')->execute([$name, $id]);
             else $pdo->prepare('INSERT INTO community_thread_categories (name) VALUES (?)')->execute([$name]);
             redirectWithMsg('community', 'Diskusijų kategorija išsaugota');
        }
        redirectWithMsg('community', 'Įveskite pavadinimą', 'error');
    }
    if ($action === 'delete_community_category') {
        $id = (int)$_POST['id'];
        $pdo->prepare('DELETE FROM community_thread_categories WHERE id = ?')->execute([$id]);
        redirectWithMsg('community', 'Kategorija ištrinta');
    }
    if ($action === 'delete_community_thread') {
        $id = (int)$_POST['id'];
        $pdo->prepare('DELETE FROM community_threads WHERE id = ?')->execute([$id]);
        redirectWithMsg('community', 'Tema ištrinta');
    }

    if ($action === 'new_listing_category' || $action === 'save_listing_category') {
        $id = $_POST['id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            if ($id) $pdo->prepare('UPDATE community_listing_categories SET name = ? WHERE id = ?')->execute([$name, $id]);
            else $pdo->prepare('INSERT INTO community_listing_categories (name) VALUES (?)')->execute([$name]);
            redirectWithMsg('community', 'Skelbimų kategorija išsaugota');
        }
        redirectWithMsg('community', 'Įveskite pavadinimą', 'error');
    }
    if ($action === 'delete_listing_category') {
        $id = (int)$_POST['id'];
        $pdo->prepare('DELETE FROM community_listing_categories WHERE id = ?')->execute([$id]);
        redirectWithMsg('community', 'Skelbimų kategorija ištrinta');
    }

    if ($action === 'delete_listing') {
        $id = (int)$_POST['id'];
        $pdo->prepare('DELETE FROM community_listings WHERE id = ?')->execute([$id]);
        redirectWithMsg('community', 'Skelbimas ištrintas');
    }
    if ($action === 'update_listing_status' || $action === 'community_listing_status') {
        $id = (int)($_POST['id'] ?? $_POST['listing_id'] ?? 0);
        $status = $_POST['status'];
        $pdo->prepare('UPDATE community_listings SET status = ? WHERE id = ?')->execute([$status, $id]);
        redirectWithMsg('community', 'Skelbimo statusas atnaujintas');
    }

    if ($action === 'block_user' || $action === 'community_block') {
        $userId = (int)$_POST['user_id'];
        $reason = trim($_POST['reason'] ?? '');
        $duration = $_POST['duration'] ?? '';
        $until = null;
        
        if (isset($_POST['banned_until'])) {
            $until = $_POST['banned_until']; // Jei ateina tiesiogiai data
        } else {
             if ($duration === '24h') $until = date('Y-m-d H:i:s', strtotime('+24 hours'));
             elseif ($duration === '7d') $until = date('Y-m-d H:i:s', strtotime('+7 days'));
             elseif ($duration === '30d') $until = date('Y-m-d H:i:s', strtotime('+30 days'));
             elseif ($duration === 'permanent') $until = '2099-12-31 00:00:00';
        }

        if ($userId) {
             $pdo->prepare('REPLACE INTO community_blocks (user_id, banned_until, reason) VALUES (?, ?, ?)')->execute([$userId, $until, $reason]);
             redirectWithMsg('community', 'Vartotojas užblokuotas');
        }
        redirectWithMsg('community', 'Nenurodytas vartotojas', 'error');
    }
    if ($action === 'unblock_user' || $action === 'community_unblock') {
        $id = (int)($_POST['id'] ?? $_POST['user_id'] ?? 0);
        $pdo->prepare('DELETE FROM community_blocks WHERE user_id = ?')->execute([$id]);
        redirectWithMsg('community', 'Vartotojas atblokuotas');
    }

    if ($action === 'delete_news') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM news WHERE id = ?')->execute([$id]);
            redirectWithMsg('content', 'Naujiena ištrinta');
        }
        redirectWithMsg('content', 'Klaida trinant', 'error');
    }

    if ($action === 'delete_recipe') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM recipes WHERE id = ?')->execute([$id]);
            redirectWithMsg('content', 'Receptas ištrintas');
        }
        redirectWithMsg('content', 'Klaida trinant', 'error');
    }

    // --- LAIŠKŲ SIUNTIMAS (ATNAUJINTA) ---
    if ($action === 'send_email') {
        require_once __DIR__ . '/../mailer.php';

        $recipientInput = $_POST['recipient_id'] ?? '';
        $subject = trim($_POST['subject'] ?? '');
        $content = trim($_POST['message'] ?? '');
        $manualEmail = trim($_POST['manual_email'] ?? '');

        if (!$recipientInput || !$subject || !$content) {
            redirectWithMsg('emails', 'Užpildykite visus laukus (gavėjas, tema, žinutė)', 'error');
        }

        // Tikriname, ar siunčiama VISIEMS
        if ($recipientInput === 'all') {
            // Siunčiame masinį laišką
            $stmt = $pdo->query("SELECT email, name FROM users WHERE email IS NOT NULL AND email != ''");
            $allUsers = $stmt->fetchAll();
            $sentCount = 0;

            foreach ($allUsers as $user) {
                // Generuojame laišką kiekvienam (kad būtų personalizuota antraštė, jei mailer.php tai naudoja, ir saugiau nuo spam filtrų)
                $htmlBody = getEmailTemplate($subject, $content, 'https://cukrinukas.lt', 'Apsilankyti parduotuvėje');
                
                if (sendEmail($user['email'], $subject, $htmlBody)) {
                    $sentCount++;
                }
            }

            redirectWithMsg('emails', "Laiškas išsiųstas $sentCount klientams iš " . count($allUsers) . "!");

        } elseif ($recipientInput === 'manual') {
            // RANKINIS SIUNTIMAS
            if (!filter_var($manualEmail, FILTER_VALIDATE_EMAIL)) {
                redirectWithMsg('emails', 'Neteisingas el. pašto formatas', 'error');
            }

            $htmlBody = getEmailTemplate($subject, $content, 'https://cukrinukas.lt', 'Apsilankyti parduotuvėje');
            
            // Siunčiame, nurodydami vardą tiesiog kaip el. paštą arba "Klientas"
            $sent = sendEmail($manualEmail, $subject, $htmlBody);
            
            if ($sent) {
                redirectWithMsg('emails', "Laiškas sėkmingai išsiųstas adresu: $manualEmail");
            } else {
                redirectWithMsg('emails', 'Nepavyko išsiųsti laiško. Patikrinkite serverio nustatymus.', 'error');
            }

        } else {
            // Siunčiame vienam registruotam klientui
            $recipientId = (int)$recipientInput;
            $stmt = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
            $stmt->execute([$recipientId]);
            $user = $stmt->fetch();

            if ($user) {
                $htmlBody = getEmailTemplate($subject, $content, 'https://cukrinukas.lt', 'Apsilankyti parduotuvėje');
                
                $sent = sendEmail($user['email'], $subject, $htmlBody);
                
                if ($sent) {
                    redirectWithMsg('emails', "Laiškas sėkmingai išsiųstas klientui {$user['name']}!");
                } else {
                    redirectWithMsg('emails', 'Nepavyko išsiųsti laiško. Patikrinkite serverio nustatymus.', 'error');
                }
            } else {
                redirectWithMsg('emails', 'Vartotojas nerastas', 'error');
            }
        }
    }
}
?>
