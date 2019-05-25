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
        global $config;
        //self::$db = new \Workerman\MySQL\Connection('172.31.37.203', '3306', 'admin', 'ydzsadmin', 'obj');
        self::$db = new \Workerman\MySQL\Connection('127.0.0.1', '3306', 'homestead', 'secret', 'obj');
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
        $ip_info['country']=$IpGet->getCountry();
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
        //验证发送数据发送端（客户端、服务端），发送类型
        $msg=json_decode($message,true);
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
                Gateway::joinGroup($client_id, 'client_'.$ip_info['lan']);var_dump('client join:'.'client_'.$ip_info['lan']);
                $country = \GatewayWorker\channel\sendSDK::getcountryandalias($ip_info['country']);
                Gateway::joinGroup($client_id, 'client_'.$country);
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
//              //初次链接，分配pid
//              $pid='c'.time().GatewayWorker\channel\sendSDK::getlanid($client_id).rand(10000,99999);
//              Gateway::bindUid($client_id,$pid);
//              //$ip_info=$_SESSION[$client_id]['ip_info'];
//              Gateway::joinGroup($client_id, 'client_'.$ip_info['lan']);var_dump('client join:'.'client_'.$ip_info['lan']);
//              Gateway::joinGroup($client_id, 'client');
//              $user_id=self::$db->insert('talk_user')->cols([
//                'talk_user_lan'=>$ip_info['lan'],
//                'talk_user_status'=>1,
//                'talk_user_goods'=>isset($msg['goods_id']) ? $msg['goods_id'] : null,
//                'talk_user_time'=>date('Y-m-d H:i:s',time()),
//                'talk_user_is_shop'=>0,
//                'talk_user_last_time'=>date('Y-m-d H:i:s',time()),
//                'talk_user_pid'=>$pid,
//                'talk_user_country'=>$ip_info['country']
//              ])->query();
//              GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'first_client','pid'=>$pid]);
//              return;
//            }elseif(isset($msg['type'])&&$msg['type']=='reClient'){
//                 if(isset($ip_info['country'])&&$ip_info['country']!=null&&$ip_info['country']!='XX'){
//                    if(!isset($msg['pid'])){
//                      //var_dump($msg);
//                      GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'pid not found'],-3);
//                      return;
//                    }
//                    Gateway::bindUid($client_id,$msg['pid']);
//                    Gateway::joinGroup($client_id, 'client_'.$ip_info['lan']);
//                    Gateway::joinGroup($client_id, 'client_'.$ip_info['country']);
//                    Gateway::joinGroup($client_id, 'client');
//                    $row_count=self::$db->update('talk_user')->cols(['talk_user_last_time'=>date('Y-m-d H:i:s',time()),'talk_user_status'=>0,'talk_user_country'=>$ip_info['country'],'talk_user_lan',$ip_info['lan']])->where('talk_user_pid='.$msg['pid'])->query();
//                    if($row_count<=0){
//                      echo 'err:reClient data update query false.pid:'.$msg['pid'];
                    }
                }
                return;
            }elseif(isset($msg['type'])&&$msg['type']=='reClient'){
                if(!isset($ip_info['country']) || (isset($ip_info['country'])&&$ip_info['country']==null) || (isset($ip_info['country'])&&$ip_info['country']!='XX')){
                    GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'country can not be read'],-6);
                    return;
                }
                if(!isset($msg['pid'])){
                     GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'pid not found'],-3);
                     return;
                }
                Gateway::bindUid($client_id,$msg['pid']);
                Gateway::joinGroup($client_id, 'client_'.$ip_info['lan']);
                $country = \GatewayWorker\channel\sendSDK::getcountryandalias($ip_info['country']);
                Gateway::joinGroup($client_id, 'client_'.$country);
                Gateway::joinGroup($client_id, 'client');
                $_SESSION['pid'] = $msg['pid'];
                $time = date('Y-m-d H:i:s');
                //更新用户线上状态
                 $row_count =self::$db->update('talk_user')->cols(['talk_user_last_time'=>$time,"talk_user_status"=>1])->where('talk_user_pid="'.$msg['pid'].'"')->query();
                 if($row_count){
                     //用户上线
                     $data = [
                         "type"  => "friendStatus",
                         "uid"   => $msg['pid'],
                         "status"=> 'online'
                     ];
                     if(!empty(GatewayWorker\channel\sendSDK::getlanfromcountry($ip_info['lan']))){
                         //客服好友上线
                         GatewayWorker\channel\sendSDK::msgToAdmin(1,GatewayWorker\channel\sendSDK::getlanfromcountry($ip_info['lan']),$data);
                     }
                 }
                 unset($row_count);
                return;
           }elseif(isset($msg['type'])&&$msg['type']=='clientSend'){
                //判断用户是否第一次通讯，如果第一次通讯，需先添加好友
                if(!isset($ip_info['country']) || (isset($ip_info['country'])&&$ip_info['country']==null) || (isset($ip_info['country'])&&$ip_info['country']!='XX')){
                    GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'country can not be read'],-6);
                    return;
                }
                $talk_user = self::$db->select('talk_user_name')->from('talk_user')->where("talk_user_pid='".$msg['pid']."'")->row();

                $data = [
                    'username'=> $talk_user->talk_user_name,
                    'avatar'=> "/admin/userImages/13.jpg",
                    'id'=> $msg['pid'],
                    'type'=> "friend",
                    'content'=> $msg['msg'],
                    'cid'=> 0,
                    'mine'=> false,
                    'fromid'=> $msg['pid'],
                    'timestamp'=> time()*1000,
                ];
                $talk_msg = self::$db->select('talk_msg_from_id')->from('talk_msg')->where("talk_msg_from_id='".$msg['pid']."'")->orwhere("talk_msg_to_id='".$msg['pid']."'")->row();
                //判断是否为新用户（没有聊天记录为新用户，有聊天记录为老用户）
                if(!$talk_msg){
                    //添加好友
                    $data['sendUser'] = "new_user";
                }else{
                    $data['sendUser'] = "old_user";
                }
                $talk_msg_data = [
                    'talk_msg_from_id'=>$msg['pid'],
                    'talk_msg_to_id'=>'',
                    'talk_msg_type'=>1,
                    'talk_msg_msg'=>$msg['msg'],
                    'talk_msg_is_read'=>1, //0未读 1已读
                    'talk_msg_time'=>date("Y-m-d H:i:s")
                ];
                if(!empty(GatewayWorker\channel\sendSDK::getlanfromcountry($ip_info['lan']))){
                    //有客服在线
                    //聊天记录存储 已读
                    $insert_id = self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
                    if($insert_id){
                        $data['cid'] = $insert_id;
                        GatewayWorker\channel\sendSDK::msgToAdmin(1,GatewayWorker\channel\sendSDK::getlanfromcountry($ip_info['lan']),$msg);
                    }else{
                        GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
                        return;
                    }
                }else{
                    //客服不在线
                    $talk_msg_data['talk_msg_is_read'] = 0;
                    $insert_id = self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
                    if(!$insert_id){
                        GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
                        return;
                    }
                }
                self::$db->update('talk_user')->cols(['talk_user_last_time'=>date('Y-m-d H:i:s',time())])->where("talk_user_pid='".$msg['pid']."'")->query();
           }
           break;
          case 'admin':
            //身份验证
            if(isset($msg['type']) && $msg['type']=='auth'){
                $admin=self::$db->select('*')->from('admin')->where('admin_name="'.$msg['admin_name'].'"')->offset(0)->limit(1)->query()[0];
                if($admin==false||password_verify($msg['admin_password'], $admin['password'])==false){
                    GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['err'=>'auth unallow','type'=>'auth'],-1);
                    return;
                }else{
                    $_SESSION[$client_id]['auth']=$admin;
                    Gateway::joinGroup($client_id,$msg['language']);var_dump($msg['language']); //TODO 需要完善
                    Gateway::joinGroup($client_id,'admin');
                    Gateway::bindUid($client_id,$admin['admin_id']);
                    $_SESSION['pid'] = $admin['admin_id'];
                    self::$db->update('admin_talk')->cols(['admin_talk_status'=>1,'admin_talk_last_time'=>date('Y-m-d H:i:s',time())])->where('admin_primary_id='.$admin['admin_id'])->query();
                    //通知其它客服，客服上线
//                    //客服上线
//                    $data = [
//                        "type"  => "friendStatus",
//                        "uid"   => $admin['admin_id'],
//                        "status"=> 'online'
//                    ];
                    //告诉自己，通讯成功
                    GatewayWorker\channel\sendSDK::msgToAdminByPid($admin['admin_id'],['pid'=>$admin['admin_id'],'type'=>'auth'],1);


                    //查看是否有未读消息，如果有，直接推送
                    $talk_msg_infos = self::$db->select('*')->from('talk_msg')->where('talk_msg_to_id="'.$admin['admin_id'].'"')->where('talk_msg_is_read=0')->query();
                    if(!empty($talk_msg_infos)){
                        foreach ($talk_msg_infos as $talk_msg_info){
                            if(strlen($talk_msg_info['talk_msg_to_id'])>10){
                                $talk_user = self::$db->select('talk_user_name')->from('talk_user')->where("talk_user_id='".$talk_msg_info['talk_msg_to_id'].'"')->row();
                                if(!$talk_user){
                                    GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
                                    return;
                                }
                                $username = $talk_user['talk_user_name'];
                                $avatar = "/admin/userImages/13.jpg";
                            }else{
                                $admin_talk = self::$db->select('admin_talk_name,admin_talk_img')->from('admin_talk')->where("admin_primary_id='".$talk_msg_info['talk_msg_to_id']."'")->row();
                                if($admin_talk) {
                                    $username = $admin_talk['admin_talk_name'];
                                    $avatar = $admin_talk['admin_talk_img'] ? $admin_talk['admin_talk_img'] : "/admin/userImages/13.jpg";
                                }else{
                                    $admin_info = self::$db->select('admin_show_name')->from('admin')->where("admin_id='".$talk_msg_info['talk_msg_to_id']."'")->row();
                                    if(!$admin_info){
                                        GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
                                        return;
                                    }
                                    $username = $admin_info['admin_show_name'];
                                    $avatar = "/admin/userImages/13.jpg";
                                }
                            }
                            $data = [
                                'username'=> $username,
                                'avatar'=> $avatar,
                                'id'=> $talk_msg_info['talk_msg_to_id'],
                                'type'=> "friend",
                                'content'=> $talk_msg_info['talk_msg_msg'],
                                'cid'=> $talk_msg_info['talk_msg_id'],
                                'mine'=> $talk_msg_info['talk_msg_to_id'] == $admin['admin_id'] ? true : false,
                                'fromid'=> $talk_msg_info['talk_msg_to_id'],
                                'timestamp'=> time()*1000,
                            ];
                            GatewayWorker\channel\sendSDK::msgToAdminByPid($admin['admin_id'],$data);
                            //修改消息为已读
                            self::$db->update('talk_msg')->cols(array('talk_msg_is_read'=>'1'))->where("talk_msg_id='".$talk_msg_info['talk_msg_id']."'")->query();
                        }
                    }

                    //告诉其它客服上线
//                    if(!empty(GatewayWorker\channel\sendSDK::getlanfromcountry($msg['language']))){
//                        //客服好友上线
//                        GatewayWorker\channel\sendSDK::msgToAdmin(1,GatewayWorker\channel\sendSDK::getlanfromcountry($msg['language']),$data);
//                    }
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
              if(isset($msg['country'])){ //发给用相同语言的客户以及客服
                    if(!isset($msg['msg'])) GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'msg send fail,msg not found'],-5);
                    $msg_arr=[];
                    $msg_arr['type']='adminSend';
                    $msg_arr['msg']=$msg['msg'];
                    $country = \GatewayWorker\channel\sendSDK::getcountryandalias($msg['country']);
                    GatewayWorker\channel\sendSDK::msgToClient($country,$msg_arr);
                    $client_ids=Gateway::getUidListByGroup('client_'.$country);
                    if(!empty($client_ids)){
                          $str = '';
                          $talk_msg_from_id = $_SESSION[$client_id]['auth']['admin_id'];
                          $talk_msg_time = date('Y-m-d H:i:s');
                          $talk_msg_msg = $msg['msg'];
                          foreach($client_ids as $v) {
                              $str .= "('$talk_msg_from_id','$v',1,'$talk_msg_time','$talk_msg_msg',1),";
                          }
                          $str = trim($str,',');
                          self::$db->query('INSERT INTO `talk_msg` (`talk_msg_from_id`,`talk_msg_to_id`,`talk_msg_type`,`talk_msg_time`,`talk_msg_msg`,`talk_msg_is_read`) VALUE '.$str);
                    }else{
                        GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,client not online'],-4);
                    }

                     //转发给对应语种服务端
                     $code=GatewayWorker\channel\sendSDK::resendToAdmin($msg['country'],$msg['msg']);var_dump($client_id);
                     if($code==false){
                            GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,country not allow'],-4);
                     }
              }else{ //广播
                    if(!isset($msg['msg'])){
                        GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'msg send fail,msg not found'],-5);
                        return;
                    }
                    $uidList = Gateway::getAllUidList();
                    if(!empty($uidList)){
                          $str = '';
                          $talk_msg_from_id = $_SESSION[$client_id]['auth']['admin_id'];
                          $talk_msg_time = date('Y-m-d H:i:s');
                          $talk_msg_msg = $msg['msg'];
                          foreach($uidList as $v) {
                              $str .= "('$talk_msg_from_id','$v',1,'$talk_msg_time','$talk_msg_msg',1),";
                          }
                          $str = trim($str,',');
                          self::$db->query('INSERT INTO `talk_msg` (`talk_msg_from_id`,`talk_msg_to_id`,`talk_msg_type`,`talk_msg_time`,`talk_msg_msg`,`talk_msg_is_read`) VALUE '.$str);
                    }

                    GatewayWorker\channel\sendSDK::msgToClient('all',$msg['msg']); //发送所以在线人员
                    //转发给所有服务端
