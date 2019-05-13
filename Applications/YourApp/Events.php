<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
//declare(ticks=1);

use \GatewayWorker\Lib\Gateway;
//use \GatewayWorker\channel\getIpInfo\IpGet;
require_once '..../vendor/mysql-master/src/Connection.php';
require_once '/channel/getIpInfo/IpGet.php';
/**
 * 主逻辑
 * 主要是处理 onConnect onMessage onClose 三个方法
 * onConnect 和 onClose 如果不需要可以不用实现并删除
 */
class Events
{
    public static $db = null;
    public static function onWorkerStart($worker)
    {
        //self::$db = new \Workerman\MySQL\Connection('172.31.37.203', '3306', 'admin', 'ydzsadmin', 'obj');
        self::$db = new \Workerman\MySQL\Connection('127.0.0.1', '3306', 'root', '', 'obj');
    }
    /**
     * 当客户端连接时触发
     * 如果业务不需此回调可以删除onConnect
     * 
     * @param int $client_id 连接id
     */
    public static function onConnect($client_id)
    {
        $ip=$_SERVER['REMOTE_ADDR'];
        //Gateway::bindUid($client_id,$ip);

        //得到地址信息
        $IpGet=new IpGet($ip);
        $ip_info=$IpGet->getIpMsg();
        unset($IpGet);
        $ip_info['country']=$ip_info->getCountry();
        $time=date('Y-m-d H:i:S',time());

        //记录全局信息
        $_SESSION[$client_id]['ip_info']=$ip_info;
        $_SESSION[$client_id]['first_time']=$time;

        // 向当前client_id发送数据 
        static::msgToClient($client_id,['type'=>'connet_success','client_id'=>$client_id,'ip'=>$ip,'country'=>$ip_info['country'],'time'=>$time]);
        
        if($ip_info!=false && $ip_info!=null && $ip_info!=[]){
          // 通知服务端
          static::msgToAdmin(1,$ip_info['country'],['type'=>'connet_notice','client_id'=>$client_id,'ip'=>$ip,'country'=>$ip_info['country'],'time'=>$time]);
        }
       
    }
    
   /**
    * 当客户端发来消息时触发
    * @param int $client_id 连接id
    * @param mixed $message 具体消息
    */
   public static function onMessage($client_id, $message)
   {
        // 向所有人发送 
        $msg=json_decode($message);
        if(!isset($msg['user'])) GateWay::closeClient($client_id);
        switch ($msg['user']) {
          case 'client':
            if($msg['type']=='first_client'){
              //初次链接，分配pid
              $pid=time().static::getlanid($client_id).rand(10000,99999);
              Gateway::bindUid($client_id,$pid);
              static::msgToClient($client_id,['type'=>'first_client','pid'=>$pid]);
              return;
            }
            $ip_info=$_SESSION[$client_id]['ip_info'];
            if(isset($ip_info['country'])&&$ip_info['country']!=null&&$ip_info['country']!='XX'){
              if(!isset($msg['pid'])){
                static::msgToClient($client_id,['type'=>'clientSend','err'=>'pid not found'],-3);
              }   
              Gateway::bindUid($client_id,$msg['pid']);
              Gateway::joinGroup($client_id, 'client_'.$ip_info['country']);
              Gateway::joinGroup($client_id, 'client');
              $msg['type']='clientSend';
              static::msgToAdmin(1,$ip_info['country'],$msg);
              return;
            }
            break;
          case 'admin':
          //身份验证
            if($msg['type']=='auth'){
              $admin=self::$db->select('*')->from('admin')->where('admin_name='.$msg['admin_name'])->offset(0)->limit(1)->query();
              if($admin==false||password_hash($admin['admin_password'])!=$admin['admin_password']){
                static::msgToAdmin(0,$client_id,['err'=>'auth unallow','type'=>'auth'],-1);
                return;
              }else{
                $_SESSION[$client_id]['auth']=$admin;
                Gateway::joinGroup($client_id,$msg['language']);
                Gateway::joinGroup($client_id,'admin');
                Gateway::bindUid($client_id,$admin['admin_id']);
                static::msgToAdminByPid($admin['admin_id'],['pid'=>$admin['admin_id'],'type'=>'auth',1]);
                return;
              }
            }
          //数据推送
            if(!isset($_SESSION[$client_id])||!isset($_SESSION[$client_id]['auth'])){
              //验证身份
              static::msgToAdmin(0,$client_id,['err'=>'auth unallow','type'=>'adminSend'],-2);
              return;
            }
            //推送数据
            if(!isset($msg['touser'])){
              static::msgToAdmin(0,$client_id,['err'=>'touser not found','type'=>'adminSend'],-3);
              return;
            }elseif($msg['touser']=='all'){
              //推送给所有客户端
              if(isset($msg['country'])){
                $msg_arr=[];
                $msg_arr['type']='adminSend';
                $msg_arr['msg']=$msg['msg'];
                static::msgToClient($msg['country'],$msg_arr);
                 //转发给对应语种服务端
                 $code=static::resendToAdmin($msg['country'],$msg['msg']);
                 if($code==false){
                  Gateway::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,country not allow'],-4);
                 }
              }else{
                static::msgToClient('all',$msg['msg']);
                //转发给所有服务端
                 $code=static::resendToAdmin('all',$msg['msg']);
                 if($code==false){
                  Gateway::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,country not allow'],-4);
                 }
              }
              return;
            }else{
              static::msgToClientByPid($msg['touser'],$msg['msg']);
            }
            break;
          default:
            # code...
            break;
        }
       //Gateway::sendToAll("$client_id said $message\r\n");
   }
   
