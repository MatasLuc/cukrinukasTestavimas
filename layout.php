<?php
require_once __DIR__ . '/security.php';
enforcePostRequestCsrf();

function currentUser(): array {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['user_name'] ?? null,
        'is_admin' => !empty($_SESSION['is_admin']),
    ];
}

function headerStyles(?int $overrideShadow = null): string {
    // Shadow kept configurable for future needs, but default styling uses none.
    $shadowSource = $overrideShadow ?? ($GLOBALS['headerShadowIntensity'] ?? getenv('HEADER_SHADOW_INTENSITY'));
    $shadowLevel = is_numeric($shadowSource) ? max(0, min(100, (int)$shadowSource)) : 0;
    $shadowOpacity = round(0.28 * ($shadowLevel / 100), 3);
    $shadowBlur = round(26 * ($shadowLevel / 100), 2);

    return <<<CSS
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</noscript><style>
:root {
  --font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  --font-weight-regular: 400;
  --font-weight-medium: 500;
  --font-weight-semibold: 600;
  --font-weight-bold: 700;
  --text-color: #0b0b0b;
  --surface: #f7f7fb;
  --accent: #829ed6;
  --header-shadow-blur: {$shadowBlur}px;
  --header-shadow-opacity: {$shadowOpacity};
  --header-surface: #ffffff;
  --header-border: #e6e6ef;
}

/* SVARBU: Globalus box-sizing pataisymas, kad padding neiškreiptų pločio */
*, *::before, *::after { box-sizing: border-box; }

body { margin: 0; background: var(--surface); color: var(--text-color); font-family: var(--font-family); font-weight: var(--font-weight-regular); }
* { font-family: var(--font-family); }

h1, h2, h3, h4, h5, h6 { font-weight: var(--font-weight-semibold); letter-spacing: -0.01em; color: var(--text-color); }
strong, b { font-weight: 700 !important; color: var(--text-color); }

a { color: var(--text-color); }
.muted { color: #4a4a55; font-weight: var(--font-weight-medium); }

/* MODERNIZUOTAS HEADER IR ANNOUNCEMENT BAR */

/* Announcement Bar - Tamsus, solidus, be tarpo */
.announcement-bar {
  background: #121212; /* Premium tamsi spalva */
  color: #ffffff;
  text-align: center;
  padding: 10px 20px;
  font-size: 13px; /* Šiek tiek mažesnis, estetiškesnis šriftas */
  font-weight: var(--font-weight-medium);
  position: relative; /* Svarbu: relative leidžia jam būti virš headerio natūraliai */
  z-index: 1002;
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 12px;
  border-bottom: 1px solid rgba(255,255,255,0.05);
}

.announcement-bar a { 
  color: #ffffff; 
  text-decoration: none; 
  font-weight: var(--font-weight-medium);
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: opacity 0.2s ease, color 0.2s ease;
}
.announcement-bar a:hover {
  opacity: 1;
  color: var(--accent); /* Užvedus nusidažo akcentine spalva */
}

.announcement-bar__label { 
  padding: 3px 8px; 
  background: rgba(255,255,255,0.15); 
  color: #fff; 
  border-radius: 6px; 
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  flex-shrink: 0;
}

.announcement-icon {
  width: 14px;
  height: 14px;
  transition: transform 0.2s ease;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.announcement-bar a:hover .announcement-icon {
  transform: translateX(3px);
}

/* Header - Prilimpa prie viršaus (arba po announcement bar, kol matomas) */
.header {
  background: rgba(255,255,255,0.95);
  backdrop-filter: saturate(1.2) blur(16px);
  -webkit-backdrop-filter: saturate(1.2) blur(16px);
  position: sticky;
  top: 0;
  z-index: 1000; 
  box-shadow: 0 1px 0 var(--header-border); /* Subtili linija apačioje */
  border-bottom: none;
  transition: transform 0.2s ease;
}

.navbar {
  max-width: 1200px;
  margin: 0 auto;
  padding: 14px 22px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  color: #0b0b0b;
  position: relative;
}
.nav-toggle {
  display: none;
  background: transparent;
  border: none;
  color: #0b0b0b;
  padding: 8px;
  font-size: 24px;
  cursor: pointer;
  line-height: 1;
}
        
.brand {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-weight: var(--font-weight-bold);
  letter-spacing: 0.3px;
  font-size: 19px;
  color: #0b0b0b;
  padding: 8px 12px;
  border-radius: 12px;
  transition: background .2s ease, color .2s ease;
  background: rgba(130,158,214,0.15);
  text-decoration: none;
}
        
.brand:hover { background: rgba(130,158,214,0.28); }

.nav-links {
  display: flex;
  gap: 14px;
  align-items: center;
  flex: 1;
  justify-content: flex-end;
}
.nav-item { position: relative; }
.nav-submenu {
  position:absolute;
  top:100%;
  left:0;
  background:#fff;
  border:1px solid #e6e6ef;
  border-radius:12px;
  padding:12px;
  box-shadow:0 18px 38px rgba(0,0,0,0.12);
  display:none;
  min-width:180px;
  z-index: 1002;
  flex-direction:column;
  gap:4px;
}
.nav-submenu .nav-item { display:block; }
.nav-item:hover .nav-submenu, .nav-item:focus-within .nav-submenu { display:flex; }
.nav-submenu a { color:#0b0b0b; font-weight:500; padding:8px 10px; display:block; border-radius:8px; font-size: 14px; }
.nav-submenu a:hover { background:#f3f3f8; }

.nav-links a {
  color: #4a4a55;
  text-decoration: none;
  font-weight: 500;
  padding: 8px 12px;
  border-radius: 8px;
  transition: all .2s ease;
  font-size: 14px;
}
.nav-links a:hover { color: #0b0b0b; background: rgba(0,0,0,0.03); }

/* USER ACTIONS (Profile & Cart) */
.user-actions {
  display: flex;
  align-items: center;
  gap: 4px;
}

.user-area { position: relative; }
.user-button {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  color:#0b0b0b;
  padding: 8px;
  border-radius: 8px;
  background: transparent;
  border: none;
  position: relative;
  cursor: pointer;
  transition: background 0.2s ease;
  text-decoration: none;
}
.user-button:hover { background: rgba(0,0,0,0.03); }
.user-button svg { width: 24px; height: 24px; }

.user-button__badge {
  position:absolute;
  top: 0px;
  right: 0px;
  background: var(--accent);
  color:#fff;
  font-weight: 700;
  font-size:10px;
  line-height:1;
  padding:0 4px;
  border-radius:9px;
  min-width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.user-menu {
  position:absolute;
  top: calc(100% + 10px);
  right:0;
  background:#fff;
  color:var(--text-color);
  border-radius:12px;
  box-shadow:0 20px 50px rgba(0,0,0,0.12);
  padding:8px;
  min-width:220px;
  display:none;
  flex-direction:column;
  gap:4px;
  border:1px solid #e6e6ef;
  z-index: 1005;
}
.user-area:hover .user-menu,
.user-area:focus-within .user-menu,
.user-menu.open { display:flex; }

.checkbox-row {
  display:flex;
  align-items:center;
  gap:8px;
  margin:0;
  font-weight: var(--font-weight-medium);
}
input[type=checkbox] {
  width: 16px;
  height: 16px;
  margin: 0;
  accent-color: #829ed6;
}
.user-menu a,
.user-menu button {
  color:#0b0b0b;
  font-weight: 500;
  font-size:14px;
  padding:8px 12px;
  border-radius:8px;
  background:none;
  border:none;
  text-align:left;
  cursor:pointer;
  width: 100%;
}
.user-menu a:hover,
.user-menu button:hover { background:#f3f3f8; }

.cart-link { position: relative; }
.cart-icon {
  display:inline-flex;
  align-items:center;
  gap:6px;
  color:#0b0b0b;
  font-weight:600;
  padding: 8px;
  border-radius: 8px;
  transition: background 0.2s ease;
  text-decoration: none;
}
.cart-icon:hover { background: rgba(0,0,0,0.03); }
.cart-icon svg { width:24px; height:24px; }
.cart-count {
  position: absolute;
  top: 0px;
  right: 0px;
  background: #0b0b0b;
  color: #fff;
  min-width: 18px;
  height: 18px;
  border-radius: 9px;
  font-size: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  padding: 0 4px;
}
.cart-dropdown {
  position: absolute;
  right: 0;
  top: calc(100% + 10px);
  min-width: 400px;
  background: #fff;
  border: 1px solid #e6e6ef;
  border-radius: 12px;
  box-shadow: 0 20px 50px rgba(0,0,0,0.12);
  padding: 16px;
  display: none;
  flex-direction: column;
  gap: 16px;
  color: var(--text-color);
  transform: translateX(0);
  overflow-y: auto;
  max-height: 70vh;
  z-index: 1005;
}
.cart-link:hover .cart-dropdown,
.cart-link:focus-within .cart-dropdown,
.cart-dropdown.open { display: flex; }
.cart-preview-item { display:flex; gap:12px; align-items:center; padding-bottom: 12px; border-bottom: 1px solid #f0f0f5; }
.cart-preview-item:last-child { border-bottom: none; padding-bottom: 0; }
.cart-preview-item img { width:48px; height:48px; object-fit:cover; border-radius:8px; background: #f7f7fb; }
.cart-preview-meta { display:flex; justify-content: space-between; width:100%; align-items: flex-start; }
.cart-preview-footer { display:flex; justify-content: space-between; align-items:center; margin-top:8px; padding-top: 12px; border-top: 2px solid #f0f0f5; }

/* PAIEŠKOS DROPDOWN STILIAI */
.search-container { position: relative; display: flex; align-items: center; }
.search-dropdown {
  position: absolute; top: calc(100% + 8px); left: 0; right: 0;
  background: #fff; border: 1px solid #e6e6ef; border-radius: 12px;
  box-shadow: 0 10px 40px rgba(0,0,0,0.12); overflow: hidden;
  display: none; flex-direction: column; z-index: 1050; min-width: 250px;
}
.search-dropdown.active { display: flex; }
.search-dropdown-item {
  display: flex; align-items: center; gap: 12px; padding: 10px 12px;
  text-decoration: none; color: var(--text-color); border-bottom: 1px solid #f0f0f5;
  transition: background 0.2s;
}
.search-dropdown-item:last-child { border-bottom: none; }
.search-dropdown-item:hover { background: #f3f3f8; }
.search-dropdown-img { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; background: #f0f0f5; flex-shrink: 0; }
.search-dropdown-img.text-avatar {
  background: #eef2ff; color: var(--accent);
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; font-weight: 700; text-transform: uppercase;
}
.search-dropdown-info { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.search-dropdown-title { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #0b0b0b; }
.search-dropdown-type { font-size: 11px; color: #6b6b7a; }
.search-dropdown-more { padding: 10px; text-align: center; font-size: 12px; font-weight: 600; color: var(--accent); text-decoration: none; background: #f9fafb; }
.search-dropdown-more:hover { background: #f3f3f8; color: #0b0b0b; }

/* SEARCH BAR STYLES */
.search-form {
  display: flex;
  align-items: center;
  background: #f3f3f8;
  border-radius: 99px;
  padding: 4px 12px;
  border: 1px solid transparent;
  transition: all 0.2s ease;
  width: 100%;
}
.search-form:focus-within {
  background: #fff;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(130,158,214,0.2);
}
.search-input {
  border: none;
  background: transparent;
  padding: 6px 8px;
  font-size: 14px;
  outline: none;
  width: 140px;
  transition: width 0.2s ease;
  color: var(--text-color);
}
.search-input:focus {
  width: 200px;
}
.search-btn {
  background: transparent;
  border: none;
  padding: 4px;
  color: #6b6b7a;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
.search-btn:hover {
  color: var(--accent);
}

.btn {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:10px 18px;
  border-radius:8px;
  border:1px solid var(--accent);
  background: var(--accent);
  color:#fff;
  font-weight: 600;
  text-decoration: none;
  font-size: 14px;
  transition: all 0.2s ease;
}
.btn:hover { opacity: 0.9; transform: translateY(-1px); }
.btn.secondary {
  background: transparent;
  color: var(--text-color);
  border-color: #dcdce7;
}
.btn.secondary:hover { background: #f3f3f8; border-color: #b0b0bd; }
.btn.ghost {
  background: transparent;
  color: var(--text-color);
  box-shadow:none;
  border-color: transparent;
}
.btn.ghost:hover { background: #f3f3f8; }

.pill-count {
  background: var(--accent);
  color: #fff;
  padding: 2px 6px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  margin-left: 6px;
}

/* MOBILE MENU STYLES */
@media (max-width: 900px) {
  .navbar {
    justify-content: flex-start;
    padding: 12px 16px;
    flex-wrap: wrap;
  }
  .brand {
    margin-right: auto;
    font-size: 18px;
  }
  .nav-toggle { 
    display: inline-flex; 
    margin-right: 4px;
  }
  .user-actions {
    order: 2;
    gap: 2px;
  }
  .search-container {
    width: 100%;
    order: 4;
    margin-top: 10px;
    flex-shrink: 0;
    margin-left: 0 !important;
    margin-right: 0 !important;
  }
  .search-form { width: 100%; margin-top: 0; }
  .search-input { width: 100%; }
  .search-input:focus { width: 100%; }
  
  .nav-links {
    order: 5;
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    width: 100%;
    background: #ffffff;
    border-bottom: 1px solid #e6e6ef;
    padding: 10px;
    box-sizing: border-box;
    flex-direction: column;
    align-items: stretch;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    z-index: 1000;
  }
  .nav-links.open { display: flex; }
  
  .nav-item {
    width: 100%;
  }
  .nav-links a {
    display: block;
    padding: 12px;
    font-size: 16px;
    background: transparent;
    border-radius: 8px;
    margin-bottom: 2px;
  }
  .nav-links a:active,
  .nav-links a:hover {
    background: #f3f3f8;
  }
  
  .nav-submenu {
    position: static;
    box-shadow: none;
    border: none;
    padding: 0 0 0 12px;
    display: none;
    width: 100%;
    min-width: auto;
    z-index: auto;
    border-left: 2px solid #f0f0f5;
    border-radius: 0;
    margin-top: 4px;
    margin-bottom: 8px;
  }
  .nav-item:hover .nav-submenu { display: flex; }

  .cart-dropdown, .user-menu {
    position: fixed;
    top: 60px;
    left: 10px;
    right: 10px;
    width: auto;
    min-width: auto;
    max-height: 80vh;
    z-index: 2000;
  }
}
</style>
CSS;
}

function renderHeader(PDO $pdo, string $active = '', array $meta = []): void {
    // Meta duomenų paruošimas su numatytosiomis reikšmėmis
    $metaTitle = $meta['title'] ?? 'Cukrinukas.lt – diabeto priemonės ir žinios';
    $metaDescRaw = strip_tags($meta['description'] ?? 'Gliukometrai, sensoriai, juostelės, mažo GI užkandžiai ir patarimai gyvenimui su diabetu.');
    $metaImage = $meta['image'] ?? 'https://cukrinukas.lt/uploads/default_social.jpg';
    $metaUrl = "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

    // SEO: Sutvarkytas aprašymo kirpimas
    $metaDesc = $metaDescRaw;
    if (mb_strlen($metaDescRaw) > 160) {
        $metaDesc = mb_substr($metaDescRaw, 0, 160);
        $lastSpace = mb_strrpos($metaDesc, ' ');
        if ($lastSpace !== false) {
            $metaDesc = mb_substr($metaDesc, 0, $lastSpace) . '...';
        } else {
            $metaDesc .= '...';
        }
    }

    // Favicon apibrėžimas
    $faviconSvg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Ctext x='50%25' y='50%25' dy='.35em' text-anchor='middle' font-family='Arial, sans-serif' font-weight='900' font-size='60' fill='black'%3EC%3C/text%3E%3C/svg%3E";

    $cart = getCartData($pdo, $_SESSION['cart'] ?? [], $_SESSION['cart_variations'] ?? []);
    
    // --- Bendruomenės prekių krepšelis ---
    if (!empty($_SESSION['cart_community'])) {
        $commIds = array_keys($_SESSION['cart_community']);
        if ($commIds) {
            $inQuery = implode(',', array_fill(0, count($commIds), '?'));
            $stmtComm = $pdo->prepare("SELECT id, title, price, image_url FROM community_listings WHERE id IN ($inQuery)");
            $stmtComm->execute($commIds);
            $commItems = $stmtComm->fetchAll(PDO::FETCH_ASSOC);

            foreach ($commItems as $cItem) {
                $cQty = 1;
                $cPrice = (float)$cItem['price'];
                $cImg = !empty($cItem['image_url']) ? $cItem['image_url'] : 'uploads/default.png';

                $cart['count'] += $cQty;
                $cart['total'] += $cPrice;

                $cart['items'][] = [
                    'cart_key' => 'comm_' . $cItem['id'],
                    'title' => $cItem['title'],
                    'image_url' => $cImg,
                    'quantity' => $cQty,
                    'price' => $cPrice,
                    'line_total' => $cPrice,
                    'line_base' => $cPrice,
                    'variation' => [['group' => 'Turgelis', 'name' => 'Bendruomenė']]
                ];
            }
        }
    }
    // -----------------------------------------------------------

    $user = currentUser();
    try {
        ensureDirectMessages($pdo);
    } catch (Throwable $e) {
        // fail silently
    }
    $navItems = getNavigationTree($pdo);
    $siteContent = getSiteContent($pdo);
    $unreadMessages = $user['id'] ? getUnreadDirectMessagesCount($pdo, (int)$user['id']) : 0;
    $bannerEnabled = !empty($siteContent['banner_enabled']) && $siteContent['banner_enabled'] !== '0';
    $bannerText = trim($siteContent['banner_text'] ?? '');
    $bannerLink = trim($siteContent['banner_link'] ?? '');
    
    $renderNav = function(array $items, bool $isChild = false) use (&$renderNav) {
        if (!$items) return '';
        $html = $isChild ? '<div class="nav-submenu">' : '';
        foreach ($items as $item) {
            $html .= '<div class="nav-item">';
            $html .= '<a href="' . htmlspecialchars($item['url']) . '">' . htmlspecialchars($item['label']) . '</a>';
            if (!empty($item['children'])) {
                $html .= $renderNav($item['children'], true);
            }
            $html .= '</div>';
        }
        if ($isChild) {
            $html .= '</div>';
        }
        return $html;
    };

    // --- GOOGLE TAG MANAGER & E-COMMERCE LOGIKA ---
    $gtmPurchaseData = isset($_SESSION['gtm_purchase_event']) ? $_SESSION['gtm_purchase_event'] : null;
    if ($gtmPurchaseData) {
        unset($_SESSION['gtm_purchase_event']);
    }
    // Paieškos query gavimas
    $searchQuery = $_GET['q'] ?? '';
    ?>
    <head>
      <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
      new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
      j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
      'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
      })(window,document,'script','dataLayer','GTM-TMJMPRFM');</script>
      <?php if ($gtmPurchaseData): ?>
      <script>
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
          'event': 'purchase',
          'ecommerce': {
            'transaction_id': '<?php echo $gtmPurchaseData['transaction_id']; ?>',
            'value': <?php echo $gtmPurchaseData['value']; ?>,
            'currency': '<?php echo $gtmPurchaseData['currency']; ?>'
          }
        });
      </script>
      <?php endif; ?>

      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <link rel="manifest" href="/manifest.json">
      <meta name="theme-color" content="#829ed6">
      <link rel="apple-touch-icon" href="/uploads/icon-192.png">
      
      <link rel="icon" type="image/svg+xml" href="<?php echo $faviconSvg; ?>">

      <title><?php echo htmlspecialchars($metaTitle); ?></title>
      <meta name="description" content="<?php echo htmlspecialchars($metaDesc); ?>">
      <link rel="canonical" href="<?php echo htmlspecialchars($metaUrl); ?>">
      <meta property="og:type" content="website">
      <meta property="og:url" content="<?php echo htmlspecialchars($metaUrl); ?>">
      <meta property="og:title" content="<?php echo htmlspecialchars($metaTitle); ?>">
      <meta property="og:description" content="<?php echo htmlspecialchars($metaDesc); ?>">
      <meta property="og:image" content="<?php echo htmlspecialchars($metaImage); ?>">
      <meta property="twitter:card" content="summary_large_image">
      <meta property="twitter:url" content="<?php echo htmlspecialchars($metaUrl); ?>">
      <meta property="twitter:title" content="<?php echo htmlspecialchars($metaTitle); ?>">
      <meta property="twitter:description" content="<?php echo htmlspecialchars($metaDesc); ?>">
      <meta property="twitter:image" content="<?php echo htmlspecialchars($metaImage); ?>">

        <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        
        fbq('init', '896938179371337'); 
        fbq('track', 'PageView');

        <?php 
        $purchaseData = $_SESSION['gtm_purchase_event'] ?? null;
        if ($purchaseData): 
            unset($_SESSION['gtm_purchase_event']); 
        ?>
            fbq('track', 'Purchase', {
                value: <?php echo $purchaseData['value']; ?>,
                currency: '<?php echo $purchaseData['currency']; ?>',
                content_type: 'product'
            });
        <?php endif; ?>
      </script>
      <noscript><img height="1" width="1" style="display:none"
      src="https://www.facebook.com/tr?id=896938179371337&ev=PageView&noscript=1"
      /></noscript>
        
      <?php echo headerStyles(); ?>
    </head>
    
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TMJMPRFM"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    
    <?php if ($bannerEnabled && $bannerText): ?>
      <div class="announcement-bar">
        <span class="announcement-bar__label">SVARBU</span>
        <?php if ($bannerLink): ?>
          <a href="<?php echo htmlspecialchars($bannerLink); ?>">
            <?php echo htmlspecialchars($bannerText); ?>
            <svg class="announcement-icon" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"></path></svg>
          </a>
        <?php else: ?>
          <span><?php echo htmlspecialchars($bannerText); ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <header class="header">
    <nav class="navbar">
      <button class="nav-toggle" type="button" aria-label="Meniu" aria-expanded="false">
         <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
      </button>
      <a class="brand" href="/">Cukrinukas.lt</a>
      
      <div class="nav-links">
        <?php echo $renderNav($navItems); ?>
      </div>

      <div class="search-container" style="margin-left: 16px; margin-right: 8px;">
          <form class="search-form" action="/search.php" method="GET">
              <input type="text" name="q" id="liveSearchInput" class="search-input" placeholder="Ieškoti..." required value="<?php echo htmlspecialchars((string)$searchQuery); ?>" autocomplete="off">
              <button type="submit" class="search-btn" aria-label="Ieškoti">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
              </button>
          </form>
          <div class="search-dropdown" id="searchDropdown"></div>
      </div>

      <div class="user-actions">
          <?php if ($user['id']): ?>
              <div class="user-area">
                <button class="user-button" type="button" aria-label="Profilis">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                  <?php if ($unreadMessages): ?><span class="user-button__badge"><?php echo $unreadMessages; ?></span><?php endif; ?>
                </button>
                <div class="user-menu">
                  <div style="padding: 6px 12px; font-size: 13px; color: #6b6b7a; border-bottom: 1px solid #f0f0f5; margin-bottom: 4px;">
                    Labas, <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                  </div>
                  <a class="user-menu__action" href="/orders.php">Užsakymai</a>
                  <a class="user-menu__action" href="/account.php">Paskyros redagavimas</a>
                  <a class="user-menu__action" href="/messages.php">Žinutės<?php if ($unreadMessages): ?> <span class="pill-count"><?php echo $unreadMessages; ?></span><?php endif; ?></a>
                  <a class="user-menu__action" href="/saved.php">Mano išsaugoti</a>
                  <?php if ($user['is_admin']): ?>
                    <a class="user-menu__action" href="/admin.php">Administravimas</a>
                  <?php endif; ?>
                  <form method="post" action="/login.php" style="margin:0;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="logout" value="1">
                    <button class="user-menu__action" type="submit">Atsijungti</button>
                  </form>
                </div>
              </div>
          <?php else: ?>
              <div class="user-area">
                <a href="/login.php" class="user-button" aria-label="Prisijungti">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                </a>
              </div>
          <?php endif; ?>

          <div class="cart-link">
            <a class="cart-icon" href="/cart.php" aria-label="Krepšelis">
              <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M5 5h2l1 11h8l1-8H7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="10" cy="20" r="1.2" fill="currentColor"/>
                <circle cx="17" cy="20" r="1.2" fill="currentColor"/>
              </svg>
              <?php if ($cart['count']): ?><span class="cart-count"><?php echo $cart['count']; ?></span><?php endif; ?>
            </a>
            <div class="cart-dropdown" aria-label="Krepšelio peržiūra">
              <?php if (!$cart['items']): ?>
                <div class="cart-preview-item" style="color:#6b6b7a;">Krepšelis tuščias</div>
              <?php else: ?>
                <?php foreach ($cart['items'] as $item): ?>
                  <?php
                    $baseLine = isset($item['line_base']) ? (float)$item['line_base'] : (float)$item['line_total'];
                    $lineTotal = (float)$item['line_total'];
                    $finalUnit = $item['quantity'] > 0 ? $lineTotal / (int)$item['quantity'] : $item['price'];
                    $discounted = $lineTotal < $baseLine;
                  ?>
                  <div class="cart-preview-item">
                    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                    <div style="width:100%;">
                      <div class="cart-preview-meta">
                        <div>
                            <strong><?php echo htmlspecialchars($item['title']); ?></strong>
                            <?php 
                                $vars = $item['variation'] ?? [];
                                if (!empty($vars) && !isset($vars[0])) $vars = [$vars];
                                foreach($vars as $v): 
                                    $g = $v['group'] ?? $v['group_name'] ?? '';
                                    $n = $v['name'] ?? '';
                                    if($n):
                            ?>
                                <div style="font-size:11px; color:#6b6b7a; line-height:1.2; margin-top:2px;">
                                    <?php echo $g ? htmlspecialchars($g).': ' : ''; ?>
                                    <strong><?php echo htmlspecialchars($n); ?></strong>
                                </div>
                            <?php endif; endforeach; ?>
                        </div>
                        <span>
                          <?php if ($discounted): ?><span style="text-decoration:line-through; color:#6b6b7a; font-size:13px; margin-right:6px;"><?php echo number_format($item['line_total'], 2); ?> €</span><?php endif; ?>
                          <?php echo number_format($lineTotal, 2); ?> €
                        </span>
                      </div>
                      <div style="color:#6b6b7a; font-size:14px; margin-top:4px;">Kiekis: <?php echo $item['quantity']; ?> × <?php echo number_format($finalUnit, 2); ?> €</div>
                    </div>
                  </div>
                <?php endforeach; ?>
                <div class="cart-preview-footer">
                  <span><strong>Iš viso:</strong> <?php echo number_format($cart['total'], 2); ?> €</span>
                  <div style="display:flex; gap:8px;">
                    <a class="btn secondary" href="/cart.php">Peržiūrėti</a>
                    <a class="btn ghost" href="/checkout.php">Apmokėti</a>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
      </div>

    </nav>
  </header>
  <script>
      document.querySelectorAll('.user-area').forEach(function(area){
        let timer;
        const menu = area.querySelector('.user-menu');
        function openMenu(){
          clearTimeout(timer);
          if (menu) menu.classList.add('open');
        }
        function closeMenu(){
          timer = setTimeout(()=> menu && menu.classList.remove('open'), 150);
        }
        ['mouseenter','focusin'].forEach(evt => area.addEventListener(evt, openMenu));
        ['mouseleave','focusout'].forEach(evt => area.addEventListener(evt, closeMenu));
        if (menu){
          menu.addEventListener('mouseenter', openMenu);
          menu.addEventListener('mouseleave', closeMenu);
        }
      });

      document.querySelectorAll('.cart-link').forEach(function(link){
        let timer;
        const dropdown = link.querySelector('.cart-dropdown');
        function open(){
          clearTimeout(timer);
          dropdown && dropdown.classList.add('open');
        }
        function close(){
          timer = setTimeout(() => dropdown && dropdown.classList.remove('open'), 150);
        }
        ['mouseenter','focusin'].forEach(evt => link.addEventListener(evt, open));
        ['mouseleave','focusout'].forEach(evt => link.addEventListener(evt, close));
        if (dropdown){
          dropdown.addEventListener('mouseenter', open);
          dropdown.addEventListener('mouseleave', close);
          dropdown.addEventListener('wheel', function(e){ open(); }, { passive: true });
          dropdown.addEventListener('scroll', open, { passive: true });
        }
        window.addEventListener('scroll', () => {
          if (link.matches(':hover') || dropdown.matches(':hover')) { open(); }
        }, { passive: true });
      });

      const navToggle = document.querySelector('.nav-toggle');
      const navLinks = document.querySelector('.nav-links');
      if (navToggle && navLinks) {
        navToggle.addEventListener('click', function(){
          navLinks.classList.toggle('open');
          navToggle.setAttribute('aria-expanded', navLinks.classList.contains('open') ? 'true' : 'false');
        });
        navLinks.querySelectorAll('a').forEach(function(anchor){
          anchor.addEventListener('click', function(){
            navLinks.classList.remove('open');
            navToggle.setAttribute('aria-expanded', 'false');
          });
        });
      }

      // LIVE SEARCH (AJAX) LOGIKA
      const searchInput = document.getElementById('liveSearchInput');
      const searchDropdown = document.getElementById('searchDropdown');
      let searchTimeout;

      if (searchInput && searchDropdown) {
          searchInput.addEventListener('input', function() {
              clearTimeout(searchTimeout);
              const query = this.value.trim();
              
              if (query.length < 2) {
                  searchDropdown.classList.remove('active');
                  return;
              }
              
              searchTimeout = setTimeout(() => {
                  fetch(`/ajax_search.php?q=${encodeURIComponent(query)}`)
                      .then(res => res.json())
                      .then(data => {
                          if (data.length > 0) {
                              searchDropdown.innerHTML = data.map(item => {
                                  const imgHtml = item.image 
                                      ? `<img src="${item.image}" alt="" class="search-dropdown-img">`
                                      : `<div class="search-dropdown-img text-avatar">${item.initial}</div>`;
                                  
                                  return `
                                  <a href="${item.url}" class="search-dropdown-item">
                                      ${imgHtml}
                                      <div class="search-dropdown-info">
                                          <span class="search-dropdown-title">${item.title}</span>
                                          <span class="search-dropdown-type">${item.type}</span>
                                      </div>
                                  </a>
                              `}).join('') + `<a href="/search.php?q=${encodeURIComponent(query)}" class="search-dropdown-more">Rodyti visus rezultatus &rarr;</a>`;
                              searchDropdown.classList.add('active');
                          } else {
                              searchDropdown.innerHTML = `<div style="padding: 12px; text-align: center; font-size: 13px; color: #6b6b7a;">Pagal šią užklausą nieko nerasta</div>`;
                              searchDropdown.classList.add('active');
                          }
                      })
                      .catch(err => console.error('Paieškos klaida:', err));
              }, 300);
          });

          // Uždaryti dropdown paspaudus bet kur kitur
          document.addEventListener('click', (e) => {
              if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target)) {
                  searchDropdown.classList.remove('active');
              }
          });
          
          // Atidaryti dropdown sugrįžus į laukelį (jei jau yra įvestas tekstas)
          searchInput.addEventListener('focus', () => {
              if (searchInput.value.trim().length >= 2 && searchDropdown.innerHTML !== '') {
                  searchDropdown.classList.add('active');
              }
          });
      }
  </script>
  <?php
}

function resolvePdo(?PDO $pdo = null): PDO {
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    return getPdo();
}

function renderFooter(?PDO $pdo = null): void {
    static $footerCssPrinted = false;
    if (!$footerCssPrinted) {
        $footerCssPrinted = true;
        echo <<<CSS
<style>
.footer { background: #121212; color: #fff; padding: 40px 22px; margin-top: 60px; font-size: 14px; }
.footer-inner { max-width: 1200px; margin: 0 auto; display: flex; flex-direction: column; gap: 10px; }
.footer-top { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px; }
.footer-nav { display: flex; gap: 24px; }
.footer-nav a { color: #ccc; text-decoration: none; font-weight: 500; transition: color 0.2s; }
.footer-nav a:hover { color: #fff; }
.footer-social { display: flex; align-items: center; gap: 14px; }
.footer-social span { color: #888; margin-right: 6px; font-weight: 500; }
.footer-social a { color: #fff; opacity: 0.8; transition: opacity 0.2s; display: inline-flex; }
.footer-social a:hover { opacity: 1; }
.footer-social svg { width: 22px; height: 22px; fill: currentColor; }
.footer-bottom { color: #666; font-size: 13px; text-align: left; }

@media (max-width: 800px) {
  .footer-top { flex-direction: column; gap: 20px; text-align: center; }
  .footer-nav { flex-wrap: wrap; justify-content: center; gap: 16px; }
  .footer-bottom { text-align: center; }
}
</style>
CSS;
    }
    ?>
    <footer class="footer">
      <div class="footer-inner">
        <div class="footer-top">
           <div class="footer-nav">
              <a href="news.php">Naujienos</a>
              <a href="recipes.php">Receptai</a>
              <a href="products.php">Parduotuvė</a>
              <a href="community.php">Bendruomenė</a>
              <a href="about.php">Apie mus</a>
           </div>
           <div class="footer-social">
              <span>Mus galite rasti ir čia</span>
              <a href="https://www.facebook.com/cukrinukasbecukraus" aria-label="Facebook" target="_blank" rel="noopener">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2.04c-5.5 0-10 4.49-10 10.02 0 5 3.66 9.15 8.44 9.9v-7H7.9v-2.9h2.54V9.85c0-2.51 1.49-3.89 3.78-3.89 1.09 0 2.23.19 2.23.19v2.47h-1.26c-1.24 0-1.63.77-1.63 1.56v1.88h2.78l-.45 2.9h-2.33v7a10 10 0 0 0 8.44-9.9c0-5.53-4.5-10.02-10-10.02Z"/></svg>
              </a>
              <a href="https://www.instagram.com/cukrinukaslt/" aria-label="Instagram" target="_blank" rel="noopener">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 3.25.15 4.77 1.69 4.92 4.92.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.15 3.23-1.66 4.77-4.92 4.92-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-3.26-.15-4.77-1.7-4.92-4.92-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85C2.38 3.92 3.9 2.38 7.15 2.23c1.27-.06 1.65-.07 4.85-.07m0-2.16c-3.26 0-3.67.01-4.95.07C3.76.23 1.05 1.77.47 5.05.41 6.33.4 6.74.4 10s.01 3.67.07 4.95c.58 3.28 3.29 4.82 6.58 4.88 1.28.06 1.69.07 4.95.07s3.67-.01 4.95-.07c3.29-.06 4.83-1.6 4.88-4.88.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.56-3.28-3.28-4.82-6.58-4.88C15.67.01 15.26 0 12 0Z"/><path d="M12 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32Zm0 10.16a4 4 0 1 1 0-8 4 4 0 0 1 0 8Z"/><path d="M20.63 4.8a1.44 1.44 0 1 1-2.88 0 1.44 1.44 0 0 1 2.88 0Z"/></svg>
              </a>
           </div>
        </div>
        <div class="footer-bottom">
           © 2026 cukrinukas.lt. Visos teisės saugomos
        </div>
      </div>
    <?php include_once __DIR__ . '/cookie_banner.php'; ?>
    </footer>
    <?php
}