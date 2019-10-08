<?php

//error_reporting(E_ALL);

include 'vendor/autoload.php';
use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\ConnectionPoolTrait;
use Smf\ConnectionPool\Connectors\CoroutineMySQLConnector;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

//Main Settings

define('PORT', 8100);
define('ADDRESS', '0.0.0.0');

define('MSL_HOST', 'localhost');
define('MSL_HOST_WEB1', 'localhost');
define('MSL_HOST_WEB2', 'localhost');
define('MSL_HOST_WEB3', 'localhost');
define('MSL_HOST_WEB4', 'localhost');

define('MAX_MSL_POOLS', 4);

define('MSL_PORT', 3306);
define('MSL_DATABASE', 'test');
define('MSL_USER', 'test');
define('MSL_PASSWORD', 'test1000');

//General Router Class

class Router {

    protected static $_routes = [];
    /**
     * @param string $method
     * @param string $url
     * @param callable $callback
     */

    public static function addRoute(string $method, string $url, callable $callback) : void {

	foreach( self::$_routes as $route ) {

	    if ( $route['method'] === strtolower($method) && $route['url'] === $url ) {

		print("Route $method $url already exists");
		return;

	    }

	}

	self::$_routes[] = [
	    'method' => strtolower($method),
	    'url' => $url,
	    'callback' => $callback,
	];

    }

    public static function dispatch(swoole_http_request &$request,swoole_http_response &$response) {

	$method = strtolower($request->server['request_method']);
	$uri =  $request->server['request_uri'];

	foreach ( self::$_routes as $route ) {

	    if ( $route['method'] !== $method ) {

		continue;

	    }

	    $regularStart = '/^';
	    $regularEnd = '$/i';
	    $url = str_replace('/', '\/', $route['url']);
	    $regular = $regularStart . $url . $regularEnd;

	    if ( preg_match($regular, $uri) ) {

		$route['callback']($request, $response);
		return true;

	    }

	}
	
	return false;

    }

}

//General HTTP Class

class HttpServer {

    use ConnectionPoolTrait;

    protected $swoole;

    public function __construct(string $host, int $port) {

	$this->swoole = new Server($host, $port, SWOOLE_PROCESS);

	$this->setDefault();
	$this->bindWorkerEvents();
	$this->bindHttpEvent();

    }

    //Main Performance Settings

    protected function setDefault() {

	$this->swoole->set([

	    'worker_num' => 8,
	    'daemonize' => FALSE,
	    'max_conn' => 1024,
	    'pid_file' => '/var/run/swoole-mysql/main.pid',
	    'dispatch_mode' => 2,
	    'open_tcp_nodelay' => TRUE,
	    'reload_async' => TRUE,
	    'enable_reuse_port' => TRUE,
	    'enable_coroutine' => TRUE

	]);

    }

    //Binding HTTP and HTTP Router

    protected function bindHttpEvent()
    {

	$this->swoole->on('Request', function (Request $request, Response $response) {

	    if (!Router::dispatch($request, $response)){

		$response->status(421);

	    }
			
	});

    }

    //Configuring Databases Pools

    protected function bindWorkerEvents()
    {

	$createPools = function () {

	    // All Redis Connections: [8 workers * 4 = 32, 8 workers * 16 = 128] / Per One Pool

	    $msl_pool = new ConnectionPool(
		[
		    'minActive'         => 4,
		    'maxActive'         => 16,
		    'maxWaitTime'       => 0.1,
		    'maxIdleTime'       => 60,
		    'idleCheckInterval' => 10,
		],
		new CoroutineMySQLConnector,
		[
		    'host'        => MSL_HOST,
		    'port'        => MSL_PORT,
		    'database'    => MSL_DATABASE,
		    'user'        => MSL_USER,
		    'password'    => MSL_PASSWORD,
		    'timeout'     => 10,
		    'charset'     => 'utf8mb4',
		    'strict_type' => true,
//		    'fetch_mode'  => true,
		]);
	    $msl_pool->init();

	    for ( $i = 1; $i <= MAX_MSL_POOLS; $i++ ) {

		${'msl_pool_web'.$i} = new ConnectionPool(
		    [
			'minActive'         => 4,
			'maxActive'         => 16,
			'maxWaitTime'       => 0.1,
			'maxIdleTime'       => 60,
			'idleCheckInterval' => 10,
		    ],
		    new CoroutineMySQLConnector,
		    [
			'host'        => constant('MSL_HOST_WEB' . $i),
			'port'        => MSL_PORT,
			'database'    => MSL_DATABASE,
			'user'        => MSL_USER,
			'password'    => MSL_PASSWORD,
			'timeout'     => 10,
			'charset'     => 'utf8mb4',
			'strict_type' => true,
//			'fetch_mode'  => true,
		    ]);
		${'msl_pool_web'.$i}->init();

	    }

	    $this->addConnectionPool('msl', $msl_pool);

	    for ( $i = 1; $i <= MAX_MSL_POOLS; $i++ ) {

		$this->addConnectionPool('msl_web' . $i, ${'msl_pool_web'.$i});

	    }

	};

	$closePools = function () {

	    $this->closeConnectionPools();

	};

	//System Interception

	$this->swoole->on('WorkerStart', $createPools);
	$this->swoole->on('WorkerStop', $closePools);
	$this->swoole->on('WorkerError', $closePools);

    }

