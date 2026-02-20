<?php
// report_listing.php
session_start();
require __DIR__ . '/db.php';

header('Content-Type: application/json');

$pdo = getPdo();
$user = currentUser();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Turite prisijungti, kad praneštumėte.']);
    exit;
}

$listing_id = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
$reason = $_POST['reason'] ?? '';
$details = $_POST['details'] ?? '';

if (!$listing_id || !$reason) {
    echo json_encode(['success' => false, 'error' => 'Trūksta duomenų.']);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO community_reports (reporter_id, listing_id, reason, details) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user['id'], $listing_id, $reason, $details]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Įvyko serverio klaida.']);
}
