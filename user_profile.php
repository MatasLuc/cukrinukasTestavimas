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
    renderHeader($pdo, "Klaida");
    echo "<div class='container py-5'><div class='alert alert-danger shadow-sm rounded-4 border-0 p-4 text-center'><h4 class='mb-0 fw-bold'>Nenurodytas vartotojo ID.</h4></div></div>";
    renderFooter();
    exit;
}

// Gauname profilio vartotojo duomenis
$stmt = $pdo->prepare("SELECT id, name, email, created_at FROM users WHERE id = ?");
$stmt->execute([$userId]);
$profileUser = $stmt->fetch();

if (!$profileUser) {
    renderHeader($pdo, "Klaida");
    echo "<div class='container py-5'><div class='alert alert-danger shadow-sm rounded-4 border-0 p-4 text-center'><h4 class='mb-0 fw-bold'>Vartotojas nerastas.</h4></div></div>";
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

// (Papildoma) Ištraukiame šio nario aktyvius turgelio skelbimus
$listings = [];
try {
    $listingsStmt = $pdo->prepare("SELECT id, title, price, image FROM community_listings WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 6");
    $listingsStmt->execute([$profileUser['id']]);
    $listings = $listingsStmt->fetchAll();
} catch (Exception $e) {
    // Ignoruojame, jei skelbimų sistemos nėra arba stulpeliai nesutampa
}

renderHeader($pdo, $profileUser['name'] . " profilis");
?>

<style>
    body { background-color: #f8f9fa; }
    .profile-avatar-lg {
        width: 120px;
        height: 120px;
        font-size: 3.5rem;
        background: linear-gradient(135deg, #0d6efd 0%, #0dcaf0 100%);
        color: white;
        box-shadow: 0 0.5rem 1rem rgba(13, 110, 253, 0.2);
    }
    .profile-avatar-sm {
        width: 48px;
        height: 48px;
        font-size: 1.25rem;
        background-color: #e9ecef;
        color: #0d6efd;
    }
    .hover-shadow { transition: box-shadow 0.3s ease, transform 0.3s ease; }
    .hover-shadow:hover { box-shadow: 0 .5rem 1.5rem rgba(0,0,0,.1)!important; transform: translateY(-3px); }
    .nav-pills .nav-link { color: #6c757d; font-weight: 600; border-radius: 50rem; padding: 0.6rem 1.5rem; transition: all 0.2s ease;}
    .nav-pills .nav-link.active { background-color: #0d6efd; color: #fff !important; box-shadow: 0 4px 10px rgba(13, 110, 253, 0.3); }
    .nav-pills .nav-link:not(.active):hover { background-color: #e9ecef; color: #0d6efd; }
    .rating-stars { letter-spacing: 2px; }
    .object-fit-cover { object-fit: cover; }
</style>

<div class="container py-5">
    
    <?php if ($error): ?>
        <div class="alert alert-danger shadow-sm rounded-4 border-0 mb-4 d-flex align-items-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-exclamation-triangle-fill me-3 flex-shrink-0" viewBox="0 0 16 16"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/></svg>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success shadow-sm rounded-4 border-0 mb-4 d-flex align-items-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-check-circle-fill me-3 flex-shrink-0" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>
            <div><?= htmlspecialchars($success) ?></div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        
        <div class="col-lg-4">
            
            <div class="card border-0 shadow-sm rounded-4 mb-4 text-center">
                <div class="card-body p-4 p-lg-5">
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold profile-avatar-lg mb-4">
                        <?= mb_strtoupper(mb_substr($profileUser['name'], 0, 1)) ?>
                    </div>
                    <h3 class="fw-bolder mb-1"><?= htmlspecialchars($profileUser['name']) ?></h3>
                    <p class="text-muted mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-calendar-check me-1 mb-1" viewBox="0 0 16 16"><path d="M10.854 7.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 9.793l2.646-2.647a.5.5 0 0 1 .708 0z"/><path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/></svg>
                        Narys nuo <?= date('Y-m-d', strtotime($profileUser['created_at'])) ?>
                    </p>
                    
                    <div class="bg-light rounded-4 p-3 mb-2">
                        <div class="fs-4 text-warning rating-stars mb-1">
                            <?= str_repeat('★', round($avgRating)) ?><?= str_repeat('☆', 5 - round($avgRating)) ?>
                        </div>
                        <h5 class="fw-bold mb-0 text-dark"><?= $avgRating ?> <span class="text-muted fs-6 fw-normal">/ 5 (iš <?= $totalReviews ?> vertinimų)</span></h5>
                    </div>
                </div>
            </div>

            <?php if ($currentUserId && $currentUserId !== $profileUser['id']): ?>
                <div class="card border-0 shadow-sm rounded-4 sticky-top" style="top: 2rem; z-index: 1020;">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-4 d-flex align-items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-star-fill text-warning me-2" viewBox="0 0 16 16"><path d="M3.612 15.443c-.386.198-.824-.149-.746-.592l.83-4.73L.173 6.765c-.329-.314-.158-.888.283-.95l4.898-.696L7.538.792c.197-.39.73-.39.927 0l2.184 4.327 4.898.696c.441.062.612.636.282.95l-3.522 3.356.83 4.73c.078.443-.36.79-.746.592L8 13.187l-4.389 2.256z"/></svg>
                            <?= $existingReviewForm ? 'Redaguoti atsiliepimą' : 'Palikti atsiliepimą' ?>
                        </h5>
                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            
                            <div class="mb-3">
                                <label class="form-label text-muted small fw-bold text-uppercase">Įvertinimas</label>
                                <select name="rating" class="form-select bg-light border-0 shadow-none" required>
                                    <option value="" disabled <?= !$existingReviewForm ? 'selected' : '' ?>>Pasirinkite...</option>
                                    <option value="5" <?= ($existingReviewForm && $existingReviewForm['rating'] == 5) ? 'selected' : '' ?>>5 ★ - Puikiai</option>
                                    <option value="4" <?= ($existingReviewForm && $existingReviewForm['rating'] == 4) ? 'selected' : '' ?>>4 ★ - Gerai</option>
                                    <option value="3" <?= ($existingReviewForm && $existingReviewForm['rating'] == 3) ? 'selected' : '' ?>>3 ★ - Vidutiniškai</option>
                                    <option value="2" <?= ($existingReviewForm && $existingReviewForm['rating'] == 2) ? 'selected' : '' ?>>2 ★ - Prastai</option>
                                    <option value="1" <?= ($existingReviewForm && $existingReviewForm['rating'] == 1) ? 'selected' : '' ?>>1 ★ - Labai prastai</option>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label class="form-label text-muted small fw-bold text-uppercase">Komentaras</label>
                                <textarea name="review_text" class="form-control bg-light border-0 shadow-none" rows="4" required placeholder="Aprašykite savo patirtį su šiuo nariu..."><?= $existingReviewForm ? htmlspecialchars($existingReviewForm['review_text']) : '' ?></textarea>
                            </div>
                            <button type="submit" name="submit_review" class="btn btn-primary w-100 rounded-pill fw-bold py-2 shadow-sm">
                                <?= $existingReviewForm ? 'Atnaujinti atsiliepimą' : 'Pateikti atsiliepimą' ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php elseif (!$currentUserId): ?>
                <div class="card border-0 shadow-sm rounded-4 text-center p-4">
                    <div class="card-body">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-info-circle text-primary mb-3 opacity-75" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/></svg>
                        <h5 class="fw-bold mb-3">Norite palikti atsiliepimą?</h5>
                        <a href="login.php" class="btn btn-outline-primary rounded-pill px-4 fw-bold">Prisijunkite</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card border-0 shadow-sm rounded-4 text-center p-4">
                    <div class="card-body">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-person-check text-success mb-3 opacity-75" viewBox="0 0 16 16"><path d="M12.5 16a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm1.679-4.493-1.335 2.226a.75.75 0 0 1-1.174.144l-.774-.773a.5.5 0 0 1 .708-.708l.547.548 1.17-1.951a.5.5 0 1 1 .858.514ZM11 5a3 3 0 1 1-6 0 3 3 0 0 1 6 0ZM8 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/><path d="M8.256 14a4.474 4.474 0 0 1-.229-1.004H3c.001-.246.154-.986.832-1.664C4.484 10.68 5.711 10 8 10c.26 0 .507.009.74.025.226-.341.496-.65.804-.918C9.077 9.038 8.564 9 8 9c-5 0-6 3-6 4s1 1 1 1h5.256Z"/></svg>
                        <h5 class="mb-0 fw-bold text-muted">Tai jūsų profilis</h5>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-8">
            
            <ul class="nav nav-pills mb-4 gap-2 border-bottom pb-3" id="profileTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="reviews-tab" data-bs-toggle="tab" data-bs-target="#reviews" type="button" role="tab" aria-controls="reviews" aria-selected="true">
                        Atsiliepimai <span class="badge bg-white text-primary ms-1 shadow-sm rounded-pill"><?= count($reviews) ?></span>
                    </button>
                </li>
                <?php if (count($listings) > 0): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="listings-tab" data-bs-toggle="tab" data-bs-target="#listings" type="button" role="tab" aria-controls="listings" aria-selected="false">
                        Skelbimai turgelyje <span class="badge bg-secondary ms-1 shadow-sm rounded-pill"><?= count($listings) ?></span>
                    </button>
                </li>
                <?php endif; ?>
            </ul>

            <div class="tab-content" id="profileTabsContent">
                
                <div class="tab-pane fade show active" id="reviews" role="tabpanel" aria-labelledby="reviews-tab">
                    <?php if (count($reviews) > 0): ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($reviews as $review): ?>
                                <div class="card border-0 shadow-sm rounded-4 hover-shadow">
                                    <div class="card-body p-4">
                                        <div class="d-flex justify-content-between align-items-start align-items-sm-center flex-column flex-sm-row mb-3 gap-2">
                                            <div class="d-flex align-items-center">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold me-3 profile-avatar-sm">
                                                    <?= mb_strtoupper(mb_substr($review['reviewer_name'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <h6 class="mb-0 fw-bold">
                                                        <a href="user_profile.php?id=<?= $review['reviewer_id'] ?>" class="text-dark text-decoration-none text-primary-hover">
                                                            <?= htmlspecialchars($review['reviewer_name']) ?>
                                                        </a>
                                                    </h6>
                                                    <small class="text-muted"><?= date('Y-m-d H:i', strtotime($review['created_at'])) ?></small>
                                                </div>
                                            </div>
                                            <div class="text-warning fs-6 bg-light px-3 py-1 rounded-pill fw-bold">
                                                <?= str_repeat('★', $review['rating']) ?><?= str_repeat('☆', 5 - $review['rating']) ?>
                                            </div>
                                        </div>
                                        <p class="mb-0 text-secondary" style="line-height: 1.6;">
                                            <?= nl2br(htmlspecialchars($review['review_text'])) ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="card border-0 shadow-sm rounded-4 text-center p-5">
                            <div class="card-body py-5">
                                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="currentColor" class="bi bi-chat-square-text text-muted mb-3 opacity-25" viewBox="0 0 16 16"><path d="M14 1a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1h-2.5a2 2 0 0 0-1.6.8L8 14.333 6.1 11.8a2 2 0 0 0-1.6-.8H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2.5a1 1 0 0 1 .8.4l1.9 2.533a1 1 0 0 0 1.6 0l1.9-2.533a1 1 0 0 1 .8-.4H14a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/><path d="M3 3.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5zM3 6a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9A.5.5 0 0 1 3 6zm0 2.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5z"/></svg>
                                <h5 class="text-muted fw-bold mb-0">Šis narys dar neturi atsiliepimų.</h5>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (count($listings) > 0): ?>
                <div class="tab-pane fade" id="listings" role="tabpanel" aria-labelledby="listings-tab">
                    <div class="row row-cols-1 row-cols-md-2 g-4">
                        <?php foreach ($listings as $listing): ?>
                            <div class="col">
                                <div class="card h-100 border-0 shadow-sm rounded-4 overflow-hidden hover-shadow">
                                    <div class="position-relative">
                                        <?php if (!empty($listing['image'])): ?>
                                            <img src="uploads/<?= htmlspecialchars($listing['image']) ?>" class="card-img-top object-fit-cover" alt="Skelbimo nuotrauka" style="height: 200px;">
                                        <?php else: ?>
                                            <div class="bg-light text-muted d-flex align-items-center justify-content-center" style="height: 200px;">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-image opacity-25" viewBox="0 0 16 16"><path d="M10.5 8.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/><path d="M14 14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H2a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12zM2 3h12a1 1 0 0 1 1 1v8l-2.5-2.5a1.5 1.5 0 0 0-2.122 0l-1.038 1.038-3.04-3.04a1.5 1.5 0 0 0-2.122 0L1 11V4a1 1 0 0 1 1-1z"/></svg>
                                            </div>
                                        <?php endif; ?>
                                        <div class="position-absolute top-0 end-0 m-3">
                                            <span class="badge bg-primary rounded-pill fs-6 shadow-sm px-3 py-2"><?= number_format($listing['price'], 2) ?> &euro;</span>
                                        </div>
                                    </div>
                                    <div class="card-body p-4 d-flex flex-column">
                                        <h5 class="card-title fw-bold text-truncate mb-4" title="<?= htmlspecialchars($listing['title']) ?>">
                                            <?= htmlspecialchars($listing['title']) ?>
                                        </h5>
                                        <div class="mt-auto">
                                            <a href="community_listing.php?id=<?= $listing['id'] ?>" class="btn btn-outline-primary rounded-pill w-100 fw-bold">Peržiūrėti skelbimą</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
