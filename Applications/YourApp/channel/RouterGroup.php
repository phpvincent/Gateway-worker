<?php
namespace GatewayWorker\channel;
class RouterGroup{
	public  $group;
	public  $route;
	public function __construct($group,$route)
	{	
		$this->group=$group;
		$this->route=$route;
	}
}