//                     $code=GatewayWorker\channel\sendSDK::resendToAdmin('all',$msg['msg']);
//                     if($code==false){
//                            GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,country not allow'],-4);
//                     }
                  }
                  return;
            }else{ //一对一 私聊
                $talk_msg_data = [
                    'talk_msg_from_id'=>$_SESSION[$client_id]['auth']['admin_id'],
                    'talk_msg_to_id'=>$msg['touser'],
                    'talk_msg_type'=>1,
                    'talk_msg_time'=>date('Y-m-d H:i:s',time()),
                    'talk_msg_msg'=>$msg['msg'],
                    'talk_msg_is_read'=>1
                ];
                //好友消息
                $data = \GatewayWorker\channel\sendSDK::msg_template($msg);
                if(Gateway::isUidOnline($msg['touser'])){  //在线
                      //1. 发给客户
                      self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
//                      GatewayWorker\channel\sendSDK::msgToClientByPid($msg['touser'],$msg['msg']);
                      GatewayWorker\channel\sendSDK::msgToClientByPid($msg['touser'],$data);
                      if(isset($msg['language'])){
                          //2.发送相应的客服(保持数据同步)
                          GatewayWorker\channel\sendSDK::resendToAdmin($msg['language'],$talk_msg_data);
                      }
                      GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSendResponse','msg'=>'success','account'=>$msg['account']],0);
                }else{ //不在线
                      $talk_msg_data['talk_msg_is_read'] = 0;
                      self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
                      GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSendResponse','msg'=>'failed','account'=>$msg['account']],0);
                }
              
            }
            break;
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
       $pid = $_SESSION['pid'];

       if(strlen($pid)>10){
           //用户下线
           $row_count =self::$db->update('talk_user')->cols(array('talk_user_status'))->where('talk_user_pid="'.$pid.'"')->bindValue('talk_user_status', 0)->query();

           //通知客服，用户下线
           if(isset($_SESSION[$client_id]['ip_info']['country']) && $_SESSION[$client_id]['ip_info']['country'] && $row_count){
               //用户离线
               $data = [
                   "type"  => "friendStatus",
                   "uid"   => $pid,
                   "status"=> 'offline'
               ];
               static::msgToAdmin(1,$_SESSION[$client_id]['ip_info']['country'],$data);
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
