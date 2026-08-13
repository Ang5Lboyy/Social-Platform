<?php
$config = [
    'db' => [
        'host' => 'localhost',
        'name' => 'angel_barseghyan2',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
];

$local_config_path = __DIR__ . '/../config.local.php';
if (file_exists($local_config_path)) {
    $local = require $local_config_path;
    if (is_array($local) && isset($local['db']) && is_array($local['db'])) {
        $config['db'] = array_merge($config['db'], $local['db']);
    }
}

return $config;
