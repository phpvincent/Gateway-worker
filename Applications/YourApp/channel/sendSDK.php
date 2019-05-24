<?php
namespace GatewayWorker\channel;
use \GatewayWorker\Lib\Gateway;
class sendSDK {
	public static $lan_arr=[
        '菲律宾'=>'ENG',
        '印度尼西亚'=>'IND',
        '沙特阿拉伯'=>'ARB',
        '阿联酋'=>'ARB',
        '卡塔尔'=>'ARB',
        '台湾省'=>'CHI',
        '美国'=>'ENG'
      ];
    public static $lan_alias=[
        '菲律宾'=>'PH',
        '印度尼西亚'=>'ID',
        '沙特阿拉伯'=>'SA',
        '阿联酋'=>'AE',
        '卡塔尔'=>'QA',
        '台湾省'=>'TW',
        '美国'=>'US'
    ];
    public static $lan=[];
    public static $country=[];
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
            GateWay::sendToAll(json_encode(['touser'=>'client','status'=>$status,'msg'=>$msg,'type'=>'notice']));
      }elseif(strlen($client_id)<10){
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
        if(strlen($tosend)>10){
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

    /**
     * 根据国家获取语言
     * @param $country
     * @return bool|mixed
     */
   public static function getlanfromcountry($country)
   {
      $arr=static::$lan_arr;
      if(!array_key_exists($country, $arr)) return false;
      return $arr[$country];
   }

    /**
     * 根据国家获取简称
     * @param $country
     * @return bool|mixed
     */
   public static function getcountryandalias($country)
   {
       $arr=static::$lan_alias;
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

    /**
     * 消息模板
     * @param $msg
     * @return array
     */
   public static function msg_template($msg)
   {
       //客服发送消息
       $data = [
           'username' => isset($msg['msg']['mine']['username']) ? $msg['msg']['mine']['username'] : '',
           'avatar' => isset($msg['msg']['mine']['avatar']) ? $msg['msg']['mine']['avatar'] : '/admin/images/13.jpg',
           'id' => isset($msg['msg']['mine']['id']) ? $msg['msg']['mine']['id'] : 0,
           'type' => $msg['msg']['to']['type'],
           'content' => isset($msg['msg']['mine']['content']) ? $msg['msg']['mine']['content'] : '',
           'cid' => 0,
           'mine'=> $msg['pid'] == $msg['msg']['mine']['id'] ? true : false,//要通过判断是否是我自己发的
           'fromid' => $msg['msg']['mine']['id'],
           'timestamp' => time()*1000
       ];
       return $data;
   }
}