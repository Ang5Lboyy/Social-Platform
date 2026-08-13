<?php
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!rate_limit('register', 5, 300)) {
        flash_set('danger', 'Too many registration attempts. Please try again later.');
        redirect('index.php?page=register');
    }

    if (!csrf_verify()) {
        flash_set('danger', 'Invalid security token.');
        redirect('index.php?page=register');
    }

    $firstname = sanitize_input($_POST['firstname'] ?? '', 100);
    $lastname  = sanitize_input($_POST['lastname'] ?? '', 100);
    $username  = sanitize_input($_POST['username'] ?? '', 50);
    $email     = sanitize_input($_POST['email'] ?? '', 255);
    $phone     = sanitize_input($_POST['phone'] ?? '', 20);
    $birthday  = $_POST['birthday'] ?? null;
    $gender    = trim($_POST['gender'] ?? '');
    $password  = $_POST['password'] ?? '';

    if (empty($firstname) || empty($lastname) || empty($username) || empty($email) || empty($password)) {
        flash_set('warning', 'Please fill all required fields.');
        redirect('index.php?page=register');
    }

    if (strlen($password) < 6) {
        flash_set('warning', 'Password must be at least 6 characters.');
        redirect('index.php?page=register');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('warning', 'Please enter a valid email address.');
        redirect('index.php?page=register');
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    try {
        $check = db()->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND deleted IS NULL LIMIT 1");
        $check->execute([$username, $email]);
        if ($check->fetch()) {
            flash_set('warning', 'Username or Email already exists.');
            redirect('index.php?page=register');
        }

        $stmt = db()->prepare("
            INSERT INTO users (firstname, lastname, username, email, password, phone, birthday, gender) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $firstname, $lastname, $username, $email, $hashed_password, 
            $phone, $birthday ?: null, $gender !== '' ? (int)$gender : null
        ]);

        flash_set('success', 'Registration successful! Please login.');
        redirect('index.php?page=login');
    } catch (PDOException $e) {
        error_log("Registration error: " . $e->getMessage());
        flash_set('danger', 'Registration failed. Please try again.');
        redirect('index.php?page=register');
    }
}
?>

<?php require __DIR__ . '/../public/header.php'; ?>

<div class="auth-container">
    <h2>Register</h2>
    <form action="" method="POST">
        <?= csrf_field() ?>
        <label>First Name</label>
        <input type="text" name="firstname" required maxlength="100">

        <label>Last Name</label>
        <input type="text" name="lastname" required maxlength="100">

        <label>Username</label>
        <input type="text" name="username" required maxlength="50">

        <label>Email</label>
        <input type="email" name="email" required maxlength="255">

        <label>Password</label>
        <input type="password" name="password" required minlength="6">

        <label>Phone Number</label>
        <input type="text" name="phone" maxlength="20">

        <label>Birthday</label>
        <input type="date" name="birthday">

        <label>Gender</label>
        <select name="gender" required>
            <option value="">Select Gender</option>
            <option value="1">Male</option>
            <option value="0">Female</option>
        </select>

        <button type="submit">Register</button>
    </form>
    <a href="index.php?page=login" class="auth-link">Already have an account? Login</a>
</div>

<?php require __DIR__ . '/../public/footer.php'; ?>
