<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

$allowed_languages = ['en', 'pt-BR', 'es'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $language = trim($_POST['language'] ?? '');
    if (!in_array($language, $allowed_languages)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid language']);
        exit;
    }
    if ($db) {
        pg_query_params($db,
            "UPDATE powerplant SET language = $1 WHERE id = (SELECT id FROM powerplant ORDER BY id LIMIT 1)",
            [$language]
        );
    }
    echo json_encode(['ok' => true]);
    exit;
}

// GET: return current language
$lang = 'en';
if ($db) {
    $result = pg_query($db, "SELECT language FROM powerplant ORDER BY id LIMIT 1");
    $row = $result ? pg_fetch_assoc($result) : false;
    if ($row && !empty($row['language'])) {
        $lang = $row['language'];
    }
}
echo json_encode(['language' => $lang]);
