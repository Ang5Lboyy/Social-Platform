<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    redirect('index.php?page=login');
    exit;
}

$current_user_id = $_SESSION['user_id'];
$search_query = trim($_GET['q'] ?? '');

$found_users = [];
$found_posts = [];

if (!empty($search_query)) {
    try {
        $escaped_query = escape_like_pattern($search_query);
        $like_query = '%' . $escaped_query . '%';

        $u_stmt = db()->prepare("
            SELECT id, firstname, lastname, username, picture 
            FROM users 
            WHERE deleted IS NULL AND (firstname LIKE ? OR lastname LIKE ? OR username LIKE ?)
            LIMIT 10
        ");
        $u_stmt->execute([$like_query, $like_query, $like_query]);
        $found_users = $u_stmt->fetchAll();

        $words = preg_split('/[\s,#]+/', $search_query, -1, PREG_SPLIT_NO_EMPTY);
        
        if (!empty($words)) {
            $sql_conditions = [];
            $params = [];
            
            foreach ($words as $word) {
                $word = trim($word);
                if (strlen($word) >= 2) {
                    $escaped_word = escape_like_pattern($word);
                    $sql_conditions[] = "post.content LIKE ?";
                    $params[] = '%' . $escaped_word . '%';
                }
            }
            
            if (!empty($sql_conditions)) {
                $where_clause = implode(' OR ', $sql_conditions);
                
                $p_stmt = db()->prepare("
                    SELECT post.*, users.firstname, users.lastname, users.picture 
                    FROM post 
                    JOIN users ON post.user_id = users.id 
                    WHERE post.deleted IS NULL AND users.deleted IS NULL AND ({$where_clause})
                    ORDER BY post.created DESC 
                    LIMIT 20
                ");
                $p_stmt->execute($params);
                $found_posts = $p_stmt->fetchAll();
            }
        }

    } catch (PDOException $e) {
        error_log("Search error: " . $e->getMessage());
    }
}

require __DIR__ . '/../public/header.php';
?>

<div class="search-container">
    
    <div class="search-box-page">
        <form action="index.php" method="GET">
            <input type="hidden" name="page" value="search">
            <input type="text" name="q" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search users, posts or #hashtags..." required autocomplete="off">
            <button type="submit">Search</button>
        </form>
    </div>

    <?php if (empty($search_query)): ?>
        <p class="no-results">Type something to start searching...</p>
    <?php else: ?>
        
        <div class="section-title">Users Found</div>
        <?php if (empty($found_users)): ?>
            <p class="no-results">No users found matching "<?= htmlspecialchars($search_query) ?>"</p>
        <?php else: ?>
            <?php foreach ($found_users as $u): ?>
                <div class="user-card-search">
                    <a href="index.php?page=profile&id=<?= (int)$u['id'] ?>" style="text-decoration: none;">
                        <?php if (!empty($u['picture'])): ?>
                            <img src="<?= htmlspecialchars($u['picture']) ?>" class="search-avatar" alt="Avatar">
                        <?php else: ?>
                            <div class="search-avatar"><?= strtoupper(mb_substr($u['firstname'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </a>
                    <div class="search-user-info">
                        <a href="index.php?page=profile&id=<?= (int)$u['id'] ?>">
                            <?= htmlspecialchars($u['firstname'] . ' ' . $u['lastname']) ?>
                        </a>
                        <div class="search-username">@<?= htmlspecialchars($u['username']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="section-title">Posts Found</div>
        <?php if (empty($found_posts)): ?>
            <p class="no-results">No posts or hashtags found matching "<?= htmlspecialchars($search_query) ?>"</p>
        <?php else: ?>
            <?php foreach ($found_posts as $post): ?>
                <?php
                $parsed = parse_post_content($post);
                $display_text = $parsed['text'];
                $display_image = $parsed['image'];
                ?>
                <article class="post-card" style="padding: 15px;">
                    <div class="post-header" style="padding: 0; margin-bottom: 12px;">
                        <a href="index.php?page=profile&id=<?= (int)$post['user_id'] ?>" style="text-decoration: none; color: inherit;">
                            <?php if (!empty($post['picture'])): ?>
                                <img src="<?= htmlspecialchars($post['picture']) ?>" class="author-img" alt="Avatar">
                            <?php else: ?>
                                <div class="author-img"><?= strtoupper(mb_substr($post['firstname'], 0, 1)) ?></div>
                            <?php endif; ?>
                        </a>
                        <div>
                            <a href="index.php?page=profile&id=<?= (int)$post['user_id'] ?>" class="author-name">
                                <?= htmlspecialchars($post['firstname'] . ' ' . $post['lastname']) ?>
                            </a>
                            <div class="post-time"><?= date('F j, Y, g:i a', strtotime($post['created'])) ?></div>
                        </div>
                    </div>
                    <div class="post-content">
                        <?= nl2br(htmlspecialchars($display_text)) ?>
                        <?php if (!empty($display_image)): ?>
                            <img src="<?= htmlspecialchars($display_image) ?>" class="post-image" alt="Post Image">
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/../public/footer.php'; ?>
