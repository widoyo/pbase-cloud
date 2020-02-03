<?php

use Slim\Http\Request;
use Slim\Http\Response;

if (PHP_SAPI == 'cli-server') {
    // To help the built-in PHP dev server, check if the request was actually for
    // something which should probably be served as a static file
    $url  = parse_url($_SERVER['REQUEST_URI']);
    $file = __DIR__ . $url['path'];
    if (is_file($file)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

session_start();

/**
 * SETTINGS BLOCK
 */

// Load .env
$dotenv = new Dotenv\Dotenv(__DIR__ .'/../');
$dotenv->load();
function env($key, $defaultValue='') {
    return isset($_ENV[$key]) ? $_ENV[$key] : $defaultValue;
}

// get timezone from ENV, default "Asia/Jakarta"
date_default_timezone_set(env('APP_TIMEZONE', "Asia/Jakarta"));

$settings = [
    'settings' => [
        'displayErrorDetails' => env('APP_ENV', 'local') != 'production',
        'addContentLengthHeader' => false, // Allow the web server to send the content-length header
        'debugMode' => env('APP_DEBUG', 'true') == 'true',
        'upload_directory' => __DIR__ . '/uploads', // upload directory

        // Renderer settings
        'renderer' => [
            'template_path' => __DIR__ . '/../templates/',
			'cache_path' => env('APP_ENV', 'local') != 'production' ? '' : __DIR__ . '/../cache/'
        ],

        // Monolog settings
        'logger' => [
            'name' => env('APP_NAME', 'App'),
            'path' => env('docker') ? 'php://stdout' : __DIR__ . '/../logs/app.log',
            'level' => \Monolog\Logger::DEBUG,
        ],

        // Database
        'db' => [
			'connection' => env('DB_CONNECTION'),
			'host' => env('DB_HOST'),
			'port' => env('DB_PORT'),
			'database' => env('DB_DATABASE'),
			'username' => env('DB_USERNAME'),
			'password' => env('DB_PASSWORD'),
        ],
        'jwt' => [
            'secret' => env('SECRET')
        ]
    ],
];

// Instantiate the app
$app = new \Slim\App($settings);

/**
 * # SETTINGS BLOCK
 */

/**
 * DEPENDENCIES BLOCK
 */

// Set up dependencies
$container = $app->getContainer();

// view renderer
$container['view'] = function ($c) {
    $settings = $c->get('settings')['renderer'];
	$view = new \Slim\Views\Twig($settings['template_path'], [
        // 'cache' => $settings['cache_path']
    ]);

    // Instantiate and add Slim specific extension
    $router = $c->get('router');
    $uri = \Slim\Http\Uri::createFromEnvironment(new \Slim\Http\Environment($_SERVER));
    $view->addExtension(new \Slim\Views\TwigExtension($router, $uri));

    return $view;
};

// not found handler
$container['notFoundHandler'] = function($c) {
    return function (Request $request, Response $response) use ($c) {
        // return $response->withJson([
            // "status" => "404",
            // "message" => "endpoint not found",
            // "data" => []
        // ], 404, JSON_PRETTY_PRINT);
		return $c->view->render($response->withStatus(404), 'errors/404.html');
    };
};

// error handler
if (!$container->get('settings')['debugMode'])
{
    $container['errorHandler'] = function($c) {
        return function ($request, $response) use ($c) {
            return $c->view->render($response->withStatus(500), 'errors/500.phtml');
        };
    };
    $container['phpErrorHandler'] = function ($c) {
        return $c['errorHandler'];
    };
}

// flash messages
$container['flash'] = function() {
    return new \Slim\Flash\Messages();
};

// session helper
require_once __DIR__ . '/../src/Session.php';
$container['session'] = function() {
    return Session::getInstance();
};

// monolog
$container['logger'] = function ($c) {
    $settings = $c->get('settings')['logger'];
    $logger = new Monolog\Logger($settings['name']);
    $logger->pushProcessor(new Monolog\Processor\UidProcessor());
    $logger->pushHandler(new Monolog\Handler\StreamHandler($settings['path'], $settings['level']));
    return $logger;
};

// db
$container['db'] = function($c) {
    $settings = $c->get('settings')['db'];
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
		return new PDO($dsn, $username, $password, $options);
	} catch (PDOException $e) {
		throw new PDOException($e->getMessage(), (int)$e->getCode());
	}
};

// get active user, cara menggunakan: $this->user
$container['user'] = function($c) {
    $session = Session::getInstance();
	if (!isset($session->user_id)) {
		return null;
	};
	
	$user_id = $session->user_id;

    // hide password, just because
	$stmt = $c->db->prepare("SELECT * FROM users WHERE id=:id");
	$stmt->execute([':id' => $user_id]);
	$user = $stmt->fetch();
	return $user ?: null;
};

/**
 * # DEPENDENCIES BLOCK
 */

/**
 * MIDDLEWARES BLOCK
 */

$loggedinMiddleware = function (Request $request, Response $response, $next) {
    $user_refresh_time = $this->session->user_refresh_time;
    $now = time();

    // cek masa aktif login
    if (!empty($user_refresh_time) && $user_refresh_time < $now) {
        $this->session->destroy();
        return $this->response->withRedirect('/login');
    }

    // cek user exists, ada di index.php
    $user = $this->user;
    if (!$user) {
        $this->flash->addMessage('errors', 'Silahkan login untuk melanjutkan.');
        return $this->response->withRedirect('/login');
    }

    $this->session->user_refresh_time = strtotime("+24hour");

    return $next($request, $response);
};

$adminRoleMiddleware = function (Request $request, Response $response, $next) {
    $user = $this->user;
    if (!$user || $user['tenant_id'] > 0) {
        $this->flash->addMessage('errors', 'Hanya admin yang diperbolehkan mengakses laman tersebut.');
        return $this->response->withRedirect('/logger');
    }

    return $next($request, $response);
};

$getLoggerMiddleware = function (Request $request, Response $response, $next) {
	$args = $request->getAttribute('routeInfo')[2];
    if (!empty($args['id'])) {
        $logger_id = intval($args['id']);
        $stmt = $this->db->prepare("SELECT * FROM logger WHERE id=:id");
        $stmt->execute([':id' => $logger_id]);
    } else if (!empty($args['sn'])) {
        $logger_sn = $args['sn'];
        $stmt = $this->db->prepare("SELECT * FROM logger WHERE sn=:sn");
        $stmt->execute([':sn' => $logger_sn]);
    } else {
        throw new \Slim\Exception\NotFoundException($request, $response);
    }

    $logger = $stmt->fetch();

    $user = $this->user;
    if (!$logger || ($user['tenant_id'] > 0 && $user['tenant_id'] != $logger['tenant_id'])) {
        throw new \Slim\Exception\NotFoundException($request, $response);
    }

    $logger['location_nama'] = null;
    if (!empty($logger['location_id'])) {
        $location = $this->db->query("SELECT * FROM location WHERE id={$logger['location_id']}")->fetch();
        if ($location) {
            $logger['location_nama'] = $location['nama'];
        }
    }

    $request = $request->withAttribute('logger', $logger);

    return $next($request, $response);
};

$getLocationMiddleware = function (Request $request, Response $response, $next) {
    $args = $request->getAttribute('routeInfo')[2];
    if (!empty($args['id'])) {
        $location_id = intval($args['id']);
        $stmt = $this->db->prepare("SELECT * FROM location WHERE id=:id");
        $stmt->execute([':id' => $location_id]);
    } else {
        throw new \Slim\Exception\NotFoundException($request, $response);
    }

    $location = $stmt->fetch();

    $user = $this->user;
    if (!$location || ($user['tenant_id'] > 0 && $user['tenant_id'] != $location['tenant_id'])) {
        throw new \Slim\Exception\NotFoundException($request, $response);
    }

    $location['tenant_nama'] = null;
    if (!empty($location['tenant_id'])) {
        $tenant = $this->db->query("SELECT * FROM tenant WHERE id={$location['tenant_id']}")->fetch();
        if ($location) {
            $location['tenant_nama'] = $tenant['nama'];
        }
    }

    $request = $request->withAttribute('location', $location);

    return $next($request, $response);
};

$getTenantMiddleware = function (Request $request, Response $response, $next) {
	$args = $request->getAttribute('routeInfo')[2];
    $tenant_id = intval($args['id']);
    $stmt = $this->db->prepare("SELECT * FROM tenant WHERE id=:id");
    $stmt->execute([':id' => $tenant_id]);
    $tenant = $stmt->fetch();

    $user = $this->user;
    if (!$tenant || ($user['tenant_id'] > 0 && $user['tenant_id'] != $tenant_id)) {
        throw new \Slim\Exception\NotFoundException($request, $response);
    }
    
    $request = $request->withAttribute('tenant', $tenant);

    return $next($request, $response);
};

$getUserMiddleware = function (Request $request, Response $response, $next) {
	$args = $request->getAttribute('routeInfo')[2];
    $user_id = intval($args['id']);
    $stmt = $this->db->prepare("SELECT * FROM users WHERE id=:id");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new \Slim\Exception\NotFoundException($request, $response);
    }
    
    $request = $request->withAttribute('user', $user);

    return $next($request, $response);
};

