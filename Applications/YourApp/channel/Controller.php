<?php
class Controller{
	public static function __callStatic($name,$val)
	{
		throw new \Exception('method '.$name." of ".get_called_class()."  not found");
	}
}