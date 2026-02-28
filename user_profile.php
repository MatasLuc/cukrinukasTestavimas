<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$userId) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>Nenurodytas vartotojo ID.</div></div>";
    renderFooter();
    exit;
}

// Gauname profilio vartotojo duomenis
$stmt = $pdo->prepare("SELECT id, name, email, created_at FROM users WHERE id = ?");
$stmt->execute([$userId]);
$profileUser = $stmt->fetch();

if (!$profileUser) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>Vartotojas nerastas.</div></div>";
    renderFooter();
    exit;
}

$currentUserId = $_SESSION['user_id'] ?? null;
$error = '';
$success = '';

// Atsiliepimo formos apdorojimas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!$currentUserId) {
        $error = "Prisijunkite, kad galėtumėte palikti atsiliepimą.";
    } elseif ($currentUserId === $profileUser['id']) {
        $error = "Negalite vertinti patys savęs.";
    } else {
        $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
        $reviewText = isset($_POST['review_text']) ? trim($_POST['review_text']) : '';

        if ($rating < 1 || $rating > 5) {
            $error = "Įvertinimas turi būti nuo 1 iki 5 žvaigždučių.";
        } elseif (mb_strlen($reviewText) < 5) {
            $error = "Atsiliepimas per trumpas. Parašykite bent kelis žodžius.";
        } else {
            // Patikriname, ar šis vartotojas jau yra palikęs atsiliepimą šiam nariui
            $checkStmt = $pdo->prepare("SELECT id FROM user_reviews WHERE reviewer_id = ? AND reviewee_id = ?");
            $checkStmt->execute([$currentUserId, $profileUser['id']]);
            $existingReview = $checkStmt->fetch();

            if ($existingReview) {
                // Atnaujiname esamą atsiliepimą
                $updateStmt = $pdo->prepare("UPDATE user_reviews SET rating = ?, review_text = ?, created_at = NOW() WHERE id = ?");
                $updateStmt->execute([$rating, $reviewText, $existingReview['id']]);
                $success = "Atsiliepimas sėkmingai atnaujintas!";
            } else {
                // Įkeliame naują atsiliepimą
                $insertStmt = $pdo->prepare("INSERT INTO user_reviews (reviewer_id, reviewee_id, rating, review_text) VALUES (?, ?, ?, ?)");
                $insertStmt->execute([$currentUserId, $profileUser['id'], $rating, $reviewText]);
                $success = "Atsiliepimas sėkmingai pridėtas!";
            }
        }
    }
}

// Ištraukiame esamą prisijungusio vartotojo atsiliepimą, kad galėtume jį atvaizduoti redagavimo formoje
$existingReviewForm = null;
if ($currentUserId && $currentUserId !== $profileUser['id']) {
    $checkFormStmt = $pdo->prepare("SELECT * FROM user_reviews WHERE reviewer_id = ? AND reviewee_id = ?");
    $checkFormStmt->execute([$currentUserId, $profileUser['id']]);
    $existingReviewForm = $checkFormStmt->fetch();
}

// Ištraukiame atsiliepimų statistiką (vidurkį ir kiekį)
$statsStmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(id) as total_reviews FROM user_reviews WHERE reviewee_id = ?");
$statsStmt->execute([$profileUser['id']]);
$stats = $statsStmt->fetch();
$avgRating = $stats['avg_rating'] ? round($stats['avg_rating'], 1) : 0;
$totalReviews = $stats['total_reviews'];

