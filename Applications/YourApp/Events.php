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
require_once './vendor/mysql-master/src/Connection.php';
require_once './Applications/YourApp/channel/getIpInfo/IpGet.php';
require_once './Applications/YourApp/channel/sendSDK.php';
require_once './Applications/YourApp/channel/onMessageAdmin.php';
require_once './Applications/YourApp/channel/onMessageClient.php';
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
        global $config;
        //self::$db = new \Workerman\MySQL\Connection('172.31.37.203', '3306', 'admin', 'ydzsadmin', 'obj')
        self::$db = new \Workerman\MySQL\Connection($config['database']['route'],$config['database']['port'], $config['database']['username'], $config['database']['password'],$config['database']['database']);
        //self::$db = new \Workerman\MySQL\Connection('127.0.0.1', '3306', 'root', 'root', 'obj');
        /*global $http_worker;
        $http_worker=new \Workerman\Worker('http://0.0.0.1:8200');
        var_dump($http_worker);
        $http_worker->onMessage='http_listen';
        $http_worker->listen();*/
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
        //if(strstr($ip, '192.168.1')!==false) return;
        $ip='39.10.194.98';
        //得到地址信息
        $IpGet=new GatewayWorker\channel\getIpInfo\IpGet($ip);
        $ip_info=$IpGet->getIpMsg();
        //unset($IpGet);
        $time=date('Y-m-d H:i:s',time());
        //$ip_info['country']=$IpGet->getCountry() != "局域网" ? $IpGet->getCountry() : '台湾省';
        if(!array_key_exists($ip_info['country'], GatewayWorker\channel\sendSDK::$lan_arr)){
          GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'connet fail,country not allowed','client_id'=>$client_id,'ip'=>$ip,'country'=>$ip_info['country'],'time'=>$time]);
          Gateway::closeClient($client_id);
          return;
        }else{
          $ip_info['lan']=GatewayWorker\channel\sendSDK::$lan_arr[$ip_info['country']];
        }
        //记录全局信息
        $_SESSION[$client_id]['ip_info']=$ip_info;
        $_SESSION[$client_id]['first_time']=$time;
        // 向当前client_id发送数据 
        GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'connet_success','client_id'=>$client_id,'ip'=>$ip,'country'=>$ip_info['country'],'time'=>$time]);
    }
    
   /**
    * 当客户端发来消息时触发
    * @param int $client_id 连接id
    * @param mixed $message 具体消息
    */
   public static function onMessage($client_id, $message)
   {
        //验证发送数据发送端（客户端、服务端），发送类型
        $msg=json_decode($message,true);
        if(!isset($msg['user'])||!isset($msg['type'])) GateWay::closeClient($client_id);

        switch ($msg['user']) {
          case 'ping':
                //心跳检测 1*30秒内没有心跳回复，认为客户离线
                break;
          case 'client':
            //客户通讯数据
            if($msg['type']!='firstClient'&&(!isset($msg['pid'])||($msg['type']!='reClient' && $msg['pid']==null))){
                \GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'pid not found'],-3);
                GateWay::closeClient($client_id);
            }
            $ip_info=$_SESSION[$client_id]['ip_info'];
            if(isset($msg['lan'])){
                $ip_info['lan']=$data['lan'];
                $_SESSION[$client_id]['ip_info']['lan']=$data['lan'];
            }
            $get_message_res = \GatewayWorker\channel\onMessageClient::get_message($client_id,$msg,$ip_info,self::$db);
            if($get_message_res === false) {
                \GatewayWorker\channel\sendSDK::msgToClient($client_id, ['type' => 'clientSend', 'err' => 'Method does not exist'], -9);
            }
            return;
          case 'admin':
              //客服通讯数据
              $get_message_res = \GatewayWorker\channel\onMessageAdmin::get_message($client_id,$msg,self::$db);
              if($get_message_res === false){
                  \GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'Method does not exist'],-9);
              }
              return;
          default:
            # code...
            break;
        }
   }
   
   /**
    * 当用户断开连接时触发
    * @param int $client_id 连接id
    */
   public static function onClose($client_id)
   {
       var_dump('bye bye');
       $pid = $_SESSION['pid'];
       if(strlen($pid)>10){
           //用户下线
           $row_count =self::$db->update('talk_user')->cols(array('talk_user_status'))->where('talk_user_pid="'.$pid.'"')->bindValue('talk_user_status', 0)->query();
           //通知客服，用户下线
           if(isset($_SESSION[$client_id]['ip_info']['country']) && $_SESSION[$client_id]['ip_info']['country'] && $row_count){
               //用户离线
               $data = [
                   "type"  => "status",
                   "uid"   => $pid,
                   "status"=> 'offline'
               ];
               $lan = \GatewayWorker\channel\sendSDK::getlanfromcountry($_SESSION[$client_id]['ip_info']['country']);

               $admin_talk_all = self::$db->select('*')->from('admin_talk')->where("admin_talk_pro='".$lan."'")->orwhere("admin_talk_pro='0'")->query();
               if(!empty($admin_talk_all)){
                   foreach ($admin_talk_all as $admin_talk_user){
                       if(Gateway::isUidOnline($admin_talk_user['admin_primary_id'])){
                           //用户上线
                           \GatewayWorker\channel\sendSDK::msgToAdminByPid($admin_talk_user['admin_primary_id'],$data);
                       }
                   }
               }
           }
           unset($row_count);
       }else{
           //客服下线
           self::$db->update('admin_talk')->cols(array('admin_talk_status'))->where('admin_primary_id="'.$pid.'"')->bindValue('admin_talk_status', 0)->query();
       }
   }
  public static function http_listen($con)
  {
    var_dump('on message');
  }
}
