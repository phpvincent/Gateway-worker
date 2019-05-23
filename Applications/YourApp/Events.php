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
        self::$db = new \Workerman\MySQL\Connection('127.0.0.1', '3306', 'homestead', 'secret', 'homestead');
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
//        if(strstr($ip, '192.168.1')!==false) return;
        //$ip='39.10.194.98';
        $time = date("Y-m-d H:i:s");
        //得到地址信息
        $IpGet=new GatewayWorker\channel\getIpInfo\IpGet($ip);
        $ip_info=$IpGet->getIpMsg();
        $china_country = $IpGet->getCountry();
        $ip_info['country']=$china_country != "局域网" ? $china_country : '台湾省';
        if(!array_key_exists($ip_info['country'], GatewayWorker\channel\sendSDK::$lan_arr)){
            GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'connet fail,country not allowed','client_id'=>$client_id,'ip'=>$ip,'country'=>$ip_info['country'],'time'=>$time]);
            Gateway::closeClient($client_id);
            return;
        }else{
          $ip_info['lan']=GatewayWorker\channel\sendSDK::$lan_arr[$ip_info['country']];
        }
        $time=date('Y-m-d H:i:S',time());
        //记录全局信息
        $_SESSION[$client_id]['ip_info']=$ip_info;
        $_SESSION[$client_id]['first_time']=$time;
        // 向当前client_id发送数据 
        GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'connet_success','client_id'=>$client_id,'ip'=>$ip,'country'=>$ip_info['country'],'time'=>$time]);
        
//        if($ip_info!=false && $ip_info!=null && $ip_info!=[]){
            // 通知服务端
//            GatewayWorker\channel\sendSDK::msgToAdmin(1,GatewayWorker\channel\sendSDK::getlanfromcountry($ip_info['country']),['type'=>'connet_notice','client_id'=>$client_id,'ip'=>$ip,'country'=>$ip_info['country'],'time'=>$time]);
//        }
       
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

        //验证发送数据发送端（客户端、服务端），发送类型
        if(!isset($msg['user'])||!isset($msg['type'])) GateWay::closeClient($client_id);

        switch ($msg['user']) {
          case 'client':
            //判断数据验证（第一次发消息type=firstClient 无需pid，不是第一次必须携带pid）
            if($msg['type']!='firstClient'&&(!isset($msg['pid'])||($msg['type']!='reClient' && $msg['pid']==null))){
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
                $_SESSION['pid'] = $pid;
                //判断用户是否存在
                $talk_user = self::$db->select('talk_user_pid')->from('talk_user')->where("talk_user_pid= '$pid' ")->row();
                if(!$talk_user){
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
                    if($user_id){
                        GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'first_client','pid'=>$pid]);
                    }else{
                        GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
                    }
                }
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
                    Gateway::joinGroup($client_id, 'client');
                    $_SESSION['pid'] = $msg['pid'];
                    $time = date('Y-m-d H:i:s');
                    //更新用户线上状态
                     $row_count =self::$db->update('talk_user')->cols(array('talk_user_last_time'))->where('talk_user_pid="'.$msg['pid'].'"')->bindValue('talk_user_last_time', $time)->query();
                     if($row_count){
                         //用户上线
                         $data = [
                             "type"  => "friendStatus",
                             "uid"   => $msg['pid'],
                             "status"=> 'online'
                         ];
                         //客服好友上线
                         GatewayWorker\channel\sendSDK::msgToAdmin(1,GatewayWorker\channel\sendSDK::getlanfromcountry($ip_info['lan']),$data);
                     }
                    return;
                 }else{
                     GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'ip can not be read'],-6);
                     return;
                 }
           }elseif(isset($msg['type'])&&$msg['type']=='clientSend'){
                //判断用户是否第一次通讯，如果第一次通讯，需先添加好友

                if(isset($ip_info['country'])&&$ip_info['country']!=null&&$ip_info['country']!='XX'){
                    $talk_user = self::$db->select('talk_user_pid')->from('talk_user')->where("talk_user_pid=".$msg['pid']."'")->row();
                    if(!$talk_user){
                        //添加好友
                        $msg['sendUser'] = "new_user";
                    }else{
                        $msg['sendUser'] = "old_user";
                    }
                    GatewayWorker\channel\sendSDK::msgToAdmin(1,GatewayWorker\channel\sendSDK::getlanfromcountry($ip_info['lan']),$msg);
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
                 //转发给对应语种服务端
                 $code=GatewayWorker\channel\sendSDK::resendToAdmin($msg['country'],$msg['msg']);var_dump($client_id);
                 if($code==false){
                  GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,country not allow'],-4);
                 }
              }else{
                if(!isset($msg['msg'])) GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'msg send fail,msg not found'],-5);
                GatewayWorker\channel\sendSDK::msgToClient('all',$msg['msg']);
                //转发给所有服务端
                 $code=GatewayWorker\channel\sendSDK::resendToAdmin('all',$msg['msg']);
                 if($code==false){
                  GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,country not allow'],-4);
                 }
              }
              return;
            }else{
              GatewayWorker\channel\sendSDK::msgToClientByPid($msg['touser'],$msg['msg']);
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
       $pid = $_SESSION['pid'];

       //用户下线
       $row_count =self::$db->update('talk_user')->cols(array('talk_user_status'))->where('talk_user_pid="'.$pid.'"')->bindValue('talk_user_status', 1)->query();

//       $admin_info = self::$db->query("update admin_info set admin_info_status=1 where admin_id='". $pid ."'");
       //通知客服，用户下线
       if(isset($_SESSION[$client_id]['ip_info']['country']) && $_SESSION[$client_id]['ip_info']['country'] && $row_count){
           //用户上线
           $data = [
               "type"  => "friendStatus",
               "uid"   => $pid,
               "status"=> 'offline'
           ];
           static::msgToAdmin(1,$_SESSION[$client_id]['ip_info']['country'],$data);
       }

       // 通知服务端
       GatewayWorker\channel\sendSDK::msgToAdmin(1,'admin',['msg'=>"$client_id($pid) logout",'type'=>'clientClose','client_id'=>$client_id,'pid'=>$pid]);
   }
  public static function http_listen($con)
  {
    var_dump('on message');
  }
}
