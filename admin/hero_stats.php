<?php
$ordersCountHero = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$userCountHero = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalSalesHero = (float)$pdo->query('SELECT COALESCE(SUM(total), 0) FROM orders')->fetchColumn();
$averageOrderHero = $ordersCountHero > 0 ? $totalSalesHero / $ordersCountHero : 0;
?>
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
      <div class="stat-value"><?php echo number_format($totalSalesHero, 2); ?> €</div>
      <div class="stat-sub">Apima visus užsakymus</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">UŽSAKYMAI</div>
      <div class="stat-value"><?php echo (int)$ordersCountHero; ?></div>
      <div class="stat-sub">Šioje parduotuvėje</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">VID. UŽSAKYMAS</div>
      <div class="stat-value"><?php echo number_format($averageOrderHero, 2); ?> €</div>
      <div class="stat-sub">Pastaruosius visus</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">VARTOTOJAI</div>
      <div class="stat-value"><?php echo (int)$userCountHero; ?></div>
      <div class="stat-sub">Bendruomenės nariai</div>
    </div>
  </div>
</div>