/**
 * # MIDDLEWARES BLOCK
 */

/**
 * HELPERS BLOCK
 */

// Menambahkan fungsi env() pada Twig
$env = new Twig\TwigFunction('env', function ($key, $default) {
	return isset($_ENV[$key]) ? $_ENV[$key] : $default;
});
$container->get('view')->getEnvironment()->addFunction($env);

// Menambahkan fungsi asset() pada Twig
$asset = new Twig\TwigFunction('asset', function ($path) {
	return $_ENV['APP_URL'] .'/'. $path;
});
$container->get('view')->getEnvironment()->addFunction($asset);

// Menambahkan fungsi flash() pada Twig
$flash = new Twig\TwigFunction('flash', function ($key) use ($container) {
    return $container->get('flash')->getMessage($key);
});
$container->get('view')->getEnvironment()->addFunction($flash);

// Menambahkan fungsi session() pada Twig
$session = new Twig\TwigFunction('session', function () {
	return Session::getInstance();
});
$container->get('view')->getEnvironment()->addFunction($session);

// Menambahkan fungsi user() pada Twig -> untuk mendapatkan current user
$user = new Twig\TwigFunction('user', function () use ($container) {
	return $container->get('user');
});
$container->get('view')->getEnvironment()->addFunction($user);

// curl
// https://stackoverflow.com/questions/28858351/php-ssl-certificate-error-unable-to-get-local-issuer-certificate
function curl ($url, $method="GET", $headers=[], $postFields="")
{
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => $headers,
    ));
    
    // ob_start();  
    // $out = fopen('php://output', 'w');
    // curl_setopt($curl, CURLOPT_VERBOSE, true);  
    // curl_setopt($curl, CURLOPT_STDERR, $out);  

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);
    
    // fclose($out);  
    // $debug = ob_get_clean();
    if ($err) {
        return $err;
    }
    // } else if ($debug) {
    //  return $debug;
    // }

    return $response;
};

