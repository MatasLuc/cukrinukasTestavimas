<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php'; // Reikalinga slugify funkcijai

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$pdo = getPdo();
$searchTerm = '%' . $q . '%';
$results = [];
$limit = 3;

// Pagalbinė funkcija rezultatų formatavimui ir dėjimi į bendrą masyvą
$addResults = function($items, $type, $urlPrefix, $urlSuffix = '') use (&$results, $limit) {
    foreach ($items as $item) {
        if (count($results) >= $limit) return;
        
        $image = null;
        if (!empty($item['image_url'])) {
            $image = $item['image_url'];
        } elseif (!empty($item['profile_photo'])) {
            $image = $item['profile_photo'];
        }
        
        $title = $item['title'] ?? $item['name'] ?? '?';
        $initial = mb_strtoupper(mb_substr(trim($title), 0, 1));
        
        $url = $urlPrefix . slugify($title) . '-' . $item['id'] . $urlSuffix;
        
        // Individualūs URL formatai
        if ($type === 'Diskusija') $url = '/community_thread.php?id=' . $item['id'];
        if ($type === 'Bendruomenės prekė') $url = '/community_listing.php?id=' . $item['id'];
        if ($type === 'Narys') $url = '/user_profile.php?id=' . $item['id'];

        $results[] = [
            'title' => $title,
            'url' => $url,
            'image' => $image,
            'initial' => $initial,
            'type' => $type
        ];
    }
};

// 1. Parduotuvės prekės
$stmt = $pdo->prepare("SELECT id, title, image_url FROM products WHERE title LIKE ? LIMIT 3");
$stmt->execute([$searchTerm]);
$addResults($stmt->fetchAll(PDO::FETCH_ASSOC), 'Prekė', '/produktas/');

if (count($results) < $limit) {
    // 2. Receptai
    $stmt = $pdo->prepare("SELECT id, title, image_url FROM recipes WHERE title LIKE ? LIMIT 3");
    $stmt->execute([$searchTerm]);
    $addResults($stmt->fetchAll(PDO::FETCH_ASSOC), 'Receptas', '/receptas/');
}

if (count($results) < $limit) {
    // 3. Naujienos
    $stmt = $pdo->prepare("SELECT id, title, image_url FROM news WHERE title LIKE ? LIMIT 3");
    $stmt->execute([$searchTerm]);
    $addResults($stmt->fetchAll(PDO::FETCH_ASSOC), 'Naujiena', '/naujiena/');
}

if (count($results) < $limit) {
    // 4. Bendruomenės prekės
    $stmt = $pdo->prepare("SELECT id, title, image_url FROM community_listings WHERE title LIKE ? LIMIT 3");
    $stmt->execute([$searchTerm]);
    $addResults($stmt->fetchAll(PDO::FETCH_ASSOC), 'Bendruomenės prekė', '');
}

if (count($results) < $limit) {
    // 5. Diskusijos
    $stmt = $pdo->prepare("SELECT id, title FROM community_threads WHERE title LIKE ? LIMIT 3");
    $stmt->execute([$searchTerm]);
    $addResults($stmt->fetchAll(PDO::FETCH_ASSOC), 'Diskusija', '');
}

if (count($results) < $limit) {
    // 6. Nariai
    $stmt = $pdo->prepare("SELECT id, name, profile_photo FROM users WHERE name LIKE ? LIMIT 3");
    $stmt->execute([$searchTerm]);
    $addResults($stmt->fetchAll(PDO::FETCH_ASSOC), 'Narys', '');
}

echo json_encode($results);
exit;