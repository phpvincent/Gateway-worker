<?php
namespace GatewayWorker\channel;
use \Workerman\Autoloader;
require_once __DIR__ . '/../../../vendor/autoload.php';

class RouterFactory{
	private static $instance=[];

	public static function get_instance($type)
	{
		$obj_name=$type.'Controller';
		if(isset(static::$instance[$obj_name])){
			return static::$instance[$obj_name];
		}
		require_once __DIR__.'/'.$type.'Controller.php';
		$obj=new $obj_name();
		static::$instance[$obj_name]=$obj;
		return static::$instance[$obj_name];
	}
}