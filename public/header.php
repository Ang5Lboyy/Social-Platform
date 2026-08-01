<?php
    $user = current_user();
    $flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Social Network</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php if ($user): ?>
<header class="main-header">
    <div class="header-container">
        <a href="index.php?page=feed" class="header-logo">ConnectApp</a>

        <nav class="header-nav">
            <a href="index.php?page=feed" class="nav-link">Home</a>
            <a href="index.php?page=friends" class="nav-link">Friends</a>
            <a href="index.php?page=messages" class="nav-link">Messages</a>
            <a href="index.php?page=profile" class="nav-link">My Profile</a>
            <a href="index.php?page=logout" class="nav-link logout-link">Logout</a>
        </nav>
    </div>
</header>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="flash-container">
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
    </div>
<?php endif; ?>
