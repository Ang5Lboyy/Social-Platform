<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_login();
$current_user_id = (int)current_user()['id'];
$profile_id = isset($_GET['id']) ? (int)$_GET['id'] : $current_user_id;
$page_type = $_GET['page'] === 'followers' ? 'followers' : 'following';

try {
    $stmt = db()->prepare("SELECT firstname, lastname, username, picture FROM users WHERE id = ? AND deleted IS NULL");
    $stmt->execute([$profile_id]);
    $profile_user = $stmt->fetch();
    if (!$profile_user) { redirect('index.php?page=feed'); exit; }
} catch (PDOException $e) {
    redirect('index.php?page=feed');
    exit;
}

if ($page_type === 'followers') {
    $list_stmt = db()->prepare("
        SELECT u.id, u.firstname, u.lastname, u.username, u.picture 
        FROM follows f
        JOIN users u ON f.follower_id = u.id
        WHERE f.following_id = ? AND u.deleted IS NULL
        ORDER BY f.created_at DESC
    ");
    $list_stmt->execute([$profile_id]);
    $title = htmlspecialchars($profile_user['firstname']) . "'s Followers";
} else {
    $list_stmt = db()->prepare("
        SELECT u.id, u.firstname, u.lastname, u.username, u.picture 
        FROM follows f
        JOIN users u ON f.following_id = u.id
        WHERE f.follower_id = ? AND u.deleted IS NULL
        ORDER BY f.created_at DESC
    ");
    $list_stmt->execute([$profile_id]);
    $title = htmlspecialchars($profile_user['firstname']) . "'s Following";
}

$users = $list_stmt->fetchAll();
?>

<?php require __DIR__ . '/../public/header.php'; ?>

<div class="friends-container">
    <a href="index.php?page=profile&id=<?= (int)$profile_id ?>" class="back-link" style="margin-bottom: 15px; display: inline-block;">← Back to Profile</a>
    
    <div class="section-title"><?= $title ?> (<?= count($users) ?>)</div>
    
    <?php if (empty($users)): ?>
        <p style="color: #65676b;"><?= $page_type === 'followers' ? 'No followers yet.' : 'Not following anyone yet.' ?></p>
    <?php else: ?>
        <?php foreach ($users as $u): ?>
            <div class="user-row">
                <div class="user-info">
                    <?php if(!empty($u['picture'])): ?>
                        <img src="<?= htmlspecialchars($u['picture']) ?>" class="user-img" alt="Avatar">
                    <?php else: ?>
                        <div class="user-img"><?= strtoupper(substr($u['firstname'],0,1)) ?></div>
                    <?php endif; ?>
                    <div>
                        <a href="index.php?page=profile&id=<?= (int)$u['id'] ?>" class="user-name"><?= htmlspecialchars($u['firstname'] . ' ' . $u['lastname']) ?></a>
                        <div style="font-size:12px; color:#65676b;">@<?= htmlspecialchars($u['username']) ?></div>
                    </div>
                </div>
                <div class="actions-btn-group">
                    <a href="index.php?page=profile&id=<?= (int)$u['id'] ?>" class="f-btn add-btn" style="background:#1877f2; color:white;">View Profile</a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../public/footer.php'; ?>
