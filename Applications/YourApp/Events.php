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
        self::$db = new \Workerman\MySQL\Connection('127.0.0.1', '3306', 'homestead', 'secret', 'obj');
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
        if(strstr($ip, '192.168.1')!==false) return;
        //$ip='39.10.194.98';
        //得到地址信息
        $IpGet=new GatewayWorker\channel\getIpInfo\IpGet($ip);
        $ip_info=$IpGet->getIpMsg();
        //unset($IpGet);
        $ip_info['country']=$IpGet->getCountry();
        if(!array_key_exists($ip['country'], GatewayWorker\channel\sendSDK::$lan_arr)){
          GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'connet fail,country not allowed','client_id'=>$client_id,'ip'=>$ip,'country'=>$ip_info['country'],'time'=>$time]);
          Gateway::closeClient($client_id);
          return;
        }else{
          $ip_info['lan']=GatewayWorker\channel\sendSDK::$lan_arr[$ip_info['country']];
        }
        $time=date('Y-m-d H:i:s',time());

        //记录全局信息
        $_SESSION[$client_id]['ip_info']=$ip_info;
        $_SESSION[$client_id]['first_time']=$time;
        // 向当前client_id发送数据 
        GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'connet_success','client_id'=>$client_id,'ip'=>$ip,'country'=>$ip_info['country'],'time'=>$time]);
        
        if($ip_info!=false && $ip_info!=null && $ip_info!=[]){
          // 通知服务端
          GatewayWorker\channel\sendSDK::msgToAdmin(1,GatewayWorker\channel\sendSDK::getlanfromcountry($ip_info['country']),['type'=>'connet_notice','client_id'=>$client_id,'ip'=>$ip,'country'=>$ip_info['country'],'time'=>$time]);
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
        $msg=json_decode($message,true);//var_dump($msg);
        if(!isset($msg['user'])||!isset($msg['type'])) GateWay::closeClient($client_id);
        switch ($msg['user']) {
          case 'client':
           if($msg['type']!='firstClient'&&(!isset($msg['pid'])||$msg['pid']==null)){
            GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'pid not found'],-3);
            GateWay::closeClient($client_id);
           }
           $ip_info=$_SESSION[$client_id]['ip_info'];
            if(isset($msg['type'])&&$msg['type']=='firstClient'){
              //初次链接，分配pid
              $pid='c'.time().GatewayWorker\channel\sendSDK::getlanid($client_id).rand(10000,99999);
              Gateway::bindUid($client_id,$pid);
              //$ip_info=$_SESSION[$client_id]['ip_info'];
              Gateway::joinGroup($client_id, 'client_'.$ip_info['lan']);var_dump('client join:'.'client_'.$ip_info['lan']);
              Gateway::joinGroup($client_id, 'client');
              $user_id=self::$db->insert('talk_user')->cols([
                'talk_user_lan'=>$ip_info['lan'],
                'talk_user_status'=>1,
                'talk_user_goods'=>$msg['goods_id'],
                'talk_user_time'=>date('Y-m-d H:i:s',time()),
                'talk_user_is_shop'=>0,
                'talk_user_last_time'=>date('Y-m-d H:i:s',time()),
                'talk_user_pid'=>$pid,
                'talk_user_country'=>$ip_info['country']
              ])->query();
              GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'first_client','pid'=>$pid]);
              return;
            }elseif(isset($msg['type'])&&$msg['type']=='reClient'){
                 if(isset($ip_info['country'])&&$ip_info['country']!=null&&$ip_info['country']!='XX'){
                    if(!isset($msg['pid'])){
                      //var_dump($msg);
                      GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'pid not found'],-3);
                      return;
                    }   
                    Gateway::bindUid($client_id,$msg['pid']);
                    Gateway::joinGroup($client_id, 'client_'.$ip_info['lan']);
                    Gateway::joinGroup($client_id, 'client_'.$ip_info['country']);
                    Gateway::joinGroup($client_id, 'client');
                    $row_count=self::$db->update('talk_user')->cols(['talk_user_last_time'=>date('Y-m-d H:i:s',time()),'talk_user_status'=>0,'talk_user_country'=>$ip_info['country'],'talk_user_lan',$ip_info['lan']])->where('talk_user_pid='.$msg['pid'])->query();
                    if($row_count<=0){
                      echo 'err:reClient data update query false.pid:'.$msg['pid'];
                    }
                    unset($row_count);
                    //$msg['type']='clientSend';
                    //GatewayWorker\channel\sendSDK::msgToAdmin(1,$ip_info['country'],$msg);
                    return;
                  }else{
                     GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'ip can not be read'],-6);
                     return;
                  }
            }elseif(isset($msg['type'])&&$msg['type']=='clientSend'){
                if(isset($ip_info['country'])&&$ip_info['country']!=null&&$ip_info['country']!='XX'){
                    $insert_data=[
                      'talk_msg_from_id'=>Gateway::getUidByClientId($client_id),
                      'talk_msg_type'=>1,
                      'talk_msg_time'=>date('Y-m-d H:i:s',time()),
                      'talk_msg_msg'=>$msg['msg'],
                      //'talk_msg_is_read'=>1
                    ];
                    if(Gateway::getUidCountByGroup($ip_info['lan'])<=0){
                      $insert_data['talk_msg_is_read']=0;
                      GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'no admin online'],-6);
                    }else{
                      $insert_data['talk_msg_is_read']=1;
                    }
