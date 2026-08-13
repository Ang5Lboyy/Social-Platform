<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_login();
$current_user_id = (int)current_user()['id'];

$requests_stmt = db()->prepare("
    SELECT f.id as request_id, u.id, u.firstname, u.lastname, u.username, u.picture 
    FROM friends f
    JOIN users u ON f.user_id = u.id
    WHERE f.friend_id = ? AND f.status = 'pending' AND u.deleted IS NULL
");
$requests_stmt->execute([$current_user_id]);
$friend_requests = $requests_stmt->fetchAll();

$friends_stmt = db()->prepare("
    SELECT u.id, u.firstname, u.lastname, u.username, u.picture 
    FROM friends f
    JOIN users u ON (f.user_id = u.id AND f.friend_id = ?) OR (f.friend_id = u.id AND f.user_id = ?)
    WHERE f.status = 'accepted' AND u.id != ? AND u.deleted IS NULL
");
$friends_stmt->execute([$current_user_id, $current_user_id, $current_user_id]);
$my_friends = $friends_stmt->fetchAll();
?>

<?php require __DIR__ . '/../public/header.php'; ?>

<div class="friends-container">
    
    <div class="section-title">Friend Requests (<?= count($friend_requests) ?>)</div>
    <?php if (empty($friend_requests)): ?>
        <p style="color: #65676b;">No pending friend requests.</p>
    <?php else: ?>
        <?php foreach ($friend_requests as $req): ?>
            <div class="user-row">
                <div class="user-info">
                    <?php if(!empty($req['picture'])): ?><img src="<?= htmlspecialchars($req['picture']) ?>" class="user-img" alt="Avatar"><?php else: ?><div class="user-img"><?= strtoupper(substr($req['firstname'],0,1)) ?></div><?php endif; ?>
                    <div>
                        <a href="index.php?page=profile&id=<?= (int)$req['id'] ?>" class="user-name"><?= htmlspecialchars($req['firstname'] . ' ' . $req['lastname']) ?></a>
                        <div style="font-size:12px; color:#65676b;">@<?= htmlspecialchars($req['username']) ?></div>
                    </div>
                </div>
                <div class="actions-btn-group">
                    <form action="index.php?page=friend_action" method="POST" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="accept">
                        <input type="hidden" name="target_id" value="<?= (int)$req['id'] ?>">
                        <button type="submit" class="f-btn add-btn">Confirm</button>
                    </form>
                    <form action="index.php?page=friend_action" method="POST" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="target_id" value="<?= (int)$req['id'] ?>">
                        <button type="submit" class="f-btn cancel-btn">Delete</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <br><br>

    <div class="section-title">My Friends (<?= count($my_friends) ?>)</div>
    <?php if (empty($my_friends)): ?>
        <p style="color: #65676b;">You haven't added any friends yet.</p>
    <?php else: ?>
        <?php foreach ($my_friends as $fr): ?>
            <div class="user-row">
                <div class="user-info">
                    <?php if(!empty($fr['picture'])): ?><img src="<?= htmlspecialchars($fr['picture']) ?>" class="user-img" alt="Avatar"><?php else: ?><div class="user-img"><?= strtoupper(substr($fr['firstname'],0,1)) ?></div><?php endif; ?>
                    <div>
                        <a href="index.php?page=profile&id=<?= (int)$fr['id'] ?>" class="user-name"><?= htmlspecialchars($fr['firstname'] . ' ' . $fr['lastname']) ?></a>
                        <div style="font-size:12px; color:#65676b;">@<?= htmlspecialchars($fr['username']) ?></div>
                    </div>
                </div>
                <div class="actions-btn-group">
                    <a href="index.php?page=messages&user_id=<?= (int)$fr['id'] ?>" class="f-btn add-btn" style="background:#1877f2; color:white;">Message</a>
                    <form action="index.php?page=friend_action" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unfriend">
                        <input type="hidden" name="target_id" value="<?= (int)$fr['id'] ?>">
                        <button type="submit" class="f-btn cancel-btn">Unfriend</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../public/footer.php'; ?>