   /**
    * 当用户断开连接时触发
    * @param int $client_id 连接id
    */
   public static function onClose($client_id)
   {
      $pid=Gateway::getUidByClientId($client_id);
      if($pid==false){
        return;
      }

       // 通知服务端
       GateWay::msgToAdmin(1,'admin',['msg'=>"$client_id($pid) logout",'type'=>'clientClose','client_id'=>$client_id,'pid'=>$pid]);
   }
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
        GateWay::sendToAll(json_decode(['touser'=>'client','status'=>$status,'msg'=>$msg]));
      }elseif(count($client_id)<10){
        Gateway::sendToGroup('client_'.$client_id,json_decode(['touser'=>'client','status'=>$status,'msg'=>$msg]));
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
     Gateway::sendToGroup($group,json_encode(['touser'=>'admin','status'=>$status,'msg'=>$msg]));
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
    if(count($tosend)>10){
      //发送给指定用户的消息
      $client_id=Gateway::::getClientIdByUid($tosend);
      $lan=static::getlanfromcountry($_SESSION[$client_id]['ip_info']['country']);
      if($lan==false) return false;
      Gateway::sendToGroup($lan,json_encode(['type'=>'resendToAdmin','touser'=>$tosend,'msg'=>$msg,'status'=>$status]));
    }elseif($tosend!='all'){
     $lan=static::getlanfromcountry($tosend);
     if($lan==false) return false;
     Gateway::sendToGroup($lan,json_encode(['type'=>'resendToAdmin','touser'=>$tosend,'msg'=>$msg,'status'=>$status]));
    }elseif($tosend=='all'){
      Gateway::sendToGroup('admin',json_encode(['type'=>'resendToAdmin','msg'=>$msg,'touser'=>$tosend,'status'=>$status]));
    }
   } 
   public static function getlanfromcountry($country)
   {
    $arr=[
        '菲律宾'=>'ENG',
        '印度尼西亚'=>'IND',
        '沙特阿拉伯'=>'ARB',
        '阿联酋'=>'ARB',
        '卡塔尔'=>'ARB',
        '台湾省'=>'CHI'
      ];
      if(!in_array($country, $arr)) return false;
      return $arr[$country];
   }
}