/*                  $msg['type']='clientSend';
*/                  GatewayWorker\channel\sendSDK::msgToAdmin(1,GatewayWorker\channel\sendSDK::getlanfromcountry($ip_info['lan']),$msg);
                    self::$db->insert('talk_msg')->cols($insert_data)->query();
                    self::$db->update('talk_user')->cols(['talk_user_last_time'=>date('Y-m-d H:i:s',time())])->where('talk_user_pid='.Gateway::getUidByClientId($client_id))->query();
                }else{
                   GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'ip can not be read'],-6);
                   return;
                }
            }
            break;
          case 'admin':
          //var_dump($msg);
          //身份验证
            if($msg['type']=='auth'){
              $admin=self::$db->select('*')->from('admin')->where('admin_name="'.$msg['admin_name'].'"')->offset(0)->limit(1)->query()[0];
              if($admin==false||password_verify($msg['admin_password'], $admin['password'])==false){
                GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['err'=>'auth unallow','type'=>'auth'],-1);
                return;
              }else{
                $_SESSION[$client_id]['auth']=$admin;
                Gateway::joinGroup($client_id,$msg['language']);var_dump($msg['language']);
                Gateway::joinGroup($client_id,'admin');
                Gateway::bindUid($client_id,$admin['admin_id']);
                self::$db->update('admin_talk')->cols(['admin_talk_status'=>1,'admin_talk_last_time'=>date('Y-m-d H:i:s',time())])->where('admin_primary_id='.$admin['admin_id'])->query();
                GatewayWorker\channel\sendSDK::msgToAdminByPid($admin['admin_id'],['pid'=>$admin['admin_id'],'type'=>'auth'],1);
                return;
              }
            }
          //数据推送
            if(!isset($_SESSION[$client_id])||!isset($_SESSION[$client_id]['auth'])){
              //验证身份
              GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['err'=>'auth unallow','type'=>'adminSend'],-2);
              GateWay::closeClient($client_id);
              return;
            }
            //推送数据
            if(!isset($msg['touser'])){
              GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['err'=>'touser not found','type'=>'adminSend'],-3);
              return;
            }elseif($msg['touser']=='all'){
              //推送给所有客户端
              if(isset($msg['country'])){
                 if(!isset($msg['msg'])) GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'msg send fail,msg not found'],-5);
                $msg_arr=[];
                $msg_arr['type']='adminSend';
                $msg_arr['msg']=$msg['msg'];
                GatewayWorker\channel\sendSDK::msgToClient($msg['country'],$msg_arr);
                $client_ids=Gateway::getUidListByGroup('client_'.$msg['country']);
                foreach($client_ids as $v)
                {
                  self::$db->insert('talk_msg')->cols([
                    'talk_msg_from_id'=>$_SESSION[$client_id]['auth']['admin_id'],
                    'talk_msg_to_id'=>$v,
                    'talk_msg_type'=>0,
                    'talk_msg_time'=>date('Y-m-d H:i:s',time()),
                    'talk_msg_msg'=>$msg['msg'],
                    'talk_msg_is_read'=>1
                  ])->query();
                }
                 //转发给对应语种服务端
                 $code=GatewayWorker\channel\sendSDK::resendToAdmin($msg['country'],$msg['msg']);var_dump($client_id);
                 if($code==false){
                  GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,country not allow'],-4);
                 }
              }else{
                if(!isset($msg['msg'])){
                  GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'msg send fail,msg not found'],-5);
                  return;
                } 
                GatewayWorker\channel\sendSDK::msgToClient('all',$msg['msg']);
                foreach(Gateway::getAllUidList() as $v){
                  self::$db->insert('talk_msg')->cols([
                    'talk_msg_from_id'=>$_SESSION[$client_id]['auth']['admin_id'],
                    'talk_msg_to_id'=>$v,
                    'talk_msg_type'=>0,
                    'talk_msg_time'=>date('Y-m-d H:i:s',time()),
                    'talk_msg_msg'=>$msg['msg'],
                    'talk_msg_is_read'=>1
                  ])->query();
                }
                //转发给所有服务端
                 $code=GatewayWorker\channel\sendSDK::resendToAdmin('all',$msg['msg']);
                 if($code==false){
                  GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,country not allow'],-4);
                 }
              }
              return;
            }else{
              if(Gateway::isUidOnline($msg['touser'])){
                GatewayWorker\channel\sendSDK::msgToClientByPid($msg['touser'],$msg['msg']);
                self::$db->insert('talk_msg')->cols([
                  'talk_msg_from_id'=>$_SESSION[$client_id]['auth']['admin_id'],
                  'talk_msg_to_id'=>$msg['touser'],
                  'talk_msg_type'=>0,
                  'talk_msg_time'=>date('Y-m-d H:i:s',time()),
                  'talk_msg_msg'=>$msg['msg'],
                  'talk_msg_is_read'=>1
                ])->query();
                  GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSendResponse','msg'=>'success','account'=>$msg['account']],0);
              }else{
                  GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSendResponse','msg'=>'failed','account'=>$msg['account']],0);
              }
              
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
      if(!is_numeric($pid)){
        self::$db->update('admin_talk')->cols(['admin_talk_status'=>0])->where('admin_primary_id='.$pid)->query();
      }else{
        self::$db->update('talk_user')->cols(['talk_user_status'=>0])->where('talk_user_pid='.$pid)->query();
      }
        // 通知服务端
        GatewayWorker\channel\sendSDK::msgToAdmin(1,'admin',['msg'=>"$client_id($pid) logout",'type'=>'clientClose','client_id'=>$client_id,'pid'=>$pid]);
   }
  public static function http_listen($con)
  {
    var_dump('on message');
  }
}
