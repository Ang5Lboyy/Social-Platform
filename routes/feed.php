<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    redirect('index.php?page=login');
    exit;
}

$current_user_id = $_SESSION['user_id'];

$posts_per_page = 10;
$current_page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($current_page - 1) * $posts_per_page;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_post'])) {
    if (!csrf_verify()) {
        flash_set('danger', 'Invalid security token.');
        redirect('index.php?page=feed');
    }

    if (!rate_limit('create_post', 10, 60)) {
        flash_set('danger', 'You are posting too quickly. Please slow down.');
        redirect('index.php?page=feed');
    }

    $user_input = sanitize_input($_POST['content'] ?? '', 5000);
    $image_url = trim($_POST['image_url'] ?? '');
    $use_ai = isset($_POST['use_ai']) && $_POST['use_ai'] == '1';

    if (!empty($image_url)) {
        $image_url = filter_var($image_url, FILTER_VALIDATE_URL) ? $image_url : '';
    }

    if (!empty($user_input)) {
        if ($use_ai) {
            $preview = $_SESSION['ai_post_preview'] ?? null;
            $preview_is_valid = is_array($preview)
                && isset($preview['created_at'])
                && $preview['created_at'] >= time() - 900;

            if (!$preview_is_valid) {
                flash_set('danger', 'Please generate an AI preview before publishing this post.');
                redirect('index.php?page=feed');
            }

            // The editor can change the generated preview; do not call Gemini a second time.
            // The preview response is HTML-escaped before it reaches the editor.
            // Decode once here so saving it does not double-escape characters such as &.
            $final_content = sanitize_ai_output(html_entity_decode($user_input, ENT_QUOTES, 'UTF-8'));
            unset($_SESSION['ai_post_preview']);
        } 
        else {
            $final_content = $user_input;
        }
        
        $title = mb_substr(strip_tags($final_content), 0, 50) . '...';
        $is_ai_value = $use_ai ? 1 : 0;
        
        try {
            $stmt = db()->prepare("
                INSERT INTO post (user_id, title, content, image_url, views, status, is_ai) 
                VALUES (?, ?, ?, ?, 0, 1, ?)
            ");
            $stmt->execute([$current_user_id, $title, $final_content, $image_url ?: null, $is_ai_value]);
            
            if ($use_ai) {
                flash_set('success', 'AI-generated post published successfully!');
            } else {
                flash_set('success', 'Post published successfully!');
            }
            redirect('index.php?page=feed');
            exit;
        } catch (PDOException $e) {
            error_log("Error saving post: " . $e->getMessage());
            flash_set('danger', 'Error saving post. Please try again.');
            redirect('index.php?page=feed');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_like'])) {
    if (!csrf_verify()) {
        flash_set('danger', 'Invalid security token.');
        redirect('index.php?page=feed');
    }

    $post_id = (int)$_POST['post_id'];

    try {
        $check = db()->prepare("SELECT id FROM likes WHERE post_id = ? AND user_id = ?");
        $check->execute([$post_id, $current_user_id]);
        $like = $check->fetch();

        if ($like) {
            $del = db()->prepare("DELETE FROM likes WHERE post_id = ? AND user_id = ?");
            $del->execute([$post_id, $current_user_id]);
        } else {
            $ins = db()->prepare("INSERT INTO likes (post_id, user_id, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
            $ins->execute([$post_id, $current_user_id]);
        }

        redirect('index.php?page=feed');
        exit;
    } catch (PDOException $e) {
        error_log("Error processing like: " . $e->getMessage());
        flash_set('danger', 'Error processing like.');
        redirect('index.php?page=feed');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    if (!csrf_verify()) {
        flash_set('danger', 'Invalid security token.');
        redirect('index.php?page=feed');
    }

    if (!rate_limit('comment', 20, 60)) {
        flash_set('danger', 'You are commenting too quickly. Please slow down.');
        redirect('index.php?page=feed');
    }

    $post_id = (int)$_POST['post_id'];
    $comment_text = sanitize_input($_POST['comment_text'] ?? '', 2000);

    if (!empty($comment_text)) {
        try {
            $stmt = db()->prepare("INSERT INTO comment (user_id, post_id, text) VALUES (?, ?, ?)");
            $stmt->execute([$current_user_id, $post_id, $comment_text]);
            redirect('index.php?page=feed');
            exit;
        } catch (PDOException $e) {
            error_log("Error adding comment: " . $e->getMessage());
            flash_set('danger', 'Error adding comment.');
            redirect('index.php?page=feed');
        }
    }
}

$total_posts_stmt = db()->query("SELECT COUNT(*) FROM post WHERE status = 1 AND deleted IS NULL");
$total_posts = (int)$total_posts_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_posts / $posts_per_page));

try {
    $stmt = db()->prepare("
        SELECT post.*, users.firstname, users.lastname, users.picture
        FROM post 
        JOIN users ON post.user_id = users.id 
        WHERE post.status = 1 AND post.deleted IS NULL AND users.deleted IS NULL
        ORDER BY post.created DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$posts_per_page, $offset]);
    $posts = $stmt->fetchAll();

    $post_ids = array_column($posts, 'id');

    $comments_by_post = [];
    $likes_by_post = [];

    if (!empty($post_ids)) {
        $placeholders = implode(',', array_fill(0, count($post_ids), '?'));

        $pv_stmt = db()->prepare("SELECT post_id FROM post_views WHERE post_id IN ($placeholders) AND user_id = ?");
        $pv_params = array_merge($post_ids, [$current_user_id]);
        $pv_stmt->execute($pv_params);
        $already_viewed = array_column($pv_stmt->fetchAll(), 'post_id');

        $new_views = array_diff($post_ids, $already_viewed);

        if (!empty($new_views)) {
            $new_views_arr = array_values($new_views);
            $ins_placeholders = implode(',', array_fill(0, count($new_views_arr), '?'));
            $ins_params = [];
            foreach ($new_views_arr as $pid) {
                $ins_params[] = $pid;
                $ins_params[] = $current_user_id;
            }
            db()->prepare("INSERT IGNORE INTO post_views (post_id, user_id) VALUES " . implode(',', array_fill(0, count($new_views_arr), '(?, ?)')))->execute($ins_params);
            db()->prepare("UPDATE post SET views = views + 1 WHERE id IN ($ins_placeholders)")->execute($new_views_arr);
        }

        foreach ($posts as &$post) {
            if (in_array($post['id'], $new_views)) {
                $post['views']++;
            }
        }
        unset($post);

        $c_stmt = db()->prepare("
            SELECT comment.*, users.firstname, users.lastname, comment.post_id
            FROM comment 
            JOIN users ON comment.user_id = users.id 
            WHERE comment.post_id IN ($placeholders) AND users.deleted IS NULL
            ORDER BY comment.created_at ASC
        ");
        $c_stmt->execute($post_ids);
        foreach ($c_stmt->fetchAll() as $c) {
            $comments_by_post[$c['post_id']][] = $c;
        }

        $l_stmt = db()->prepare("
            SELECT post_id, id FROM likes 
            WHERE post_id IN ($placeholders) AND user_id = ?
        ");
        $l_params = array_merge($post_ids, [$current_user_id]);
        $l_stmt->execute($l_params);
        foreach ($l_stmt->fetchAll() as $l) {
            $likes_by_post[$l['post_id']] = true;
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching posts: " . $e->getMessage());
    $posts = [];
    $comments_by_post = [];
    $likes_by_post = [];
}

require __DIR__ . '/../public/header.php';
?>

<div class="feed-container">
    <div class="search-box">
        <form action="index.php" method="GET">
            <input type="hidden" name="page" value="search">
            <input type="text" name="q" placeholder="Search friends, posts or #hashtags..." required autocomplete="off">
            <button type="submit">Search</button>
        </form>
    </div>

    <div class="create-post-card">
    <div class="post-type-toggle">
        <button type="button" id="normalPostBtn" class="post-type-btn" style="background: #1877f2; color: white;">Normal Post</button>
        <button type="button" id="aiPostBtn" class="post-type-btn" style="background: #e4e6eb; color: #65676b;">AI Post</button>
    </div>

    <form id="normalPostForm" action="" method="POST" style="display: block;">
        <?= csrf_field() ?>
        <textarea name="content" rows="3" placeholder="What's on your mind?" required maxlength="5000"></textarea>
        <input type="text" name="image_url" placeholder="Image URL (Optional)" maxlength="500">
        <button type="button" id="previewNormalBtn" class="create-post-btn">Preview & Publish</button>
    </form>

    <form id="aiPostForm" action="" method="POST" style="display: none;">
        <?= csrf_field() ?>
        <textarea name="content" rows="3" placeholder="Write a few words... AI will create a complete post for you!" required maxlength="5000"></textarea>
        
        <div style="display: flex; gap: 8px; margin-top: 8px;">
            <input type="text" id="aiImageUrl" name="image_url" placeholder="Image URL (AI will find one automatically)" maxlength="500" style="flex: 1;">
            <button type="button" id="findImageBtn" style="background: #e4e6eb; border: none; border-radius: 6px; padding: 8px 15px; cursor: pointer;">Find Image</button>
        </div>
        
        <input type="hidden" name="use_ai" value="1">
        <button type="button" id="previewAiBtn" class="create-post-btn" style="background: #8e44ad; margin-top: 10px;">Generate Preview & Publish</button>
        <small style="color: #65676b; display: block; margin-top: 8px;">
            AI will automatically find a matching image for your post!
        </small>
    </form>
    
    <small id="formHint" style="color: #65676b; display: block; margin-top: 8px;">
        Write your own post normally
    </small>
</div>

<div id="previewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Preview & Edit Post</h3>
            <span class="close-modal">&times;</span>
        </div>
        <div id="previewText" class="preview-text"></div>
        
        <textarea id="editText" class="edit-textarea" placeholder="Edit your post text here..." maxlength="5000"></textarea>
        
        <div style="margin-top: 10px;">
            <label style="display: block; font-size: 13px; font-weight: bold; color: #606770; margin-bottom: 5px;">Image URL (Optional)</label>
            <input type="text" id="editImageUrl" class="edit-textarea" placeholder="https://example.com/image.jpg" maxlength="500" style="min-height: 40px;">
        </div>
        
        <div class="modal-actions">
            <button type="button" id="cancelPublishBtn" class="cancel-publish-btn">Cancel</button>
            <button type="button" id="confirmPublishBtn" class="confirm-publish-btn">Publish Post</button>
        </div>
    </div>
</div>

<script>
    let pendingForm = null;
    let pendingImageUrl = '';
    let pendingUseAi = false;

    document.getElementById('previewNormalBtn').addEventListener('click', function() {
        const content = document.querySelector('#normalPostForm textarea').value;
        const imageUrl = document.querySelector('#normalPostForm input[name="image_url"]').value;
        
        if (!content.trim()) {
            alert('Please write something first!');
            return;
        }
        
        pendingForm = 'normal';
        pendingImageUrl = imageUrl;
        pendingUseAi = false;
        
        let previewHtml = content.replace(/\n/g, '<br>');
        if (imageUrl) {
            previewHtml += '<br><br><img src="' + imageUrl.replace(/"/g, '&quot;') + '" style="max-width: 100%; border-radius: 8px; margin-top: 10px;" alt="Preview Image">';
        }
        document.getElementById('previewText').innerHTML = previewHtml;
        document.getElementById('editText').value = content;
        document.getElementById('editImageUrl').value = imageUrl;
        document.getElementById('previewModal').style.display = 'block';
    });

document.getElementById('findImageBtn').addEventListener('click', function() {
    const searchQuery = document.querySelector('#aiPostForm textarea').value;
    
    if (!searchQuery.trim()) {
        alert('Please write a few words first!');
        return;
    }
    
    document.getElementById('aiImageUrl').value = 'Searching for image...';
    
    const csrfToken = document.querySelector('#normalPostForm input[name="csrf_token"]').value;
    
    fetch('index.php?page=generate_image', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'query=' + encodeURIComponent(searchQuery) + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(response => response.json())
    .then(data => {
        if (data.image_url) {
            document.getElementById('aiImageUrl').value = data.image_url;
            alert('Image found! You can see it in the preview.');
        } else if (data.error) {
            document.getElementById('aiImageUrl').value = '';
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        document.getElementById('aiImageUrl').value = '';
        alert('Error finding image');
    });
});

document.getElementById('previewAiBtn').addEventListener('click', function() {
    const userInput = document.querySelector('#aiPostForm textarea').value;
    
    if (!userInput.trim()) {
        alert('Please write a few words first!');
        return;
    }
    
    document.getElementById('previewText').innerHTML = '<span style="color: #8e44ad;">AI is generating your post and searching for an image... Please wait...</span>';
    document.getElementById('editText').value = 'Generating...';
    document.getElementById('editImageUrl').value = '';
    document.getElementById('previewModal').style.display = 'block';
    
    const csrfToken = document.querySelector('#aiPostForm input[name="csrf_token"]').value;
    
    fetch('index.php?page=generate_image', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'query=' + encodeURIComponent(userInput) + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(response => response.json())
    .then(imageData => {
        const foundImageUrl = imageData.image_url || '';
        
        fetch('index.php?page=generate_post_ai', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'content=' + encodeURIComponent(userInput) + '&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(async response => {
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.error || 'AI generation failed. Please try again.');
            }
            return data;
        })
        .then(data => {
            if (data.content) {
                let previewHtml = data.content.replace(/\n/g, '<br>');
                if (foundImageUrl) {
                    previewHtml += '<br><br><img src="' + foundImageUrl.replace(/"/g, '&quot;') + '" style="max-width: 100%; border-radius: 8px; margin-top: 10px;" alt="AI Found Image">';
                }
                document.getElementById('previewText').innerHTML = previewHtml;
                document.getElementById('editText').value = data.content;
                document.getElementById('editImageUrl').value = foundImageUrl;
                pendingForm = 'ai';
                pendingImageUrl = foundImageUrl;
                pendingUseAi = true;
            } else {
                document.getElementById('previewText').textContent = data.error || 'AI generation failed. Please try again.';
                document.getElementById('editText').value = userInput;
            }
        })
        .catch(error => {
            document.getElementById('previewText').textContent = error.message || 'Connection error. Please try again.';
            document.getElementById('editText').value = userInput;
        });
    })
    .catch(error => {
        document.getElementById('previewText').innerHTML = '<span style="color: red;">Image search failed. Please try again.</span>';
        document.getElementById('editText').value = userInput;
    });
});

    document.querySelector('.close-modal').addEventListener('click', function() {
        document.getElementById('previewModal').style.display = 'none';
    });
    document.getElementById('cancelPublishBtn').addEventListener('click', function() {
        document.getElementById('previewModal').style.display = 'none';
    });
    window.addEventListener('click', function(event) {
        if (event.target === document.getElementById('previewModal')) {
            document.getElementById('previewModal').style.display = 'none';
        }
    });

    document.getElementById('confirmPublishBtn').addEventListener('click', function() {
        const finalContent = document.getElementById('editText').value;
        const finalImageUrl = document.getElementById('editImageUrl').value;
        
        if (!finalContent.trim()) {
            alert('Post content cannot be empty!');
            return;
        }
        
        const activeForm = pendingUseAi ? document.getElementById('aiPostForm') : document.getElementById('normalPostForm');
        const csrfInput = activeForm.querySelector('input[name="csrf_token"]');
        const csrfToken = csrfInput ? csrfInput.value : '';
        
        const formData = new FormData();
        formData.append('content', finalContent);
        formData.append('image_url', finalImageUrl);
        if (pendingUseAi) {
            formData.append('use_ai', '1');
        }
        formData.append('create_post', '1');
        formData.append('csrf_token', csrfToken);
        
        fetch('index.php?page=feed', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            window.location.href = 'index.php?page=feed';
        })
        .catch(error => {
            alert('Error publishing post. Please try again.');
        });
    });

    document.getElementById('normalPostBtn').addEventListener('click', function() {
        this.style.background = '#1877f2';
        this.style.color = 'white';
        document.getElementById('aiPostBtn').style.background = '#e4e6eb';
        document.getElementById('aiPostBtn').style.color = '#65676b';
        
        document.getElementById('normalPostForm').style.display = 'block';
        document.getElementById('aiPostForm').style.display = 'none';
        document.getElementById('formHint').textContent = 'Write your own post normally';
    });

    document.getElementById('aiPostBtn').addEventListener('click', function() {
        this.style.background = '#8e44ad';
        this.style.color = 'white';
        document.getElementById('normalPostBtn').style.background = '#e4e6eb';
        document.getElementById('normalPostBtn').style.color = '#65676b';
        
        document.getElementById('normalPostForm').style.display = 'none';
        document.getElementById('aiPostForm').style.display = 'block';
        document.getElementById('formHint').textContent = 'Just type a few words, AI will create the complete post!';
    });

document.addEventListener('click', function(event) {
    const button = event.target.closest('.ai-btn');
    if (!button) return;

    const postId = button.getAttribute('data-id');
    const tagsContainer = document.getElementById('ai-tags-' + postId);
    if (!tagsContainer) return;

    if (tagsContainer.textContent.trim() !== '' && !tagsContainer.textContent.includes('Generating')) {
        return;
    }

    tagsContainer.textContent = 'Generating AI tags...';
    tagsContainer.style.color = '';
    button.disabled = true;

    const csrfToken = document.querySelector('input[name="csrf_token"]');
    const token = csrfToken ? csrfToken.value : '';

    const formData = new FormData();
    formData.append('post_id', postId);
    formData.append('csrf_token', token);

    fetch('index.php?page=generate_tags', {
        method: 'POST',
        body: formData
    })
    .then(async response => {
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'Failed to generate tags');
        }
        return data;
    })
    .then(data => {
        if (!data.tags) {
            throw new Error(data.error || 'Failed to generate tags');
        }
        tagsContainer.textContent = data.tags;
    })
    .catch(error => {
        tagsContainer.textContent = error.message || 'Connection error';
        tagsContainer.style.color = 'red';
    })
    .finally(() => {
        button.disabled = false;
    });
});
</script>

    <?php if (empty($posts)): ?>
        <p style="text-align: center; color: #65676b; font-size: 16px; margin-top: 30px;">No posts yet. Be the first to publish!</p>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
            <?php
            $parsed = parse_post_content($post);
            $display_text = $parsed['text'];
            $display_image = $parsed['image'];
            $post_comments = $comments_by_post[$post['id']] ?? [];
            $is_liked = isset($likes_by_post[$post['id']]);
            ?>

            <article class="post-card" data-id="<?= (int)$post['id'] ?>">
                
                <div class="post-header">
                    <div class="post-header-left">
                        <a href="index.php?page=profile&id=<?= (int)$post['user_id'] ?>" style="text-decoration: none; display: flex; align-items: center; gap: 10px; color: inherit;">
                            <?php if (!empty($post['picture'])): ?>
                                <img src="<?= htmlspecialchars($post['picture']) ?>" class="author-img" alt="Avatar">
                            <?php else: ?>
                                <div class="author-img"><?= strtoupper(mb_substr($post['firstname'], 0, 1)) ?></div>
                            <?php endif; ?>
                        </a>
                        <div>
                            <a href="index.php?page=profile&id=<?= (int)$post['user_id'] ?>" style="text-decoration: none; color: inherit;">
                                <span class="author-name"><?= htmlspecialchars($post['firstname'] . ' ' . $post['lastname']) ?></span>
                            </a>
                            <div class="post-time">
    <?= date('F j, Y, g:i a', strtotime($post['created'])) ?> &bull; <?= number_format($post['views']) ?> views
    <?php if (isset($post['is_ai']) && $post['is_ai'] == 1): ?>
        <span class="ai-badge">AI</span>
    <?php endif; ?>
</div>
                        </div>
                    </div>
                </div>

                <div class="post-content">
    <?= nl2br(htmlspecialchars($display_text)) ?>
</div>

<?php if (!empty($display_image)): ?>
<div class="post-image-wrapper">
    <img src="<?= htmlspecialchars($display_image) ?>" class="post-image" alt="Post Image">
</div>
<?php endif; ?>

                <div class="ai-tags-container" id="ai-tags-<?= (int)$post['id'] ?>"></div>

                <div class="post-actions">
    <form action="" method="POST" class="action-form" style="flex: 1;">
        <?= csrf_field() ?>
        <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
        <button type="submit" name="toggle_like" class="action-btn <?= $is_liked ? 'liked' : '' ?>">
            <?= $is_liked ? 'Unlike' : 'Like' ?>
        </button>
    </form>
    
    <?php if ((int)$post['user_id'] === (int)$current_user_id): ?>
        <button class="action-btn ai-btn" style="flex: 1;" data-id="<?= (int)$post['id'] ?>">
            AI Tags
        </button>
    <?php endif; ?>
    
    <button class="action-btn" style="flex: 1;" 
            onclick="document.getElementById('comment-input-<?= (int)$post['id'] ?>').focus();">
        Comment
    </button>
    
    <a href="index.php?page=share&post_id=<?= (int)$post['id'] ?>" 
       class="action-btn" style="text-decoration: none; flex: 1; display: flex; align-items: center; justify-content: center;">
        Share
    </a>
</div>

                <div class="comments-section">
                    <div class="comments-list">
                        <?php foreach ($post_comments as $comment): ?>
                            <div class="comment-item">
                                <div class="comment-bubble">
                                    <span class="comment-user"><?= htmlspecialchars($comment['firstname'] . ' ' . $comment['lastname']) ?>:</span>
                                    <?= htmlspecialchars($comment['text']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <form action="" method="POST" class="comment-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                        <input type="text" id="comment-input-<?= (int)$post['id'] ?>" name="comment_text" placeholder="Write a comment..." required autocomplete="off" maxlength="2000">
                        <button type="submit" name="add_comment">Send</button>
                    </form>
                </div>

            </article>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($current_page > 1): ?>
            <a href="index.php?page=feed&p=<?= $current_page - 1 ?>">&laquo; Previous</a>
        <?php endif; ?>
        
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i == $current_page): ?>
                <span class="current"><?= $i ?></span>
            <?php else: ?>
                <a href="index.php?page=feed&p=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($current_page < $total_pages): ?>
            <a href="index.php?page=feed&p=<?= $current_page + 1 ?>">Next &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../public/footer.php'; ?>
