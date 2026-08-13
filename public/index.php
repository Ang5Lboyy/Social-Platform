<?php
$is_secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $is_secure,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/functions.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data:; font-src 'self';");

$page = $_GET['page'] ?? null;
if ($page === null) {
    $page = !empty($_SESSION['user_id']) ? 'feed' : 'login';
}
$page = preg_replace('/[^a-z_]/', '', $page);

$routes = [
    'register'         => __DIR__ . '/../routes/register.php',
    'login'            => __DIR__ . '/../routes/login.php',
    'feed'             => __DIR__ . '/../routes/feed.php',
    'profile'          => __DIR__ . '/../routes/profile.php',
    'logout'           => __DIR__ . '/../routes/logout.php',
    'messages'         => __DIR__ . '/../routes/messages.php',
    'share'            => __DIR__ . '/../routes/share.php',
    'post'             => __DIR__ . '/../routes/single_post.php',
    'friends'          => __DIR__ . '/../routes/friends.php',
    'friend_action'    => __DIR__ . '/../routes/friend_action.php',
    'follow_action'    => __DIR__ . '/../routes/follow_action.php',
    'followers'        => __DIR__ . '/../routes/follow_list.php',
    'following'        => __DIR__ . '/../routes/follow_list.php',
    'generate_tags'    => __DIR__ . '/../routes/generate_tags.php',
    'search'           => __DIR__ . '/../routes/search.php',
    'generate_post_ai' => __DIR__ . '/../routes/generate_post_ai.php',
    'generate_image'   => __DIR__ . '/../routes/generate_image.php',
];

if (!isset($routes[$page])) {
    http_response_code(404);
    echo "Not found";
    exit;
}

require $routes[$page];
