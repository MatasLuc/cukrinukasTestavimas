<?php
function ensureSessionStarted(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function getCsrfToken(): string {
    ensureSessionStarted();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function csrfField(): string {
    $token = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function validateCsrfToken(?string $token = null): void {
    ensureSessionStarted();
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $submitted = $token ?? ($_POST['csrf_token'] ?? '');

    if (!is_string($submitted) || $sessionToken === '' || !hash_equals($sessionToken, (string) $submitted)) {
        http_response_code(400);
        echo 'Invalid CSRF token.';
        exit;
    }
}

function enforcePostRequestCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validateCsrfToken();
    } else {
        getCsrfToken();
    }
}

function sanitizeHtml(string $html): string {
    static $allowedTags = [
        'a', 'b', 'strong', 'i', 'em', 'u', 'p', 'br', 'ul', 'ol', 'li', 'span',
        'blockquote', 'code', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img'
    ];

    static $allowedAttributes = [
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        '*' => ['class']
    ];

    if ($html === '') {
        return '';
    }

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    // UTF-8 hack
    $document->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $sanitizeNode = function (DOMNode $node) use (&$sanitizeNode, $allowedTags, $allowedAttributes): void {
        for ($child = $node->firstChild; $child; $child = $child->nextSibling) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($child->nodeName);

                if (!in_array($tag, $allowedTags, true)) {
                    $next = $child->nextSibling;
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    $child = $next;
                    if ($child === null) {
                        break;
                    }
                    continue;
                }

                $allowedForTag = array_merge($allowedAttributes['*'], $allowedAttributes[$tag] ?? []);

                if ($child->hasAttributes()) {
                    foreach (iterator_to_array($child->attributes) as $attribute) {
                        $attrName = strtolower($attribute->name);

                        if (str_starts_with($attrName, 'on')) {
                            $child->removeAttributeNode($attribute);
                            continue;
                        }

                        if (!in_array($attrName, $allowedForTag, true)) {
                            $child->removeAttributeNode($attribute);
                            continue;
                        }

                        if (($attrName === 'href' || $attrName === 'src')) {
                            $value = trim($attribute->value);
                            $scheme = parse_url($value, PHP_URL_SCHEME);
                            if ($scheme && !in_array(strtolower($scheme), ['http', 'https', 'mailto'], true)) {
                                $child->removeAttributeNode($attribute);
                                continue;
                            }
                        }
                    }
                }

                $sanitizeNode($child);
            }
        }
    };

    $sanitizeNode($document);
    return $document->saveHTML() ?: '';
}

// --- REMEMBER ME FUNKCIJOS ---

function setRememberMe(PDO $pdo, int $userId): void {
    // 1. Sugeneruojame saugų atsitiktinį kodą
    $token = bin2hex(random_bytes(32));
    // 2. Išsaugome kodo hash'ą duomenų bazėje
    $hash = password_hash($token, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare('UPDATE users SET remember_token = ? WHERE id = ?');
    $stmt->execute([$hash, $userId]);

    // 3. Išsaugome slapuką naršyklėje: "user_id:token" (30 dienų)
    $cookieValue = $userId . ':' . $token;
    $expiry = time() + (86400 * 30); 
    // setcookie(name, value, expires, path, domain, secure, httponly)
    // Pastaba: 'secure' (6-as parametras) turi būti true, jei naudojate HTTPS. Localhost gali būti false.
    // Čia nustatome true, bet jei neveikia localhost be HTTPS, pakeiskite į false.
    setcookie('remember_me', $cookieValue, $expiry, '/', '', false, true); 
}

function clearRememberMe(PDO $pdo): void {
    if (isset($_COOKIE['remember_me'])) {
        // Ištriname iš DB
        if (isset($_SESSION['user_id'])) {
            $stmt = $pdo->prepare('UPDATE users SET remember_token = NULL WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
        }
        // Ištriname slapuką: SVARBU naudoti tuos pačius parametrus kaip kuriant
        setcookie('remember_me', '', time() - 3600, '/', '', false, true);
        unset($_COOKIE['remember_me']);
    }
}

function tryAutoLogin(PDO $pdo): void {
    // Jei vartotojas jau prisijungęs, nieko nedarome
    if (isset($_SESSION['user_id'])) {
        return;
    }

    // Jei nėra slapuko, nieko nedarome
    if (!isset($_COOKIE['remember_me'])) {
        return;
    }

    $parts = explode(':', $_COOKIE['remember_me']);
    if (count($parts) !== 2) {
        return;
    }

    [$userId, $token] = $parts;

    try {
        $stmt = $pdo->prepare('SELECT id, name, is_admin, remember_token FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user && !empty($user['remember_token'])) {
            if (password_verify($token, $user['remember_token'])) {
                ensureSessionStarted();
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['is_admin'] = (int) $user['is_admin'];
            }
        }
    } catch (Exception $e) {
        // Ignoruojame klaidas automatinio prisijungimo metu
    }
}
