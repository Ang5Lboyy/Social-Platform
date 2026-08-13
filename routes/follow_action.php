<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('index.php?page=feed'); exit; }
if (!csrf_verify()) { flash_set('danger', 'Invalid security token.'); redirect('index.php?page=feed'); exit; }

$current_user_id = (int)current_user()['id'];
$action = $_POST['action'] ?? '';
$target_id = (int)($_POST['target_id'] ?? 0);

if ($target_id > 0 && $target_id !== $current_user_id) {
    $check = db()->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
    $check->execute([$current_user_id, $target_id]);
    $already_following = $check->fetch();

    if ($action === 'follow' && !$already_following) {
        $stmt = db()->prepare("INSERT INTO follows (follower_id, following_id) VALUES (?, ?)");
        $stmt->execute([$current_user_id, $target_id]);
    } elseif ($action === 'unfollow' && $already_following) {
        $stmt = db()->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?");
        $stmt->execute([$current_user_id, $target_id]);
    }
}

$redirect_url = isset($_POST['redirect']) ? $_POST['redirect'] : 'index.php?page=profile&id=' . $target_id;
redirect($redirect_url);
exit;
