<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    redirect('index.php?page=login');
    exit;
}

$current_user_id = $_SESSION['user_id'];

$chat_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if (!csrf_verify()) {
        flash_set('danger', 'Invalid security token.');
        redirect('index.php?page=messages');
    }

    if (!rate_limit('message', 30, 60)) {
        flash_set('danger', 'You are sending messages too quickly.');
        redirect('index.php?page=messages&user_id=' . $chat_user_id);
    }

    $to_user_id = (int)$_POST['to_user_id'];
    $text = sanitize_input($_POST['message_text'] ?? '', 2000);

    if (!empty($text) && $to_user_id > 0) {
        $friend_check = get_friend_status($current_user_id, $to_user_id);
        if (!$friend_check || $friend_check['status'] !== 'accepted') {
            flash_set('danger', 'You can only message friends.');
            redirect('index.php?page=messages&user_id=' . $to_user_id);
        }

        try {
            $stmt = db()->prepare("
                INSERT INTO messages (user_id, to_id, text, created_at) 
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$current_user_id, $to_user_id, $text]);
            
            redirect("index.php?page=messages&user_id=" . $to_user_id);
        } catch (PDOException $e) {
            error_log("Error sending message: " . $e->getMessage());
            flash_set('danger', 'Error sending message.');
            redirect('index.php?page=messages&user_id=' . $to_user_id);
        }
    }
}

try {
    $users_stmt = db()->prepare("
        SELECT DISTINCT u.id, u.firstname, u.lastname, u.username 
        FROM users u
        INNER JOIN friends f ON (
            (f.user_id = ? AND f.friend_id = u.id) OR 
            (f.friend_id = ? AND f.user_id = u.id)
        )
        WHERE f.status = 'accepted' AND u.id != ? AND u.deleted IS NULL
        ORDER BY u.firstname ASC
    ");
    $users_stmt->execute([$current_user_id, $current_user_id, $current_user_id]);
    $all_users = $users_stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching users: " . $e->getMessage());
    $all_users = [];
}

$chat_user = null;
$chat_messages = [];

if ($chat_user_id > 0) {
    $cu_stmt = db()->prepare("SELECT id, firstname, lastname FROM users WHERE id = ? AND deleted IS NULL");
    $cu_stmt->execute([$chat_user_id]);
    $chat_user = $cu_stmt->fetch();

    if ($chat_user) {
        $is_friend = get_friend_status($current_user_id, $chat_user_id);
        if (!$is_friend || $is_friend['status'] !== 'accepted') {
            flash_set('danger', 'You can only view messages with friends.');
            redirect('index.php?page=messages');
        }

        try {
            $msg_stmt = db()->prepare("
                SELECT * FROM messages 
                WHERE (user_id = ? AND to_id = ?) OR (user_id = ? AND to_id = ?) 
                ORDER BY created_at ASC
            ");
            $msg_stmt->execute([$current_user_id, $chat_user_id, $chat_user_id, $current_user_id]);
            $chat_messages = $msg_stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error fetching messages: " . $e->getMessage());
        }
    }
}
?>

<?php require __DIR__ . '/../public/header.php'; ?>

<div class="chat-container">

    <div class="users-sidebar">
        <div class="sidebar-title">Chats</div>
        <div class="users-list">
            <?php foreach ($all_users as $u): ?>
                <a href="index.php?page=messages&user_id=<?= (int)$u['id'] ?>" class="user-item <?= $chat_user_id === (int)$u['id'] ? 'active' : '' ?>">
                    <div class="chat-avatar"><?= strtoupper(mb_substr($u['firstname'], 0, 1)) ?></div>
                    <div>
                        <div style="font-weight: 600;"><?= htmlspecialchars($u['firstname'] . ' ' . $u['lastname']) ?></div>
                        <div style="font-size: 12px; color: #65676b;">@<?= htmlspecialchars($u['username']) ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="chat-main">
        <?php if ($chat_user): ?>
            <div class="chat-header">
                <?= htmlspecialchars($chat_user['firstname'] . ' ' . $chat_user['lastname']) ?>
            </div>

            <div class="messages-box" id="msgBox">
                <?php if (empty($chat_messages)): ?>
                    <p style="text-align: center; color: #65676b; margin-top: 20px;">No messages yet. Say hello!</p>
                <?php else: ?>
                    <?php foreach ($chat_messages as $msg): 
                        $is_sent = ((int)$msg['user_id'] === $current_user_id);
                    ?>
                        <div class="msg-bubble <?= $is_sent ? 'sent' : 'received' ?>">
    <?php 
    $msg_text = $msg['text'];
    if (strpos($msg_text, 'index.php?page=post') !== false) {
        $parts = explode('index.php?page=post', $msg_text);
        $clean_text = htmlspecialchars($parts[0]);
        $link_params = htmlspecialchars($parts[1]);
        
        echo nl2br($clean_text);
        echo '<a href="index.php?page=post' . $link_params . '" class="chat-shared-link">View Shared Post</a>';
    } else {
        echo nl2br(htmlspecialchars($msg_text));
    }
    ?>
    <span class="msg-time"><?= date('H:i', strtotime($msg['created_at'])) ?></span>
</div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="chat-footer">
                <form action="" method="POST" class="chat-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="to_user_id" value="<?= (int)$chat_user['id'] ?>">
                    <input type="text" name="message_text" placeholder="Type a message..." required autocomplete="off" autofocus maxlength="2000">
                    <button type="submit" name="send_message">Send</button>
                </form>
            </div>
        <?php else: ?>
            <div class="no-chat-selected">
                <div>Select a friend from the left menu to start chatting!</div>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
    const msgBox = document.getElementById('msgBox');
    if (msgBox) {
        msgBox.scrollTop = msgBox.scrollHeight;
    }
</script>

<?php require __DIR__ . '/../public/footer.php'; ?>
