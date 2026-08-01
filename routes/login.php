<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit('login', 10, 300)) {
        flash_set('danger', 'Too many login attempts. Please try again later.');
        redirect('index.php?page=login');
    }

    if (!csrf_verify()) {
        flash_set('danger', 'Invalid security token.');
        redirect('index.php?page=login');
    }

    $usernameOrEmail = sanitize_input($_POST['email'] ?? '', 255);
    $password = $_POST['password'] ?? '';

    if (empty($usernameOrEmail) || empty($password)) {
        flash_set('danger', 'Please fill in all fields.');
        redirect('index.php?page=login');
    }

    $stmt = db()->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND deleted IS NULL LIMIT 1");
    $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($password, $u['password'])) {
        flash_set('danger', 'Wrong login or password.');
        redirect('index.php?page=login');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    flash_set('success', 'Welcome back!');
    redirect('index.php?page=feed');
}
?>

<?php require __DIR__ . '/../public/header.php'; ?>

<div class="auth-container">
    <h2>Login</h2>
    <form action="" method="POST">
        <?= csrf_field() ?>
        <label>Username or Email</label>
        <input type="text" name="email" placeholder="Username or Email" required maxlength="255">
        <label>Password</label>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Login</button>
    </form>
    <a href="index.php?page=register" class="auth-link">Don't have an account? Register</a>
</div>
