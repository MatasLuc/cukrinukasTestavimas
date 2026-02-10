<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php'; // Būtina slugify funkcijai

$pdo = getPdo();
ensureProductsTable($pdo);
ensureCartTables($pdo);
ensureAdminAccount($pdo);
tryAutoLogin($pdo);

// --- KREPŠELIO SURINKIMO LOGIKA ---
$rawCart = $_SESSION['cart'] ?? [];
$rawVariations = $_SESSION['cart_variations'] ?? [];

$items = [];
$total = 0;
$freeShippingIds = [];

// Nuolaidų gavimas (jei helperiai naudoja tas pačias funkcijas)
$categoryDiscounts = getCategoryDiscounts($pdo);
$globalDiscount = getGlobalDiscount($pdo);
$fsProducts = getFreeShippingProducts($pdo);
$fsIds = array_column($fsProducts, 'product_id');

// Surenkame unikalius produktų ID iš krepšelio raktų
$productIdsToFetch = [];
foreach (array_keys($rawCart) as $key) {
    // Raktas gali būti "123" arba "123_md5hash"
    $parts = explode('_', $key);
    $pid = (int)$parts[0];
    if ($pid > 0) {
        $productIdsToFetch[$pid] = true;
    }
}

$fetchedProducts = [];
if (!empty($productIdsToFetch)) {
    $placeholders = implode(',', array_fill(0, count($productIdsToFetch), '?'));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
    $stmt->execute(array_keys($productIdsToFetch));
    while ($row = $stmt->fetch()) {
        $fetchedProducts[$row['id']] = $row;
    }
}

// Formuojame items sąrašą
foreach ($rawCart as $key => $qty) {
    $parts = explode('_', $key);
    $pid = (int)$parts[0];
    
    if (!isset($fetchedProducts[$pid])) continue;
    $product = $fetchedProducts[$pid];
    
    // Prijungiame variacijas
    $currentVariations = $rawVariations[$key] ?? [];
    
    // Skaičiuojame kainą su variacijomis
    $variationDelta = 0;
    foreach ($currentVariations as $cv) {
        $variationDelta += (float)($cv['delta'] ?? 0);
    }
    
    // Bazinės kainos
    $basePrice = (float)$product['price'] + $variationDelta;
    $salePrice = ($product['sale_price'] !== null) ? ((float)$product['sale_price'] + $variationDelta) : null;
    
    // Pritaikome nuolaidas
    // Pastaba: čia naudojama supaprastinta logika, atkartojanti helperius
    // Jei helperių funkcijos prieinamos, geriausia naudoti jas, bet čia įdedame tiesioginį skaičiavimą
    
    // Kategorijos nuolaida
    $catDisc = $categoryDiscounts[$product['category_id']] ?? null;
    $finalPrice = ($salePrice !== null) ? $salePrice : $basePrice;
    
    // Globali nuolaida
    if ($globalDiscount['type'] === 'percent') $finalPrice *= (1 - $globalDiscount['value']/100);
    elseif ($globalDiscount['type'] === 'amount') $finalPrice -= $globalDiscount['value'];
    
    // Kategorijos
    if ($catDisc) {
        if ($catDisc['type'] === 'percent') $finalPrice *= (1 - $catDisc['value']/100);
        elseif ($catDisc['type'] === 'amount') $finalPrice -= $catDisc['value'];
    }
    
    $finalPrice = max(0, $finalPrice);
    
    // Ar nemokamas pristatymas?
    if (in_array($pid, $fsIds)) {
        $freeShippingIds[] = $pid;
    }

    $items[] = [
        'id' => $pid,
        'cart_key' => $key, // Svarbu ištrynimui
        'title' => $product['title'],
        'image_url' => $product['image_url'],
        'quantity' => $qty,
        'price' => $finalPrice,
        'line_total' => $finalPrice * $qty,
        'variation' => $currentVariations,
        'free_shipping_gift' => in_array($pid, $fsIds)
    ];
    
    $total += ($finalPrice * $qty);
}

$freeShippingOffers = getFreeShippingProducts($pdo);
$hasGiftProduct = !empty($freeShippingIds);