    public function start() {

	$this->swoole->start();

    }

}

//Adding New HTTP Server

$server = new HttpServer(ADDRESS, PORT);

//Multi Object to Array Converter

class Arrayable {

    public static function toArray($object) {

        if ( is_null($object) ) {
            $object = null;
        }

        $original_object = $object;

        if ( is_object($object) ) {
            $object = (array) $object;
        }

        if ( is_array($object) ) {

            $converted = [];

            foreach ( $object as $key => $val ) {

                if ( (!is_object($key))) {

                    $converted[$key] = self::toArray($val);

                } else {

                    $key = str_replace(get_class($original_object), '', $key);
                    $converted[$key] = self::toArray($val);

                }

            }

        } else {

            $converted = $object;

        }

        return $converted;

    }

}

//Creating Routes And Main Logic

Router::addRoute('GET', '/.*', function ($request, $response) use (&$server){

    $msl_pool = $server->getConnectionPool('msl');

    $msl_pools = [];

    for ( $i = 1; $i <= MAX_MSL_POOLS; $i++ ) {

	$msl_pools[$i] = $server->getConnectionPool('msl_web' . $i);

    }

    try {

	$msl = $msl_pool->borrow();

//	print_r($msl);

	$msl_array = Arrayable::toArray($msl);
	$msl_status = array('errCode' => $msl_array['connect_errno']);
	$msl_array = Arrayable::toArray($msl);
	$msl_message = array('errMsg' => $msl_array['connect_error']);

	if ( ($msl_status['errCode']) !== 0 ) {

	    $msl_pool->return($msl);
	    shuffle($msl_pools);

	    foreach ( $msl_pools as $msl_pool ) {

		$msl = $msl_pool->borrow();

		$msl_array = Arrayable::toArray($msl);
		$msl_status = array('errCode' => $msl_array['connect_errno']);
		$msl_array = Arrayable::toArray($msl);
		$msl_message = array('errMsg' => $msl_array['connect_error']);

		if ( ($msl_status['errCode']) !== 0 ) {

		    $msl_pool->return($msl);
		    continue;

		} else {

		    break;

		}

	    }

	    if ( ($msl_status['errCode']) !== 0 ) {

		$response->status(503);
		$msl_pool->return($msl);

		throw new RuntimeException('Error code: ' . $msl_status['errCode'] . 'Error message: ' . $msl_message['errMsg']);

	    }

	}

    } catch (Exception $e) {

	$isBorrow = false;
	shuffle($msl_pools);

	foreach ( $msl_pools as $msl_pool ) {

	    try {

		$msl = $msl_pool->borrow();

	    } catch (Exception $e) {

		$msl_pool->return($msl);
		continue;

	    }

	    if ( $msl ) {

		$isBorrow == true;
		break;

	    }

	}

	if ( ! $isBorrow ) {

	    $response->status(500);
	    $msl_pool->return($msl);
	    return;

	}

    }

    $data = $msl->query('SHOW STATUS LIKE "Threads_connected"');

    $msl_pool->return($msl);

    if ( ! $data ) {

	$response->status(404);
	return;

    }

    $json = [
		'data'  => $data,
    ];

//    print_r($data);
    $response->end(json_encode($json));

});

//Starting HTTP Server

$server->start();
