<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/security.php';

enforcePostRequestCsrf();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Nepakanka teisių']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['image']['name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Neteisinga užklausa']);
    exit;
}

$allowedMimeMap = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];

if (($_FILES['image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Įkelti nepavyko.']);
    exit;
}

$url = saveUploadedFile($_FILES['image'], $allowedMimeMap, 'editor_');
if (!$url) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Leidžiami formatai: jpg, jpeg, png, webp, gif.']);
    exit;
}

echo json_encode(['success' => true, 'url' => $url]);
