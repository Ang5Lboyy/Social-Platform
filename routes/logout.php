<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ջնջում ենք սեսիայի բոլոր տվյալները
$_SESSION = [];

// Եթե օգտագործվում են Session Cookie-ներ, ջնջում ենք նաև դրանք
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Փակում ենք սեսիան
session_destroy();

// Օգտատիրոջը հետ ենք ուղարկում լոգինի էջ
header("Location: index.php?page=login");
exit;