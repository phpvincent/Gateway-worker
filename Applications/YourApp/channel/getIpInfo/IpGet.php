<?php
namespace GatewayWorker\channel\getIpInfo;
require_once './Applications/YourApp/channel/getIpInfo/IpLocation.php';
use GatewayWorker\channel\getIpInfo\IpLocation;
class IpGet {
	private static $ipinfo;
	private static $is_local=false;
	public function __construct($ip)
	{	
		if($ip=='127.0.0.1') static::$is_local=true;
		//$IpLocation=new IpLocation($ip);
		static::$ipinfo=static::getIpInfo($ip);
	}
	public function getIpMsg()
	{
		return static::$ipinfo;
	}
	public function getCountry()
	{
		if(isset(static::$ipinfo['country'])&&static::$ipinfo['country']!='XX'&&static::$ipinfo['country']!=null){
			$cou=static::$ipinfo['country'];
		}else{
			$cou=static::$ipinfo['city']=='XX' ? static::$ipinfo['region'] : static::$ipinfo['city'];
		}
		return $cou;
	}
	/**
    * 根据Ip获取地址信息
    * @param  [type]  $ip   [description]
    * @param  boolean $type [description]
    * @return [type]        [description]
    */
	private static function getIpInfo($ip)
    { 
      //set_time_limit(0);
        $IpLocation=new IpLocation();
        $ip = $IpLocation->getlocation($ip);
        if($ip!=null&&$ip!=false&&$ip!=[]&&$ip!=''){
          $iplo['ip']=$ip['ip'];
          $iplo['country']=$ip['country'];
          $iplo['city']=$ip['city'];
          $iplo['region']=$ip['province'];
          $iplo['county']=$ip['city'];
          $iplo['area']=$ip['area'];
          $iplo['isp']=$ip['area'];
          if(strpos($iplo['isp'],"facebook")!==false||strpos($iplo['isp'],"Facebook")!==false||strpos($iplo['isp'],"脸书")!==false){
             $iplo['isp']='脸书';
          }
          return $iplo;
        }
        //根据接口获取
      if($ip!='127.0.0.1'){
        //获取网络来源
         $data = @file_get_contents('https://api.ip.sb/geoip/'.$ip);
         $arr['isp']=json_decode($data,true)['organization'];
      }else{
         $data=true;
         $arr['isp']='本机地址';
      }     
        //判断是否是脸书人员
      if(strpos($arr['isp'],"facebook")!==false||strpos($arr['isp'],"Facebook")!==false){
         $arr['isp']='脸书';
      }
      //获取地区信息
      $area=@file_get_contents('https://freeapi.ipip.net/'.$ip);
      $area=json_decode($area,true);
      $arr['ip']=$request->getClientIp();
      $arr['region']=isset($area[1])?$area[1]:'XX';
      $arr['country']=isset($area[0])?$area[0]:'XX';
      $arr['city']=isset($area[2])?$area[2]:'XX';
      $arr['county']=isset($area[3])?$area[3]:'XX';
      $arr['area']=isset($area[0])?$area[0]:'XX';
     if($data==false||$area==false){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://ip.taobao.com/service/getIpInfo.php?ip=".$ip);
        $wip=\App\vis::first()['vis_ip'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-FORWARDED-FOR:8.8.8.8', "CLIENT-IP:".$wip));  //构造IP
        curl_setopt($ch, CURLOPT_REFERER, "http://taobao.com.cn/");   //构造来路
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        //curl_setopt($ch, CURLOPT_HEADER, 1);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if($httpCode == 502) {
         $data = @file_get_contents('https://api.ip.sb/geoip/'.$ip);
         $arr['country']=isset(json_decode($data,true)['country'])?json_decode($data,true)['country']:'XX';
         $arr['area']=isset(json_decode($data,true)['continent_code'])?json_decode($data,true)['continent_code']:'XX';
         $arr['region']=isset(json_decode($data,true)['region'])?json_decode($data,true)['region']:'XX';
         $arr['city']=isset(json_decode($data,true)['city'])?json_decode($data,true)['city']:'XX';
         $arr['county']=isset(json_decode($data,true)['county'])?json_decode($data,true)['county']:'XX';
         $arr['isp']=isset(json_decode($data,true)['organization'])?json_decode($data,true)['organization']:'XX';
         $arr['ip']=$request->getClientIp();
         if(strpos($arr['isp'],"facebook")!==false||strpos($arr['isp'],"Facebook")!==false){
           $arr['isp']='脸书';
         }
         return $arr;
        }
          curl_close($ch);
          $arr=json_decode($data,true);
          return $arr['data'];
     }else{
        return $arr;
     }
    return $arr;
  }
} 