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
    'logger' => [
        'name' => env('APP_NAME', 'App'),
        'path' => env('docker') ? 'php://stdout' : __DIR__ . '/../logs/app.log',
        'level' => \Monolog\Logger::DEBUG,
    ],
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
    $pclient = new Predis\Client();

    $log = new Monolog\Logger('MQTT_PBASE_TRIGGER');
    $log->pushProcessor(new Monolog\Processor\UidProcessor());
    $handler = new Monolog\Handler\RotatingFileHandler($settings['logger']['path'], 0, $settings['logger']['level'], true, 0664);
    $handler->setFilenameFormat('{date}_{filename}', 'Y-m-d');
    $log->pushHandler($handler);
} catch (PDOException $e) {
    echo "ERROR ({$e->getCode()}): {$e->getMessage()}\n";
    die();
}
