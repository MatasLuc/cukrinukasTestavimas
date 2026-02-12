<?php
function setPrimaryImage(PDO $pdo, int $productId, int $imageId): void {
    $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?')->execute([$imageId, $productId]);
    $path = $pdo->prepare('SELECT path FROM product_images WHERE id = ? AND product_id = ?');
    $path->execute([$imageId, $productId]);
    $file = $path->fetchColumn();
    if ($file) {
        $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute([$file, $productId]);
    }
}

function deleteProductImage(PDO $pdo, int $productId, int $imageId): void {
    $stmt = $pdo->prepare('SELECT path, is_primary FROM product_images WHERE id = ? AND product_id = ?');
    $stmt->execute([$imageId, $productId]);
    $image = $stmt->fetch();
    if (!$image) {
        return;
    }
    $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
    $filePath = __DIR__ . '/../' . ltrim($image['path'], '/'); // Pataisytas kelias
    if (is_file($filePath)) {
        @unlink($filePath);
    }
    if ((int)$image['is_primary'] === 1) {
        $fallback = $pdo->prepare('SELECT id, path FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id DESC LIMIT 1');
        $fallback->execute([$productId]);
        $newMain = $fallback->fetch();
        if ($newMain) {
            $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?')->execute([$newMain['id']]);
            $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute([$newMain['path'], $productId]);
        } else {
            $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute(['https://placehold.co/600x400?text=Preke', $productId]);
        }
    }
}

function storeUploads(PDO $pdo, int $productId, array $files): void {
    if (empty($files['name'][0])) {
        return;
    }
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_primary = 1');
    $countStmt->execute([$productId]);
    $hasPrimary = (int)$countStmt->fetchColumn();

    $allowedMimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $file = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i] ?? 0,
        ];

        $relativePath = saveUploadedFile($file, $allowedMimeMap, 'img_');
        if (!$relativePath) {
            continue;
        }

        $isPrimary = ($hasPrimary === 0 && $i === 0) ? 1 : 0;
        $stmt = $pdo->prepare('INSERT INTO product_images (product_id, path, is_primary) VALUES (?, ?, ?)');
        $stmt->execute([$productId, $relativePath, $isPrimary]);
        if ($isPrimary) {
            $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute([$relativePath, $productId]);
            $hasPrimary = 1;
        }
    }
}

function readXlsxRows(string $filePath): array {
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return [];
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();

    if ($sheetXml === false) {
        return [];
    }

    $sharedStrings = [];
    if ($sharedXml !== false) {
        $shared = simplexml_load_string($sharedXml);
        if ($shared && isset($shared->si)) {
            foreach ($shared->si as $si) {
                $sharedStrings[] = (string)$si->t;
            }
        }
    }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) {
        return [];
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            $type = (string)$cell['t'];
            $value = (string)$cell->v;
            if ($type === 's') {
                $value = $sharedStrings[(int)$value] ?? $value;
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $value = (string)$cell->is->t;
            }
            $cells[] = trim((string)$value);
        }
        if ($cells) {
            $rows[] = $cells;
        }
    }

    return $rows;
}

function parseLockerFile(string $provider, string $filePath): array {
    $rows = readXlsxRows($filePath);
    if (!$rows) {
        return [];
    }

    $parsed = [];
    foreach ($rows as $index => $row) {
        $maybeTitle = strtolower((string)($row[1] ?? ''));
        if ($index === 0 && (str_contains($maybeTitle, 'pavadinimas') || str_contains($maybeTitle, 'name'))) {
            continue;
        }

        if ($provider === 'omniva') {
            $title = trim($row[1] ?? '');
            $city = trim($row[5] ?? '');
            $street = trim($row[6] ?? '');
            $house = trim($row[7] ?? '');
            $note = trim($row[10] ?? '');
            if ($title === '') {
                continue;
            }
            $addressParts = [];
            if ($city !== '') {
                $addressParts[] = $city;
            }
            $streetLine = trim($street . ($house !== '' ? ' ' . $house : ''));
            if ($streetLine !== '') {
                $addressParts[] = $streetLine;
            }
            $address = implode(', ', $addressParts);
            $parsed[] = [
                'title' => $title,
                'address' => $address ?: ($row[0] ?? $title),
                'note' => $note ?: null,
            ];
        } elseif ($provider === 'lpexpress') {
            $title = trim($row[2] ?? '');
            $city = trim($row[0] ?? '');
            $addressLine = trim($row[3] ?? '');
            $postcode = trim($row[4] ?? '');
            $note = trim($row[7] ?? '');
            if ($title === '') {
                continue;
            }
            $addressParts = array_filter([$city, $addressLine, $postcode]);
            $address = implode(', ', $addressParts);
            $parsed[] = [
                'title' => $title,
                'address' => $address ?: $addressLine,
                'note' => $note ?: null,
            ];
        }
    }
    return $parsed;
}
