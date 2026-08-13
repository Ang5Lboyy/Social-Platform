<?php

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_login();
$current_user_id = (int)current_user()['id'];
$profile_id = isset($_GET['id']) ? (int)$_GET['id'] : $current_user_id;
$is_own_profile = ($profile_id === $current_user_id);

$error_message = '';
$success_message = '';

try {
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND deleted IS NULL");
    $stmt->execute([$profile_id]);
    $profile_user = $stmt->fetch();

    if (!$profile_user) {
        redirect('index.php?page=feed');
        exit;
    }
} catch (PDOException $e) {
    error_log("Profile error: " . $e->getMessage());
    redirect('index.php?page=feed');
    exit;
}

$friend_status_row = null;
$button_html = '';
$is_following = false;

if (!$is_own_profile && $profile_user) {
    $friend_status_row = get_friend_status($current_user_id, $profile_user['id']);

    $fv = db()->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
    $fv->execute([$current_user_id, $profile_user['id']]);
    $is_following = (bool)$fv->fetch();

    if (!$friend_status_row) {
        $button_html = '<form action="index.php?page=friend_action" method="POST" style="display:inline;">' . csrf_field() . '<input type="hidden" name="action" value="add"><input type="hidden" name="target_id" value="' . (int)$profile_user['id'] . '"><button type="submit" class="profile-action-btn add-friend-btn">Add Friend</button></form>';
    }
    elseif ($friend_status_row['status'] === 'pending' && $friend_status_row['user_id'] == $current_user_id) {
        $button_html = '<form action="index.php?page=friend_action" method="POST" style="display:inline;">' . csrf_field() . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="target_id" value="' . (int)$profile_user['id'] . '"><button type="submit" class="profile-action-btn cancel-btn">Cancel Request</button></form>';
    }
    elseif ($friend_status_row['status'] === 'pending' && $friend_status_row['friend_id'] == $current_user_id) {
        $button_html = '
            <form action="index.php?page=friend_action" method="POST" style="display:inline;">' . csrf_field() . '<input type="hidden" name="action" value="accept"><input type="hidden" name="target_id" value="' . (int)$profile_user['id'] . '"><button type="submit" class="profile-action-btn accept-btn">Accept Request</button></form>
            <form action="index.php?page=friend_action" method="POST" style="display:inline;">' . csrf_field() . '<input type="hidden" name="action" value="reject"><input type="hidden" name="target_id" value="' . (int)$profile_user['id'] . '"><button type="submit" class="profile-action-btn reject-btn">Reject</button></form>
        ';
    }
    elseif ($friend_status_row['status'] === 'accepted') {
        $button_html = '
            <a href="index.php?page=messages&user_id=' . (int)$profile_user['id'] . '" class="profile-action-btn message-btn">Message</a>
            <form action="index.php?page=friend_action" method="POST" style="display:inline;" onsubmit="return confirm(\'Are you sure you want to unfriend?\');">' . csrf_field() . '<input type="hidden" name="action" value="unfriend"><input type="hidden" name="target_id" value="' . (int)$profile_user['id'] . '"><button type="submit" class="profile-action-btn unfriend-btn">Unfriend</button></form>
        ';
    }

    if ($is_following) {
        $button_html .= '<form action="index.php?page=follow_action" method="POST" style="display:inline;">' . csrf_field() . '<input type="hidden" name="action" value="unfollow"><input type="hidden" name="target_id" value="' . (int)$profile_user['id'] . '"><button type="submit" class="profile-action-btn unfollow-btn">Unfollow</button></form>';
    } else {
        $button_html .= '<form action="index.php?page=follow_action" method="POST" style="display:inline;">' . csrf_field() . '<input type="hidden" name="action" value="follow"><input type="hidden" name="target_id" value="' . (int)$profile_user['id'] . '"><button type="submit" class="profile-action-btn follow-btn">Follow</button></form>';
    }
}

if ($is_own_profile && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_post'])) {
    if (!csrf_verify()) { $error_message = 'Invalid security token.'; }
    else {
        $delete_post_id = (int)$_POST['post_id'];
        try {
            $check_stmt = db()->prepare("SELECT user_id FROM post WHERE id = ?");
            $check_stmt->execute([$delete_post_id]);
            $post_owner = $check_stmt->fetchColumn();

            if ($post_owner == $current_user_id) {
                db()->prepare("UPDATE post SET deleted = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")->execute([$delete_post_id, $current_user_id]);
                $success_message = 'Post deleted successfully!';
            } else {
                $error_message = 'You do not have permission to delete this post.';
            }
        } catch (PDOException $e) {
            error_log("Delete post error: " . $e->getMessage());
            $error_message = 'Error deleting post.';
        }
    }
}