// --- PABAIGA LOGIKOS ---

if (isset($_POST['remove_key'])) {
    validateCsrfToken();
    $removeKey = $_POST['remove_key'];
    unset($_SESSION['cart'][$removeKey]);
    unset($_SESSION['cart_variations'][$removeKey]);
    
    // DB valymas (jei naudojama) - čia reikėtų sudėtingesnės logikos, 
    // todėl kol kas paliekame tik sesiją.
    
    header('Location: /cart.php');
    exit;
}

if (isset($_POST['add_promo_product'])) {
    validateCsrfToken();
    $pid = (int)$_POST['add_promo_product'];
    // Promo prekės paprastai neturi variacijų, tad naudojame paprastą ID kaip raktą
    $key = (string)$pid . '_default';
    if ($pid > 0) {
        $_SESSION['cart'][$key] = ($_SESSION['cart'][$key] ?? 0) + 1;
    }
    header('Location: /cart.php');
    exit;
}
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Krepšelis | Cukrinukas.lt</title>
  <?php echo headerStyles(); ?>
  <style>
    :root {
      --bg: #f7f7fb;
      --card: #ffffff;
      --border: #e4e7ec;
      --text-main: #0f172a;
      --text-muted: #475467;
      --accent: #2563eb;
      --accent-hover: #1d4ed8;
      --danger: #ef4444;
      --success-bg: #ecfdf5;
      --success-text: #065f46;
    }
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; transition: color .2s; }
    
    .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; }

    /* Hero Section (Match orders.php) */
    .hero { 
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border:1px solid #dbeafe; 
        border-radius:24px; 
        padding:32px; 
        margin-bottom: 32px;
        display:flex; 
        flex-direction: column;
        align-items: flex-start;
        gap:16px; 
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .hero h1 { margin:0; font-size:28px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; line-height:1.5; font-size:15px; }
    .hero .pill { 
        display:inline-flex; align-items:center; gap:8px; 
        padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #bfdbfe; 
        font-weight:600; font-size:13px; color:#1e40af; 
    }

    /* Layout Grid */
    .cart-grid {
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 24px;
        align-items: start;
    }

    /* Cards */
    .card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 16px;
        box-shadow: 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }
    .card-body { padding: 24px; }
    
    .section-title {
        font-size: 18px;
        font-weight: 700;
        margin: 0 0 20px 0;
        color: var(--text-main);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    /* Cart Items */
    .cart-item {
        display: grid;
        grid-template-columns: 80px 1fr auto;
        gap: 20px;
        padding-bottom: 24px;
        margin-bottom: 24px;
        border-bottom: 1px solid var(--border);
    }
    .cart-item:last-child {
        padding-bottom: 0;
        margin-bottom: 0;
        border-bottom: none;
    }
    .item-img {
        width: 80px;
        height: 80px;
        object-fit: contain;
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 4px;
    }
    .item-info h3 {
        margin: 0 0 6px;
        font-size: 16px;
        font-weight: 600;
        line-height: 1.4;
    }
    .item-meta {
        font-size: 13px;
        color: var(--text-muted);
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .item-actions {
        text-align: right;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        align-items: flex-end;
    }
    .item-price {
        font-weight: 700;
        font-size: 16px;
        color: var(--text-main);
    }
    .remove-btn {
        background: none;
        border: none;
        color: var(--danger);
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        padding: 0;
        text-decoration: underline;
        opacity: 0.8;
        transition: opacity .2s;
    }
    .remove-btn:hover { opacity: 1; }

    /* Summary Sidebar */
    .summary-box {
        position: sticky;
        top: 90px; /* Pagal headerio aukštį */
    }
    .summary-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 12px;
        font-size: 15px;
        color: var(--text-muted);
    }
    .summary-row.total {
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid var(--border);
        font-weight: 700;
        font-size: 18px;
        color: var(--text-main);
    }
    
    /* Promo / Free Shipping Box */
    .promo-box {
        margin-bottom: 24px;
        background: linear-gradient(135deg, #f0fdf4, #dcfce7);
        border: 1px solid #bbf7d0;
        border-radius: 16px;
        padding: 20px;
    }
    .promo-header {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 16px;
    }
    .promo-icon { font-size: 20px; }
    .promo-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 12px;
    }
    .promo-item {
        background: #fff;
        border: 1px solid #bbf7d0;
        border-radius: 12px;
        padding: 10px;
        display: flex;
        gap: 10px;
        align-items: center;
        transition: transform .2s;
    }
    .promo-item:hover { transform: translateY(-2px); border-color: #86efac; }
    .promo-item img {
        width: 48px;
        height: 48px;
        object-fit: contain;
        border-radius: 8px;
    }
    .promo-btn {
        margin-top: 4px;
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 6px;
        border: none;
        background: #16a34a;
        color: #fff;
        font-weight: 600;
        cursor: pointer;
        width: 100%;
    }
    .promo-btn:hover { background: #15803d; }

    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 14px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 15px;
        cursor: pointer;
        transition: all .2s;
        text-decoration: none;
        border: none;
    }
    .btn-primary {
        background: #0f172a;
        color: #fff;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    .btn-primary:hover {
        background: #1e293b;
        color: #ffffff !important; /* FIX: Priverstinė balta spalva */
        transform: translateY(-1px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }
    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--text-main);
        margin-top: 12px;
    }
    .btn-outline:hover {
        border-color: var(--accent);
        color: var(--accent);
        background: #eff6ff;
    }

    .empty-state {
        text-align: center;
        padding: 48px 20px;
    }

    /* Utilities */
    .badge-success {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 8px;
        background: var(--success-bg);
        color: var(--success-text);
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        margin-top: 4px;
    }

    @media (max-width: 900px) {
        .cart-grid { grid-template-columns: 1fr; }
        .summary-box { position: static; margin-top: 0; }
        .hero { align-items: flex-start; text-align: left; }
    }
    @media (max-width: 600px) {
        .cart-item { grid-template-columns: 60px 1fr; grid-template-rows: auto auto; gap: 12px; }
        .item-actions { grid-column: 1 / -1; flex-direction: row; justify-content: space-between; align-items: center; width: 100%; margin-top: 8px; }
        .item-img { width: 60px; height: 60px; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'cart'); ?>
  
  <div class="page">
    <section class="hero">
      <div class="pill">🛍️ Jūsų krepšelis</div>
      <div>
        <h1>Krepšelio peržiūra</h1>
        <p>Patikrinkite pasirinktas prekes ir tęskite link apmokėjimo.</p>
      </div>
    </section>

    <?php if (!$items): ?>
      <div class="card empty-state">
        <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">🛒</div>
        <h3 style="margin: 0 0 8px; font-size: 20px;">Krepšelis tuščias</h3>
        <p style="color: var(--text-muted); margin: 0 0 24px;">Atrodo, dar nieko neišsirinkote. Peržiūrėkite mūsų asortimentą.</p>
        <div style="max-width: 250px; margin: 0 auto;">
            <a href="/products.php" class="btn btn-primary">Eiti į parduotuvę</a>
        </div>
      </div>
    <?php else: ?>
      
      <div class="cart-grid">
        <div class="cart-main">
            
          <?php if ($freeShippingOffers): ?>
            <div class="promo-box">
              <div class="promo-header">
                <div class="promo-icon">🚚</div>
                <div>
                    <strong style="color: #14532d; display: block; margin-bottom: 2px;">
                        <?php echo $hasGiftProduct ? 'Nemokamas pristatymas pritaikytas!' : 'Gaukite nemokamą pristatymą'; ?>
                    </strong>
                    <span style="font-size: 14px; color: #166534;">
                        <?php echo $hasGiftProduct ? 'Pasirinkta dovanos prekė suteikia 0 € pristatymą.' : 'Pridėkite vieną iš šių prekių į krepšelį:'; ?>
                    </span>
                </div>
              </div>
              
              <div class="promo-grid">
                <?php foreach ($freeShippingOffers as $offer): $offerPrice = $offer['sale_price'] !== null ? (float)$offer['sale_price'] : (float)$offer['price']; ?>
                  <div class="promo-item">
                    <img src="<?php echo htmlspecialchars($offer['image_url']); ?>" alt="<?php echo htmlspecialchars($offer['title']); ?>">
                    <div style="flex:1; min-width:0;">
                      <div style="font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($offer['title']); ?></div>
                      <div style="font-size:13px; color:#15803d; font-weight:700;"><?php echo number_format($offerPrice, 2); ?> €</div>
                      <form method="post">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="add_promo_product" value="<?php echo (int)$offer['product_id']; ?>">
                        <button class="promo-btn" type="submit">Pridėti +</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="card">
            <div class="card-body">
                <h2 class="section-title">Prekių sąrašas <span style="font-weight:400; color:var(--text-muted); font-size:14px;"><?php echo count($items); ?> vnt.</span></h2>
                
                <?php foreach ($items as $item): ?>
                  <?php 
                    // SEO URL generavimas krepšelio prekėms
                    $itemUrl = '/produktas/' . slugify($item['title']) . '-' . (int)$item['id']; 
                    
                    // Variacijos
                    $itemVariations = $item['variation'] ?? [];
                  ?>
                  <div class="cart-item">
                    <a href="<?php echo htmlspecialchars($itemUrl); ?>">
                        <img class="item-img" src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                    </a>
                    
                    <div class="item-info">
                      <h3><a href="<?php echo htmlspecialchars($itemUrl); ?>"><?php echo htmlspecialchars($item['title']); ?></a></h3>
                      <div class="item-meta">
                        <?php if ($itemVariations): ?>
                            <?php foreach ($itemVariations as $v): ?>
                                <?php 
                                    $vGroup = $v['group'] ?? $v['group_name'] ?? 'Variacija';
                                    $vName = $v['name'] ?? '';
                                ?>
                                <?php if($vName): ?>
                                    <span><?php echo htmlspecialchars($vGroup); ?>: <strong><?php echo htmlspecialchars($vName); ?></strong></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <span>Kiekis: <?php echo $item['quantity']; ?> × <?php echo number_format((float)$item['price'], 2); ?> €</span>
                        
                        <?php if (!empty($item['free_shipping_gift'])): ?>
                           <span class="badge-success">
                               <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                               Nemokamas pristatymas
                           </span>
                        <?php endif; ?>
                      </div>
                    </div>

                    <div class="item-actions">
                      <div class="item-price"><?php echo number_format($item['line_total'], 2); ?> €</div>
                      <form method="post" style="margin-top: 8px;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="remove_key" value="<?php echo htmlspecialchars($item['cart_key']); ?>">
                        <button class="remove-btn" type="submit">Pašalinti</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="summary-box">
            <div class="card">
                <div class="card-body">
                    <h2 class="section-title">Užsakymo suma</h2>
                    
                    <div class="summary-row">
                        <span>Tarpinė suma</span>
                        <span><?php echo number_format($total, 2); ?> €</span>
                    </div>
                    <?php if ($hasGiftProduct): ?>
                        <div class="summary-row" style="color: #166534;">
                            <span>Pristatymas</span>
                            <span>0.00 €</span>
                        </div>
                    <?php else: ?>
                        <div class="summary-row">
                            <span>Pristatymas</span>
                            <span style="font-size:13px;">Skaičiuojama kitame žingsnyje</span>
                        </div>
                    <?php endif; ?>

                    <div class="summary-row total">
                        <span>Iš viso</span>
                        <span><?php echo number_format($total, 2); ?> €</span>
                    </div>

                    <div style="margin-top: 24px;">
                        <a href="/checkout.php" class="btn btn-primary" onclick="fbq('track', 'InitiateCheckout');">
                            Apmokėti užsakymą
                        </a>
                        <a href="/products.php" class="btn btn-outline">
                            ← Grįžti į parduotuvę
                        </a>
                    </div>
                    
                    <div style="margin-top: 16px; font-size: 12px; color: var(--text-muted); text-align: center; line-height: 1.5;">
                        Saugus atsiskaitymas per elektroninę bankininkystę. Duomenys saugomi pagal BDAR.
                    </div>
                </div>
            </div>
        </div>

      </div>
    <?php endif; ?>
  </div>

  <?php renderFooter($pdo); ?>
</body>
</html>