// Ištraukiame visus nario gautus atsiliepimus
$reviewsStmt = $pdo->prepare("
    SELECT r.*, u.name as reviewer_name 
    FROM user_reviews r 
    JOIN users u ON r.reviewer_id = u.id 
    WHERE r.reviewee_id = ? 
    ORDER BY r.created_at DESC
");
$reviewsStmt->execute([$profileUser['id']]);
$reviews = $reviewsStmt->fetchAll();

// (Papildoma) Ištraukiame šio nario aktyvius turgelio skelbimus (jei naudojate community_listings)
$listings = [];
try {
    $listingsStmt = $pdo->prepare("SELECT id, title, price, image FROM community_listings WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 6");
    $listingsStmt->execute([$profileUser['id']]);
    $listings = $listingsStmt->fetchAll();
} catch (Exception $e) {
    // Ignoruojame, jei skelbimų sistemos nėra arba stulpeliai nesutampa
}

// Pataisyta eilutė - pridedamas $pdo ir naudojamas ['name']
renderHeader($pdo, $profileUser['name'] . " profilis");
?>

<div class="container mt-5">
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card shadow-sm text-center">
                <div class="card-body">
                    <div class="mb-3">
                        <div class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center" style="width: 100px; height: 100px; font-size: 2.5rem;">
                            <?= mb_strtoupper(mb_substr($profileUser['name'], 0, 1)) ?>
                        </div>
                    </div>
                    <h3 class="card-title"><?= htmlspecialchars($profileUser['name']) ?></h3>
                    <p class="text-muted mb-1">Bendruomenės narys nuo <?= date('Y-m-d', strtotime($profileUser['created_at'])) ?></p>
                    <div class="mt-3">
                        <span class="fs-4 text-warning">
                            <?= str_repeat('★', round($avgRating)) ?><?= str_repeat('☆', 5 - round($avgRating)) ?>
                        </span>
                        <br>
                        <strong><?= $avgRating ?> / 5</strong> (<?= $totalReviews ?> atsiliepimų)
                    </div>
                </div>
            </div>

            <?php if ($currentUserId && $currentUserId !== $profileUser['id']): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><?= $existingReviewForm ? 'Redaguoti savo atsiliepimą' : 'Palikti atsiliepimą' ?></h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Įvertinimas</label>
                                <select name="rating" class="form-select" required>
                                    <option value="" disabled <?= !$existingReviewForm ? 'selected' : '' ?>>Pasirinkite įvertinimą...</option>
                                    <option value="5" <?= ($existingReviewForm && $existingReviewForm['rating'] == 5) ? 'selected' : '' ?>>5 ★ - Puikiai</option>
                                    <option value="4" <?= ($existingReviewForm && $existingReviewForm['rating'] == 4) ? 'selected' : '' ?>>4 ★ - Gerai</option>
                                    <option value="3" <?= ($existingReviewForm && $existingReviewForm['rating'] == 3) ? 'selected' : '' ?>>3 ★ - Vidutiniškai</option>
                                    <option value="2" <?= ($existingReviewForm && $existingReviewForm['rating'] == 2) ? 'selected' : '' ?>>2 ★ - Prastai</option>
                                    <option value="1" <?= ($existingReviewForm && $existingReviewForm['rating'] == 1) ? 'selected' : '' ?>>1 ★ - Labai prastai</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Komentaras</label>
                                <textarea name="review_text" class="form-control" rows="3" required placeholder="Aprašykite savo patirtį..."><?= $existingReviewForm ? htmlspecialchars($existingReviewForm['review_text']) : '' ?></textarea>
                            </div>
                            <button type="submit" name="submit_review" class="btn btn-primary w-100">
                                <?= $existingReviewForm ? 'Atnaujinti atsiliepimą' : 'Pateikti atsiliepimą' ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php elseif (!$currentUserId): ?>
                <div class="alert alert-info mt-4 text-center">
                    <a href="login.php" class="alert-link">Prisijunkite</a>, kad galėtumėte palikti atsiliepimą.
                </div>
            <?php endif; ?>
        </div>

        <div class="col-md-8">
            <h4 class="mb-3">Nario atsiliepimai</h4>
            <?php if (count($reviews) > 0): ?>
                <div class="list-group mb-5 shadow-sm">
                    <?php foreach ($reviews as $review): ?>
                        <div class="list-group-item list-group-item-action flex-column align-items-start p-3">
                            <div class="d-flex w-100 justify-content-between align-items-center">
                                <h6 class="mb-1 fw-bold">
                                    <a href="user_profile.php?id=<?= $review['reviewer_id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($review['reviewer_name']) ?>
                                    </a>
                                </h6>
                                <small class="text-muted"><?= date('Y-m-d', strtotime($review['created_at'])) ?></small>
                            </div>
                            <p class="mb-2 text-warning" style="font-size: 1.2rem;">
                                <?= str_repeat('★', $review['rating']) ?><?= str_repeat('☆', 5 - $review['rating']) ?>
                            </p>
                            <p class="mb-1 text-break"><?= nl2br(htmlspecialchars($review['review_text'])) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-light border text-muted">
                    Šis narys dar neturi atsiliepimų.
                </div>
            <?php endif; ?>

            <?php if (count($listings) > 0): ?>
                <h4 class="mb-3 mt-4">Šio nario skelbimai turgelyje</h4>
                <div class="row row-cols-1 row-cols-md-3 g-3">
                    <?php foreach ($listings as $listing): ?>
                        <div class="col">
                            <div class="card h-100 shadow-sm">
                                <?php if (!empty($listing['image'])): ?>
                                    <img src="uploads/<?= htmlspecialchars($listing['image']) ?>" class="card-img-top" alt="Skelbimo nuotrauka" style="height: 150px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="bg-secondary text-white d-flex align-items-center justify-content-center" style="height: 150px;">
                                        Nėra nuotraukos
                                    </div>
                                <?php endif; ?>
                                <div class="card-body p-2 text-center">
                                    <h6 class="card-title mb-1 text-truncate" title="<?= htmlspecialchars($listing['title']) ?>">
                                        <?= htmlspecialchars($listing['title']) ?>
                                    </h6>
                                    <p class="card-text text-primary fw-bold mb-2"><?= number_format($listing['price'], 2) ?> &euro;</p>
                                    <a href="community_listing.php?id=<?= $listing['id'] ?>" class="btn btn-sm btn-outline-primary w-100">Peržiūrėti</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
