<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_login();
$current_user_id = (int)current_user()['id'];
$post_id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;

$post_stmt = db()->prepare("
    SELECT post.*, users.firstname, users.lastname 
    FROM post 
    JOIN users ON post.user_id = users.id 
    WHERE post.id = ? AND post.status = 1 AND post.deleted IS NULL AND users.deleted IS NULL
");
$post_stmt->execute([$post_id]);
$post = $post_stmt->fetch();

if (!$post) {
    flash_set('danger', 'Post not found.');
    redirect('index.php?page=feed');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['share_with_user'])) {
    if (!csrf_verify()) {
        flash_set('danger', 'Invalid security token.');
        redirect('index.php?page=feed');
    }

    $to_user_id = (int)$_POST['to_user_id'];

    if ($to_user_id > 0 && $to_user_id !== $current_user_id) {
        $friend_check = get_friend_status($current_user_id, $to_user_id);
        if (!$friend_check || $friend_check['status'] !== 'accepted') {
            flash_set('danger', 'You can only share posts with friends.');
            redirect('index.php?page=feed');
        }

        $clean_content = $post['content'];
        
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $post_link = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/index.php?page=post&id=' . $post['id'];

        $message_text = "Shared a post from " . $post['firstname'] . ' ' . $post['lastname'] . ":\n";
        $message_text .= "\"" . mb_substr($clean_content, 0, 60) . "...\"\n\n";
        $message_text .= "Click here to view post: " . $post_link;

        try {
            $stmt = db()->prepare("
                INSERT INTO messages (user_id, to_id, text, created_at) 
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$current_user_id, $to_user_id, $message_text]);

            flash_set('success', 'Post shared successfully!');
            redirect('index.php?page=feed');
        } catch (PDOException $e) {
            error_log("Error sharing post: " . $e->getMessage());
            flash_set('danger', 'Error sharing post.');
            redirect('index.php?page=feed');
        }
    }
}

$users_stmt = db()->prepare("
    SELECT DISTINCT u.id, u.firstname, u.lastname, u.username 
    FROM users u
    INNER JOIN friends f ON (
        (f.user_id = ? AND f.friend_id = u.id) OR 
        (f.friend_id = ? AND f.user_id = u.id)
    )
    WHERE f.status = 'accepted' AND u.id != ? AND u.deleted IS NULL
");
$users_stmt->execute([$current_user_id, $current_user_id, $current_user_id]);
$all_users = $users_stmt->fetchAll();

?>

<?php require __DIR__ . '/../public/header.php'; ?>

<div class="share-container">
    <div class="share-title">Share this Post</div>
    
    <div class="preview-box">
        <strong>Post Author:</strong> <?= htmlspecialchars($post['firstname'] . ' ' . $post['lastname']) ?><br>
        <strong>Content:</strong> <?= htmlspecialchars(mb_substr($post['content'], 0, 60)) ?>...
    </div>

    <?php if (empty($all_users)): ?>
        <p style="color: #65676b; text-align: center; padding: 20px;">Add friends to share posts with them.</p>
    <?php else: ?>
    <div class="share-users-list">
        <?php foreach ($all_users as $u): ?>
            <div class="share-user-item">
                <div style="display: flex; align-items: center;">
                    <div class="share-avatar"><?= strtoupper(mb_substr($u['firstname'], 0, 1)) ?></div>
                    <div>
                        <div style="font-weight: 600; font-size: 14px;"><?= htmlspecialchars($u['firstname'] . ' ' . $u['lastname']) ?></div>
                        <div style="font-size: 12px; color: #65676b;">@<?= htmlspecialchars($u['username']) ?></div>
                    </div>
                </div>
                <form action="" method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="to_user_id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" name="share_with_user" class="send-btn">Send</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <a href="index.php?page=feed" class="cancel-link">Cancel</a>
</div>

<?php require __DIR__ . '/../public/footer.php'; ?>
