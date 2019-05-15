<?php
namespace GatewayWorker\channel;
use \GatewayWorker\Lib\Gateway;
class sendSDK {
	private static $lan_arr=[
        '菲律宾'=>'ENG',
        '印度尼西亚'=>'IND',
        '沙特阿拉伯'=>'ARB',
        '阿联酋'=>'ARB',
        '卡塔尔'=>'ARB',
        '台湾省'=>'CHI'
      ];
	 /**
    * 向客户端发送数据
    * @param  [num] $client_id [id]
    * @param  [num] $status [状态，0：成功]
    * @param  [type] $msg    [数据]
    * @return [type]         [description]
    */
   public static function msgToClient($client_id,$msg,$status=0)
   {
      if($client_id=='all'){
        GateWay::sendToAll(json_encode(['touser'=>'client','status'=>$status,'msg'=>$msg]));
      }elseif(mb_strlen($client_id)<10){
        Gateway::sendToGroup('client_'.$client_id,json_encode(['touser'=>'client','status'=>$status,'msg'=>$msg]),$_SERVER['GATEWAY_CLIENT_ID']);
      }else{
        // 向当前client_id发送数据 
        Gateway::sendToClient($client_id, json_encode(['touser'=>'client','status'=>$status,'msg'=>$msg]));
      }
     
   }
   /**
    * 向管理员发送数据
    * @param  [type] $type [0:单独发送，1：群体发送]
    * @param  [type] $status [description]
    * @param  [type] $status [description]
    * @param  [type] $msg    [description]
    * @return [type]         [description]
    */
   public static function msgToAdmin($type=1,$group,$msg,$status=0)
   {
    if($type==1){
     Gateway::sendToGroup($group,json_encode(['touser'=>'admin','status'=>$status,'msg'=>$msg]),$_SERVER['GATEWAY_CLIENT_ID']);
    }else{
     Gateway::sendToClient($group,json_encode(['touser'=>'admin','status'=>$status,'msg'=>$msg]));
    }
   }
    /**
    * 根据pid向管理员发送数据
    * @param  [type] $status [description]
    * @param  [type] $status [description]
    * @param  [type] $msg    [description]
    * @return [type]         [description]
    */
   public static function msgToAdminByPid($pid,$msg,$status=0)
   {
     Gateway::sendToUid($pid,  json_encode(['touser'=>'admin','status'=>$status,'msg'=>$msg]));
   }  
   /**
    * 根据pid向客户发送数据
    * @param  [type] $status [description]
    * @param  [type] $status [description]
    * @param  [type] $msg    [description]
    * @return [type]         [description]
    */
   public static function msgToClientByPid($pid,$msg,$status=0)
   {
     Gateway::sendToUid($pid,  json_encode(['touser'=>'client','status'=>$status,'msg'=>$msg]));
   } 
   /**
    * 给服务端消息转发
    * @param  [type] $status [description]
    * @param  [type] $status [description]
    * @param  [type] $msg    [description]
    * @return [type]         [description]
    */
   public static function resendToAdmin($tosend,$msg,$status=0)
   {
    if(mb_strlen($tosend)>10){
      //发送给指定用户的消息
      $client_id=Gateway::getClientIdByUid($tosend);
      $lan=static::getlanfromcountry($_SESSION[$client_id]['ip_info']['country']);
      if($lan==false) return false;
      Gateway::sendToGroup($lan,json_encode(['type'=>'resendToAdmin','touser'=>$tosend,'msg'=>$msg,'status'=>$status]),$_SERVER['GATEWAY_CLIENT_ID']);
      return true;
    }elseif($tosend!='all'){
     $lan=static::getlanfromcountry($tosend);var_dump($lan);
     if($lan==false) return false;
     Gateway::sendToGroup($lan,json_encode(['type'=>'resendToAdmin','touser'=>$tosend,'msg'=>$msg,'status'=>$status]),$_SERVER['GATEWAY_CLIENT_ID']);
     return true;
    }elseif($tosend=='all'){
      Gateway::sendToGroup('admin',json_encode(['type'=>'resendToAdmin','msg'=>$msg,'touser'=>$tosend,'status'=>$status]),$_SERVER['GATEWAY_CLIENT_ID']);
      return true;
    }
   } 
   public static function getlanfromcountry($country)
   {
    $arr=static::$lan_arr;
      if(!array_key_exists($country, $arr)) return false;
      return $arr[$country];
   }
   /**
    * 湖片区语言id
    * @param  [type] $country [description]
    * @return [type]          [description]
    */
   public static function getlanid($client_id)
   {
    $country=$_SESSION[$client_id]['ip_info']['country'];
    $arr=static::$lan_arr;
      if(!array_key_exists($country, $arr)) return 'XXX';
      return $arr[$country];
   }
}