if ($is_own_profile && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!csrf_verify()) { $error_message = 'Invalid security token.'; }
    else {
        $firstname = sanitize_input($_POST['firstname'] ?? '', 100);
        $lastname = sanitize_input($_POST['lastname'] ?? '', 100);
        $phone = sanitize_input($_POST['phone'] ?? '', 20);
        $picture = validate_image_url(sanitize_input($_POST['picture'] ?? '', 500));
        $bio = sanitize_input($_POST['bio'] ?? '', 1000);

        if (!empty($firstname) && !empty($lastname)) {
            try {
                $stmt = db()->prepare("
                    UPDATE users 
                    SET firstname = ?, lastname = ?, phone = ?, picture = ?, bio = ?, updated = CURRENT_TIMESTAMP 
                    WHERE id = ? AND deleted IS NULL
                ");
                $stmt->execute([$firstname, $lastname, $phone, $picture, $bio, $current_user_id]);
                $success_message = 'Profile updated successfully!';
                
                $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND deleted IS NULL");
                $stmt->execute([$current_user_id]);
                $profile_user = $stmt->fetch();
            } catch (PDOException $e) {
                error_log("Profile update error: " . $e->getMessage());
                $error_message = 'Database error.';
            }
        } else {
            $error_message = 'Firstname and Lastname are required.';
        }
    }
}

if ($is_own_profile && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    if (!csrf_verify()) { $error_message = 'Invalid security token.'; }
    else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (!empty($current_password) && !empty($new_password) && !empty($confirm_password)) {
            if (!password_verify($current_password, $profile_user['password'])) {
                $error_message = 'Current password is incorrect.';
            } elseif ($new_password !== $confirm_password) {
                $error_message = 'New passwords do not match.';
            } elseif (strlen($new_password) < 6) {
                $error_message = 'New password must be at least 6 characters.';
            } else {
                try {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = db()->prepare("UPDATE users SET password = ?, updated = CURRENT_TIMESTAMP WHERE id = ? AND deleted IS NULL");
                    $stmt->execute([$hashed, $current_user_id]);
                    $success_message = 'Password updated successfully!';
                } catch (PDOException $e) {
                    error_log("Password update error: " . $e->getMessage());
                    $error_message = 'Database error.';
                }
            }
        } else {
            $error_message = 'All password fields are required.';
        }
    }
}

if ($is_own_profile && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    if (!csrf_verify()) { $error_message = 'Invalid security token.'; }
    else {
        $delete_password = $_POST['delete_password'] ?? '';

        if (empty($delete_password)) {
            $error_message = 'Please enter your password to delete your account.';
        } elseif (!password_verify($delete_password, $profile_user['password'])) {
            $error_message = 'Incorrect password. Account was not deleted.';
        } else {
            try {
                $stmt = db()->prepare("UPDATE users SET deleted = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$current_user_id]);

                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $params["path"], $params["domain"],
                        $params["secure"], $params["httponly"]
                    );
                }
                session_destroy();
                redirect('index.php?page=login');
            } catch (PDOException $e) {
                error_log("Account deletion error: " . $e->getMessage());
                $error_message = 'Error deleting account.';
            }
        }
    }
}

try {
    $stmt = db()->prepare("SELECT * FROM post WHERE user_id = ? AND status = 1 AND deleted IS NULL ORDER BY created DESC");
    $stmt->execute([$profile_id]);
    $user_posts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Fetch posts error: " . $e->getMessage());
    $user_posts = [];
}

$followers_count = db()->prepare("SELECT COUNT(*) FROM follows WHERE following_id = ?");
$followers_count->execute([$profile_id]);
$followers_count = (int)$followers_count->fetchColumn();

$following_count = db()->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ?");
$following_count->execute([$profile_id]);
$following_count = (int)$following_count->fetchColumn();
?>

<?php require __DIR__ . '/../public/header.php'; ?>

