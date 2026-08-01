<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    redirect('index.php?page=login');
    exit;
}

$current_user_id = $_SESSION['user_id'];
$post_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($post_id <= 0) {
    redirect('index.php?page=feed');
    exit;
}

try {
    $stmt = db()->prepare("
        SELECT post.*, users.firstname, users.lastname, users.picture 
        FROM post 
        JOIN users ON post.user_id = users.id 
        WHERE post.id = ? AND post.deleted IS NULL AND users.deleted IS NULL LIMIT 1
    ");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();

    if (!$post) {
        redirect('index.php?page=feed');
        exit;
    }

    $pv_check = db()->prepare("SELECT id FROM post_views WHERE post_id = ? AND user_id = ?");
    $pv_check->execute([$post_id, $current_user_id]);

    if (!$pv_check->fetch()) {
        db()->prepare("INSERT INTO post_views (post_id, user_id) VALUES (?, ?)")->execute([$post_id, $current_user_id]);
        db()->prepare("UPDATE post SET views = views + 1 WHERE id = ?")->execute([$post_id]);
        $post['views']++;
    }

    $c_stmt = db()->prepare("
        SELECT comment.*, users.firstname, users.lastname 
        FROM comment 
        JOIN users ON comment.user_id = users.id 
        WHERE comment.post_id = ? AND users.deleted IS NULL
        ORDER BY comment.created_at ASC
    ");
    $c_stmt->execute([$post_id]);
    $comments = $c_stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    redirect('index.php?page=feed');
    exit;
}

$parsed = parse_post_content($post);
$display_text = $parsed['text'];
$display_image = $parsed['image'];
?>

<?php require __DIR__ . '/../public/header.php'; ?>

<div class="single-post-container">
    
    <a href="index.php?page=feed" class="back-link">Back to Feed</a>

    <article class="post-card" style="padding: 20px;">
        <div class="post-header" style="padding: 0; margin-bottom: 15px;">
            <?php if (!empty($post['picture'])): ?>
                <img src="<?= htmlspecialchars($post['picture']) ?>" class="author-img" alt="Avatar">
            <?php else: ?>
                <div class="author-img"><?= strtoupper(mb_substr($post['firstname'], 0, 1)) ?></div>
            <?php endif; ?>
            <div>
                <a href="index.php?page=profile&id=<?= (int)$post['user_id'] ?>" class="author-name" style="text-decoration: none;">
                    <?= htmlspecialchars($post['firstname'] . ' ' . $post['lastname']) ?>
                </a>
                <div class="post-meta" style="font-size: 12px; color: #65676b; margin-top: 2px;">Posted on <?= date('F j, Y, g:i a', strtotime($post['created'])) ?></div>
            </div>
        </div>

        <div class="views-badge">
            <?= number_format($post['views']) ?> <?= $post['views'] == 1 ? 'view' : 'views' ?>
        </div>

        <div class="post-content" style="margin-bottom: 15px;">
            <?= nl2br(htmlspecialchars($display_text)) ?>
            <?php if (!empty($display_image)): ?>
                <img src="<?= htmlspecialchars($display_image) ?>" class="post-image" alt="Post Image" style="border-radius: 6px; margin-top: 10px; max-height: 500px;">
            <?php endif; ?>
        </div>

        <div class="comments-section" style="margin: 0;">
            <h3 style="margin-bottom: 10px;">Comments (<?= count($comments) ?>)</h3>
            <div class="comments-list">
                <?php if (empty($comments)): ?>
                    <p style="color: #65676b; font-style: italic;">No comments yet.</p>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment-item">
                            <div class="comment-bubble">
                                <span class="comment-user"><?= htmlspecialchars($comment['firstname'] . ' ' . $comment['lastname']) ?>:</span>
                                <?= htmlspecialchars($comment['text']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </article>
</div>

<?php require __DIR__ . '/../public/footer.php'; ?>
