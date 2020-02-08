<?php

require __DIR__ . '/../vendor/autoload.php';

// Load .env
$dotenv = new Dotenv\Dotenv(__DIR__ . '/../');
$dotenv->load();
function env($key, $defaultValue = '')
{
    return isset($_ENV[$key]) ? $_ENV[$key] : $defaultValue;
}

$settings = [
    'connection' => env('DB_CONNECTION'),
    'host' => env('DB_HOST'),
    'port' => env('DB_PORT'),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
];
$connection = $settings['connection'];
$host = $settings['host'];
$port = $settings['port'];
$database = $settings['database'];
$username = $settings['username'];
$password = $settings['password'];

$dsn = "$connection:host=$host;port=$port;dbname=$database";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $db = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    throw new PDOException($e->getMessage(), (int) $e->getCode());
    die();
}