/**
 * HELPER UNTUK DUMP + DIE
 */
function dump($var, $die=true) {
    echo '<pre>';
    var_dump($var);
    echo '</pre>';
    if ($die) {
        die();
    }
}

/**
 * HELPER UNTUK FORMAT DATE
 */
function tanggal_format($time, $usetime=false) {
    switch (date('n', $time)) {
        case 1: $month = 'Januari'; break;
        case 2: $month = 'Februari'; break;
        case 3: $month = 'Maret'; break;
        case 4: $month = 'April'; break;
        case 5: $month = 'Mei'; break;
        case 6: $month = 'Juni'; break;
        case 7: $month = 'Juli'; break;
        case 8: $month = 'Agustus'; break;
        case 9: $month = 'September'; break;
        case 10: $month = 'Oktober'; break;
        case 11: $month = 'November'; break;
        default: $month = 'Desember'; break;
    }
    return date('j', $time) .' '. $month .' '. date('Y', $time) . ($usetime ? ' '. date('H:i', $time) : '');
}

/**
 * # HELPERS BLOCK
 */

$app->get('/test', function(Request $request, Response $response) {
    phpinfo();
    die();
    return $this->view->render($response, 'template.html');
});

$app->group('/api', function() use ($getLoggerMiddleware) {
    $app = $this;

    require __DIR__ . '/../src/api/logger.php';
});

require __DIR__ . '/../src/main.php';
require __DIR__ . '/../src/tenant.php';
require __DIR__ . '/../src/user.php';
require __DIR__ . '/../src/logger.php';
require __DIR__ . '/../src/lokasi.php';

/**
 * # ROUTES BLOCK
 */

// Run app
$app->run();
