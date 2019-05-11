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
        Gateway::bindUid($client_id,$ip);

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
          static::msgToAdmin($ip_info['country'],['type'=>'connet_notice','client_id'=>$client_id,'ip'=>$ip,'country'=>$ip_info['country'],'time'=>$time]);
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
            $ip_info=$_SESSION[$client_id]['ip_info'];
            if(isset($ip_info['country'])&&$ip_info['country']!=null&&$ip_info['country']!='XX'){
              Gateway::joinGroup($client_id, 'clent_'.$ip_info['country']);
              static::msgToAdmin(1,$ip_info['country'],$msg);
            }
            break;
          case 'admin':
          //身份验证
            if($msg['type']=='auth'){
              $admin=self::$db->select('*')->from('admin')->where('admin_name='.$msg['admin_name'])->offset(0)->limit(1)->query();
              if($admin==false||password_hash($admin['admin_password'])!=$admin['admin_password']){
                static::msgToAdmin(0,$client_id,'auth unallow',-1);
                return;
              }else{
                $_SESSION[$client_id]['auth']=$admin;
                Gateway::joinGroup($client_id,$msg['language']);
                static::msgToAdmin(0,$client_id,'auth success!');
                return;
              }
            }
          //数据推送
            if(!isset($_SESSION[$client_id])||!isset($_SESSION[$client_id]['auth'])){
              static::msgToAdmin(0,$client_id,'auth unallow',-1);
              return;
            }elseif($msg['touser']=='all'){
              static::msgToClient('all',$msg['msg']);
              return;
            }else{
              static::msgToClient($msg['touser'],$msg['msg']);
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
       // 向所有人发送 
       GateWay::msgToAdmin("$client_id logout\r\n");
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
      }
     // 向当前client_id发送数据 
      Gateway::sendToClient($client_id, json_encode(['touser'=>'client','status'=>$status,'msg'=>$msg]));
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
}
