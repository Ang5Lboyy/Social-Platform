<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) { redirect('index.php?page=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('index.php?page=feed'); exit; }
if (!csrf_verify()) { flash_set('danger', 'Invalid security token.'); redirect('index.php?page=feed'); exit; }

$current_user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$target_id = (int)($_POST['target_id'] ?? 0);

if ($target_id > 0 && $target_id !== $current_user_id) {
    $status_row = get_friend_status($current_user_id, $target_id);

    if ($action === 'add' && !$status_row) {
        $stmt = db()->prepare("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'pending')");
        $stmt->execute([$current_user_id, $target_id]);
    } 
    elseif ($action === 'accept' && $status_row && $status_row['friend_id'] == $current_user_id) {
        $stmt = db()->prepare("UPDATE friends SET status = 'accepted' WHERE id = ?");
        $stmt->execute([$status_row['id']]);
    } 
    elseif (in_array($action, ['cancel', 'reject', 'unfriend']) && $status_row) {
        if ($action === 'unfriend' && $status_row['status'] === 'accepted') {
            $stmt = db()->prepare("DELETE FROM friends WHERE id = ?");
            $stmt->execute([$status_row['id']]);
        } elseif ($action === 'reject' && $status_row['friend_id'] == $current_user_id) {
            $stmt = db()->prepare("DELETE FROM friends WHERE id = ?");
            $stmt->execute([$status_row['id']]);
        } elseif ($action === 'cancel' && $status_row['user_id'] == $current_user_id) {
            $stmt = db()->prepare("DELETE FROM friends WHERE id = ?");
            $stmt->execute([$status_row['id']]);
        }
    }
}

redirect('index.php?page=feed');
exit;
