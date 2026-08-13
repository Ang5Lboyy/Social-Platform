<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

if (!rate_limit('generate_image', 10, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Please try again later.']);
    exit;
}

$search_query = trim($_POST['query'] ?? '');

if (empty($search_query)) {
    echo json_encode(['error' => 'Empty query']);
    exit;
}

$search_query = mb_substr($search_query, 0, 200);

if (file_exists(__DIR__ . '/../config.local.php')) {
    include_once __DIR__ . '/../config.local.php';
}
$unsplash_key = defined('UNSPLASH_ACCESS_KEY') ? UNSPLASH_ACCESS_KEY : '';

if (empty($unsplash_key)) {
    echo json_encode(['error' => 'Image search is not configured']);
    exit;
}

$url = "https://api.unsplash.com/search/photos?query=" . urlencode($search_query) . "&per_page=5&orientation=landscape";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Client-ID ' . $unsplash_key
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200 && $response) {
    $result = json_decode($response, true);
    
    if (!empty($result['results'][0]['urls']['regular'])) {
        $image_url = $result['results'][0]['urls']['regular'];
        echo json_encode(['image_url' => $image_url]);
    } else {
        echo json_encode(['error' => 'No image found']);
    }
} else {
    echo json_encode(['error' => 'Image search service unavailable']);
}
exit;