<div class="profile-container">
    <div class="profile-header-card">
        <?php if (!empty($profile_user['picture'])): ?>
            <img src="<?= htmlspecialchars($profile_user['picture']) ?>" class="profile-avatar" alt="Avatar">
        <?php else: ?>
            <div class="profile-avatar"><?= strtoupper(mb_substr($profile_user['firstname'], 0, 1)) ?></div>
        <?php endif; ?>
        
        <h1 class="profile-name"><?= htmlspecialchars($profile_user['firstname'] . ' ' . $profile_user['lastname']) ?></h1>
        <div class="profile-username">@<?= htmlspecialchars($profile_user['username']) ?></div>
        
        <?php if (!empty($profile_user['bio'])): ?>
            <p class="profile-bio-text"><?= nl2br(htmlspecialchars($profile_user['bio'])) ?></p>
        <?php endif; ?>
        
        <div class="profile-stats">
            <a href="index.php?page=followers&id=<?= (int)$profile_id ?>" class="profile-stat">
                <span class="stat-number"><?= $followers_count ?></span>
                <span class="stat-label">Followers</span>
            </a>
            <a href="index.php?page=following&id=<?= (int)$profile_id ?>" class="profile-stat">
                <span class="stat-number"><?= $following_count ?></span>
                <span class="stat-label">Following</span>
            </a>
        </div>

        <?php if (!$is_own_profile): ?>
            <div class="profile-actions"><?= $button_html ?></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <?php if ($is_own_profile): ?>
        <div class="profile-grid">
            <div class="settings-card">
                <div class="card-title">Edit Profile Information</div>
                <form action="" method="POST">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label>Firstname</label>
                        <input type="text" name="firstname" value="<?= htmlspecialchars($profile_user['firstname']) ?>" required maxlength="100">
                    </div>
                    <div class="form-group">
                        <label>Lastname</label>
                        <input type="text" name="lastname" value="<?= htmlspecialchars($profile_user['lastname']) ?>" required maxlength="100">
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($profile_user['phone'] ?? '') ?>" maxlength="20">
                    </div>
                    <div class="form-group">
                        <label>Profile Picture URL</label>
                        <input type="text" name="picture" value="<?= htmlspecialchars($profile_user['picture'] ?? '') ?>" maxlength="500">
                    </div>
                    <div class="form-group">
                        <label>Bio (About Yourself)</label>
                        <textarea name="bio" rows="4" maxlength="1000"><?= htmlspecialchars($profile_user['bio'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" name="update_profile" class="save-btn">Save Changes</button>
                </form>
            </div>

            <div class="settings-card">
                <div class="card-title">Update Password</div>
                <form action="" method="POST">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" required minlength="6">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required minlength="6">
                    </div>
                    <button type="submit" name="update_password" class="save-btn password-btn">Update Password</button>
                </form>
            </div>

            <div class="settings-card danger-card">
                <div class="card-title danger-title">Danger Zone</div>
                <p style="font-size: 13px; color: #65676b; margin-bottom: 15px;">Permanently deactivate your account.</p>
                <form action="" method="POST" onsubmit="return confirm('Are you sure you want to delete your account? This cannot be undone.');">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label>Enter your password to confirm</label>
                        <input type="password" name="delete_password" required>
                    </div>
                    <button type="submit" name="delete_account" class="delete-btn">Delete My Account</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="user-posts-section">
        <div class="section-title">
            <?= $is_own_profile ? 'My Posts' : htmlspecialchars($profile_user['firstname']) . "'s Posts" ?> (<?= count($user_posts) ?>)
        </div>
        
        <?php if (empty($user_posts)): ?>
            <p style="color: #65676b; background: white; padding: 15px; border-radius: 8px; text-align: center;">No posts yet.</p>
        <?php else: ?>
            <?php foreach ($user_posts as $post): 
                $parsed = parse_post_content($post);
            ?>
                <div class="post-card" style="padding: 15px;">
                    <div class="post-header">
                        <div>Posted on <?= date('F j, Y, g:i a', strtotime($post['created'])) ?></div>
                        <?php if ($is_own_profile): ?>
                            <form action="" method="POST" class="delete-post-form" onsubmit="return confirm('Are you sure?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                                <button type="submit" name="delete_post" class="delete-post-btn">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <p style="font-size: 15px; color: #050505; line-height: 1.4; margin: 0;"><?= nl2br(htmlspecialchars($parsed['text'])) ?></p>
                    <?php if (!empty($parsed['image'])): ?>
                        <img src="<?= htmlspecialchars($parsed['image']) ?>" alt="Post Image" class="post-image" style="max-height: 350px;">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../public/footer.php'; ?>
