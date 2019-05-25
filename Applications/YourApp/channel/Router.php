<?php
namespace GatewayWorker\channel;
use \Workerman\Autoloader;
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once './vendor/mysql-master/src/Connection.php';
require_once __DIR__.'/RouterGroup.php';
require_once __DIR__.'/RouterFactory.php';
Autoloader::setRootPath(__DIR__);
class Router{
	private static $routes=[];

	private static $db_http;

	private static $http_method;

	private static $get_routes=[];

	private static $post_routes=[];

	private static $http_type;

	private static $http_data;

	private static $admin_id;
	public static function setRouter()
	{
		$arms = func_get_args();
		foreach ($arms as  $routerGroup) {
			if(property_exists(__CLASS__, $routerGroup->group.'_routes')){
			static::${$routerGroup->group.'_routes'}=$routerGroup->route;
			}
		}
	}
	public static function setDb($route,$port,$uname,$pwork,$database)
	{
		self::$db_http = new \Workerman\MySQL\Connection($route, $port, $uname, $pwork, $database);
		//self::$db_http=$db_http;
	}
	public static function group(string $method_type,Array $routes)
	{
		return new \GatewayWorker\channel\routerGroup($method_type,$routes);
		//return GatewayWorker\channel\Router::set($method_type,$routes);
	}
	public  function http_set($http_type)
	{
		\Workerman\Protocols\Http::header('Access-Control-Allow-Origin: *');
		if($http_type=='GET'){
			static::$http_method='get';
			return $this;
		}elseif($http_type=='POST'){
			static::$http_method='post';
			return $this;
		}else{
			throw new \Exception("HTTP_METHOD not allowed");
		}
	}
	public static function get_http_data($message)
	{
		return ['get'=>$message['get'],'post'=>$message['post']];
	}
	public  function http_data($data)
	{
		if(isset($data['get']['type'])){
			static::$http_type=$data['get']['type'];
			unset($data['get']['type']);
		}elseif(isset($data['post']['type'])){
			static::$http_type=$data['post']['type'];
			unset($data['post']['type']);
		}else{
			throw new \Exception("type not found");
		}
		if(isset($data['get']['admin_id'])) static::$admin_id=$data['get']['admin_id'];
		if(static::$http_method==false) throw new \Exception("http_method not set");
		static::$http_data=$data[static::$http_method];
		return $this;
	}
	public function exec($con)
	{
		if(!isset(static::${static::$http_method.'_routes'}[static::$http_type])) throw new \Exception("this type of route not access");
		\GatewayWorker\channel\RouterFactory::get_instance(static::$http_method)::{static::${static::$http_method.'_routes'}[static::$http_type]}($con,static::$db_http,static::$http_data);
		//return static::{static::${static::$http_method.'_routes'}[static::$http_type]}(static::$http_type);
	}
	public static function __callStatic($funcname, $arguments) 
	{
		throw new \Exception(($arguments[0]) ." of route function($funcname) not found");
	}
}