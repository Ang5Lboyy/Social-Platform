<?php

function redirect($url) {
    $url = str_replace(["\r", "\n"], '', $url);
    if (preg_match('#^https?://#i', $url)) {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            $url = 'index.php?page=feed';
        }
    }
    header("Location: " . $url, true, 302);
    exit;
}

function current_user() {
    static $cached = null;
    static $called = false;
    if ($called) return $cached;
    $called = true;

    if (empty($_SESSION['user_id'])) {
        $cached = null;
        return null;
    }

    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND deleted IS NULL");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $cached = $stmt->fetch() ?: null;
    return $cached;
}

function require_login() {
    if (!current_user()) {
        redirect('index.php?page=login');
    }
}

function flash_set($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals(csrf_token(), $token);
}

function get_friend_status($user_id, $friend_id) {
    $stmt = db()->prepare("
        SELECT * FROM friends 
        WHERE (user_id = ? AND friend_id = ?) 
           OR (user_id = ? AND friend_id = ?) 
        LIMIT 1
    ");
    $stmt->execute([$user_id, $friend_id, $friend_id, $user_id]);
    return $stmt->fetch() ?: null;
}

function sanitize_input($input, $max_length = 1000) {
    $input = trim($input);
    $input = mb_substr($input, 0, $max_length);
    return $input;
}

function escape_like_pattern($input) {
    return addcslashes($input, '%_');
}

function parse_post_content($post) {
    $display_text = $post['content'] ?? '';
    $display_image = $post['image_url'] ?? null;
    return ['text' => $display_text, 'image' => $display_image];
}

function sanitize_ai_output($text) {
    $text = strip_tags($text);
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return $text;
}

function gemini_generate_content($prompt) {
    if (file_exists(__DIR__ . '/../config.local.php')) {
        include_once __DIR__ . '/../config.local.php';
    }

    $api_key = defined('GEMINI_API_KEY_OVERRIDE')
        ? trim(GEMINI_API_KEY_OVERRIDE)
        : (defined('GEMINI_API_KEY') ? trim(GEMINI_API_KEY) : '');
    $model = defined('GEMINI_MODEL') ? trim(GEMINI_MODEL) : 'gemini-3.6-flash';

    if ($api_key === '') {
        return ['content' => '', 'status' => 500, 'error' => 'Gemini API key is not configured.'];
    }

    if ($model === '') {
        return ['content' => '', 'status' => 500, 'error' => 'Gemini model is not configured.'];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model)
        . ':generateContent?key='
        . rawurlencode($api_key);
    $payload = json_encode([
        'contents' => [[
            'parts' => [['text' => $prompt]],
        ]],
    ]);

    if ($payload === false) {
        error_log('Gemini request could not be encoded: ' . json_last_error_msg());
        return ['content' => '', 'status' => 500, 'error' => 'Could not prepare the AI request.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('Gemini transport error: ' . $curl_error);
        return ['content' => '', 'status' => 502, 'error' => 'Could not reach Gemini. Please try again.'];
    }

    $result = json_decode($response, true);
    if (!is_array($result)) {
        error_log('Gemini returned invalid JSON (HTTP ' . $http_code . '): ' . json_last_error_msg());
        return ['content' => '', 'status' => 502, 'error' => 'Gemini returned an invalid response. Please try again.'];
    }

    if ($http_code < 200 || $http_code >= 300 || isset($result['error'])) {
        $api_message = $result['error']['message'] ?? 'Unknown API error';
        error_log('Gemini API error (HTTP ' . $http_code . '): ' . $api_message);

        if ($http_code === 429) {
            return ['content' => '', 'status' => 429, 'error' => 'Gemini request limit has been reached. Please try again in a minute.'];
        }
        if ($http_code === 404) {
            return ['content' => '', 'status' => 502, 'error' => 'The configured Gemini model is unavailable. Please contact the site administrator.'];
        }
        if ($http_code === 401 || $http_code === 403) {
            return ['content' => '', 'status' => 502, 'error' => 'Gemini authorization failed. Please contact the site administrator.'];
        }

        return ['content' => '', 'status' => 502, 'error' => 'Gemini could not generate content. Please try again.'];
    }

    $content = trim($result['candidates'][0]['content']['parts'][0]['text'] ?? '');
    if ($content === '') {
        $finish_reason = $result['candidates'][0]['finishReason'] ?? 'unknown';
        error_log('Gemini returned no text (finish reason: ' . $finish_reason . ')');
        return ['content' => '', 'status' => 502, 'error' => 'Gemini returned no text for this request. Please try another topic.'];
    }

    return ['content' => $content, 'status' => 200, 'error' => ''];
}

function get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function rate_limit($key, $max_attempts = 30, $window_seconds = 60) {
    $ip = get_client_ip();
    $session_key = $key . '_' . $ip;
    $now = time();

    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }

    if (!isset($_SESSION['rate_limits'][$session_key])) {
        $_SESSION['rate_limits'][$session_key] = [];
    }

    $_SESSION['rate_limits'][$session_key] = array_filter(
        $_SESSION['rate_limits'][$session_key],
        fn($t) => $t > $now - $window_seconds
    );

    if (count($_SESSION['rate_limits'][$session_key]) >= $max_attempts) {
        return false;
    }

    $_SESSION['rate_limits'][$session_key][] = $now;
    return true;
}
