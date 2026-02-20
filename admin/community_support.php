<?php
// admin/community_support.php
session_start();
require_once __DIR__ . '/../db.php';

$user = currentUser();
if (!$user || $user['role'] !== 'admin') {
    die("Neturite teisių.");
}

$pdo = getPdo();

// Būsenos atnaujinimas
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($_GET['action'] == 'close_ticket') {
        $pdo->prepare("UPDATE community_tickets SET status = 'closed' WHERE id = ?")->execute([$id]);
        header("Location: community_support.php?msg=ticket_closed");
        exit;
    }
    if ($_GET['action'] == 'close_report') {
        $pdo->prepare("UPDATE community_reports SET status = 'closed' WHERE id = ?")->execute([$id]);
        header("Location: community_support.php?msg=report_closed");
        exit;
    }
}

$tickets = $pdo->query("SELECT t.*, u.email, u.name FROM community_tickets t JOIN users u ON t.user_id = u.id ORDER BY t.created_at DESC")->fetchAll();
$reports = $pdo->query("SELECT r.*, u.email, u.name, l.title as listing_title FROM community_reports r JOIN users u ON r.reporter_id = u.id JOIN community_listings l ON r.listing_id = l.id ORDER BY r.created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="UTF-8">
    <title>Skundų Valdymas</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f4f4f4; }
        .card { background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #eee; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .badge-open { background: #ffcccc; color: #cc0000; }
        .badge-closed { background: #ccffcc; color: #008800; }
        .btn { padding: 6px 12px; color: #fff; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight:bold; }
        .btn-back { background: #555; display:inline-block; margin-bottom:20px;}
        .btn-close { background: #28a745; }
        .text-muted { color: #666; font-size:12px; }
    </style>
</head>
<body>
    <h1>Turgelio Skundai ir Pranešimai</h1>
    <a href="community.php" class="btn btn-back">&larr; Atgal į pagrindinį admin</a>
    
    <div class="card">
        <h2>Pirkėjų / Pardavėjų Skundai (Tickets)</h2>
        <table>
            <tr>
                <th>Veiksmai</th>
                <th>Būsena</th>
                <th>Vartotojas</th>
                <th>Užsakymo ID</th>
                <th>Problema</th>
                <th>Tekstas</th>
                <th>Data</th>
            </tr>
            <?php foreach ($tickets as $t): ?>
            <tr>
                <td>
                    <?php if ($t['status'] == 'open'): ?>
                        <a href="community_support.php?action=close_ticket&id=<?= $t['id'] ?>" class="btn btn-close">Žymėti kaip išspręstą</a>
                    <?php else: ?>
                        <span class="text-muted">Išspręsta</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= $t['status'] == 'open' ? 'badge-open' : 'badge-closed' ?>"><?= $t['status'] ?></span></td>
                <td><?= htmlspecialchars($t['name']) ?><br><small><?= $t['email'] ?></small></td>
                <td><?= $t['order_id'] ? '<b>#' . $t['order_id'] . '</b>' : '-' ?></td>
                <td><?= htmlspecialchars($t['type']) ?></td>
                <td><?= nl2br(htmlspecialchars($t['message'])) ?></td>
                <td><?= $t['created_at'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p class="text-muted">Pastaba: Norėdami atlikti Refund arba Force Payout, eikite į pagrindinį "Užsakymai" skirtuką pagal nurodytą ID.</p>
    </div>

    <div class="card">
        <h2>Pranešimai apie skelbimus (Reports)</h2>
        <table>
            <tr>
                <th>Veiksmai</th>
                <th>Būsena</th>
                <th>Pranešėjas</th>
                <th>Skelbimas</th>
                <th>Priežastis</th>
                <th>Detalės</th>
            </tr>
            <?php foreach ($reports as $r): ?>
            <tr>
                <td>
                    <?php if ($r['status'] == 'open'): ?>
                        <a href="community_support.php?action=close_report&id=<?= $r['id'] ?>" class="btn btn-close">Žymėti kaip peržiūrėtą</a>
                    <?php else: ?>
                        <span class="text-muted">Peržiūrėta</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= $r['status'] == 'open' ? 'badge-open' : 'badge-closed' ?>"><?= $r['status'] ?></span></td>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><a href="/community_listing.php?id=<?= $r['listing_id'] ?>" target="_blank"><?= htmlspecialchars($r['listing_title']) ?></a></td>
                <td><b><?= htmlspecialchars($r['reason']) ?></b></td>
                <td><?= nl2br(htmlspecialchars($r['details'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</body>
</html>
