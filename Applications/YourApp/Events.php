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
        //  self::$db = new \Workerman\MySQL\Connection('127.0.0.1', '3306', 'root', 'root', 'obj');
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
//        $ip='39.10.194.98';
        //得到地址信息
        $IpGet=new GatewayWorker\channel\getIpInfo\IpGet($ip);
        $ip_info=$IpGet->getIpMsg();
        //unset($IpGet);
        $time=date('Y-m-d H:i:s',time());
        $ip_info['country']=$IpGet->getCountry() != "局域网" ? $IpGet->getCountry() : '台湾省';
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
                        'talk_user_name'=>$ip_info['country']."--".$msg['goods_id']."--".date('m-d H:i'),
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
                if(!isset($ip_info['country']) || (isset($ip_info['country'])&&$ip_info['country']==null) || (isset($ip_info['country'])&&$ip_info['country']=='XX')){
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
                     if(Gateway::getUidCountByGroup($ip_info['lan'])>0){
                         //客服好友上线
                         GatewayWorker\channel\sendSDK::msgToAdmin(1,$ip_info['lan'],$data);
                     }
                 }
                 unset($row_count);
                return;
           }elseif(isset($msg['type'])&&$msg['type']=='clientSend'){
                //判断用户是否第一次通讯，如果第一次通讯，需先添加好友
                if(!isset($ip_info['country']) || (isset($ip_info['country'])&&$ip_info['country']==null) || (isset($ip_info['country'])&&$ip_info['country']=='XX')){
                    GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'country can not be read'],-6);
                    return;
                }
                $talk_user = self::$db->select('*')->from('talk_user')->where("talk_user_pid='".$msg['pid']."'")->row();
                $data = [
                    'username'=> $talk_user['talk_user_name'],
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
                //获取客服列表
                $customers = self::$db->from('admin_talk')->select('*')->where('admin_talk_pro="'.$ip_info['lan'].'"')->orwhere("admin_talk_pro=0")->query();
                if(count($customers) <= 0){
                    GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'This function has not been activated yet.'],-8);
                    return;
                }

                //修改客户发送消息最后时间
                self::$db->update('talk_user')->cols(['talk_user_last_time'=>date('Y-m-d H:i:s',time())])->where("talk_user_pid='".$msg['pid']."'")->query();

                //如果有客服在线，标记已读，没有客服在线标记未读
                if(Gateway::getUidCountByGroup($ip_info['lan'])<=0){
                    $talk_msg_data['talk_msg_is_read'] = 0;
                }else{
                    $talk_msg_data['talk_msg_is_read'] = 1;
                }

                //存储消息记录，发送在线用户
                foreach ($customers as $customer){
                    $pid = $customer['admin_primary_id'];
                    $talk_msg_data['talk_msg_to_id'] = $pid;
                    if($talk_msg_data['talk_msg_is_read'] === 1 && !Gateway::isUidOnline($pid)){
                        $talk_msg_data['talk_msg_is_read'] = 2;
                    }
                    $insert_id = self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
                    if(!$insert_id){
                        GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
                        return;
                    }
                    $data['cid'] = $insert_id;
                    if(Gateway::isUidOnline($pid) && $pid != $msg['pid']){
                        \GatewayWorker\channel\sendSDK::msgToAdminByPid($pid,$data);
                    }
                }
                return;
//                if(Gateway::getUidCountByGroup($ip_info['lan'])<=0){
//                    //客服不在线
//                    $talk_msg_data['talk_msg_is_read'] = 0;
//                    $insert_id = self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
//                    if(!$insert_id){
//                        GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
//                        return;
//                    }
//                    return;
//                }
//
//                //有客服在线
//                //聊天记录存储 已读
//                $insert_id = self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
//                if($insert_id){
//                    $data['cid'] = $insert_id;
//                    GatewayWorker\channel\sendSDK::msgToAdmin(1,$ip_info['lan'],$msg);
//                }else{
//                    GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
//                    return;
//                }
//                return;
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

                    //客服同步数据
                    $talk_msg_infos1 = self::$db->select('*')->from('talk_msg')->where('talk_msg_from_id="'.$admin['admin_id'].'"')->orwhere('talk_msg_to_id="'.$admin['admin_id'].'"')->where('talk_msg_is_read=2')->query();
                    if(!empty($talk_msg_infos1)){
                        foreach ($talk_msg_infos1 as $talk_admin_msg){
                            //客服转发同步数据
                            if($talk_admin_msg['talk_msg_from_id'] == $admin['admin_id']){
                                $admin_talk = self::$db->select('admin_talk_name,admin_talk_img')->from('admin_talk')->where("admin_primary_id='".$admin['admin_id']."'")->row();
                                if($admin_talk){
                                    $username = $admin_talk['admin_talk_name'];
                                    $avatar = $admin_talk['admin_talk_img'] ? $admin_talk['admin_talk_img'] : "/admin/userImages/13.jpg";
                                }else{
                                    $username = $admin['admin_show_name'];
                                    $avatar = "/admin/userImages/13.jpg";
                                }
                                $data = \GatewayWorker\channel\sendSDK::msg_template($username,$avatar,$admin['admin_id'],$talk_admin_msg['talk_msg_msg'],$admin['admin_id'],$talk_admin_msg['talk_msg_id'],true);
                            }else{ //客户正常发送数据同步
                                $talk_user = self::$db->select('talk_user_name')->from('talk_user')->where("talk_user_id='".$talk_admin_msg['talk_msg_from_id']."'")->row();
                                if(!$talk_user){
                                    GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
                                    return;
                                }
                                $data = \GatewayWorker\channel\sendSDK::msg_template($talk_user['talk_user_name'],"/admin/userImages/13.jpg",$talk_admin_msg['talk_msg_from_id'],$talk_admin_msg['talk_msg_msg'],$talk_admin_msg['talk_msg_from_id'],$talk_admin_msg['talk_msg_id'],false);
                            }
                            GatewayWorker\channel\sendSDK::msgToAdminByPid($admin['admin_id'],$data);

                            //修改同步数据状态
                            self::$db->update('talk_msg')
                                ->where('talk_msg_id="'.$talk_admin_msg['talk_msg_id'].'"')
                                ->cols(array('talk_msg_is_read'))
                                ->bindValue('talk_msg_is_read', 1)
                                ->query();

                        }
                    }


                    //查看是否有未读消息，如果有，直接推送
                    $talk_msg_infos2 = self::$db->select('*')->from('talk_msg')->where('talk_msg_to_id="'.$admin['admin_id'].'"')->where('talk_msg_is_read=0')->query();
                    if(!empty($talk_msg_infos2)){
                        foreach ($talk_msg_infos2 as $talk_msg_info){
                                $talk_user = self::$db->select('talk_user_name')->from('talk_user')->where("talk_user_id='".$talk_msg_info['talk_msg_from_id']."'")->row();
                                if(!$talk_user){
                                    GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
                                    return;
                                }
                                $data = \GatewayWorker\channel\sendSDK::msg_template($talk_user['talk_user_name'],"/admin/userImages/13.jpg",$talk_admin_msg['talk_msg_from_id'],$talk_admin_msg['talk_msg_msg'],$talk_admin_msg['talk_msg_from_id'],$talk_admin_msg['talk_msg_id'],false);

                                //获取客服列表
                                if($msg['language'] == 0){
                                    $customers = self::$db->from('admin_talk')->select('*')->query();
                                }else{
                                    $customers = self::$db->from('admin_talk')->select('*')->where('admin_talk_pro="'.$msg['language'].'"')->orwhere("admin_talk_pro=0")->query();
                                }
                                if(count($customers) <= 0){
                                    GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'This function has not been activated yet.'],-8);
                                    return;
                                }
                                foreach ($customers as $itme){
                                    if($itme['admin_primary_id'] != $admin['admin_id']){
                                        //修改消息为待同步
                                        self::$db->update('talk_msg')
                                            ->cols(array('talk_msg_is_read'=>'2'))
                                            ->where("talk_msg_from_id='".$talk_msg_info['talk_msg_from_id']."'")
                                            ->where('talk_msg_to_id="'.$itme['admin_primary_id'].'"')
                                            ->where('talk_msg_is_read=0')
                                            ->query();
                                    }
                                }
                                GatewayWorker\channel\sendSDK::msgToAdminByPid($admin['admin_id'],$data);
                        }
                        self::$db->update('talk_msg')->cols(array('talk_msg_is_read'=>'1'))->where("talk_msg_to_id='".$admin['admin_id']."'")->where('talk_msg_is_read=0')->query();
                    }
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
                          $lan  = \GatewayWorker\channel\sendSDK::getlanfromcountry($msg['country']);
                          if($lan === false){
                              GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,country not allow'],-4);
                          }

                        //获取客服列表
                          $customers = self::$db->from('admin_talk')->select('*')->where('admin_talk_pro="'.$lan.'"')->orwhere("admin_talk_pro=0")->query();
                          if(count($customers) <= 0){
                                GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'This function has not been activated yet.'],-8);
                                return;
                          }
                          foreach ($customers as $customer){
                              $talk_msg_from_id = $customer['admin_primary_id'];
                              if(Gateway::isUidOnline($talk_msg_from_id)){
                                    $talk_msg_is_read = 1;
                              }else{
                                    $talk_msg_is_read = 2;
                              }
                              $talk_msg_time = date('Y-m-d H:i:s');
                              $talk_msg_msg = $msg['msg'];
                              foreach($client_ids as $v) {
                                  $str .= "('$talk_msg_from_id','$v',1,'$talk_msg_time','$talk_msg_msg',$talk_msg_is_read),";
                              }
                          }
                          $str = trim($str,',');
                          self::$db->query('INSERT INTO `talk_msg` (`talk_msg_from_id`,`talk_msg_to_id`,`talk_msg_type`,`talk_msg_time`,`talk_msg_msg`,`talk_msg_is_read`) VALUE '.$str);

                    }else{
                        GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,client not online'],-4);
                    }

                     //转发给对应语种服务端
                     $code=GatewayWorker\channel\sendSDK::resendToAdmin($msg['country'],$msg['msg']);
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
                  }
                  return;
            }else{ //一对一 私聊
                $talk_msg_data = [
                    'talk_msg_from_id'=>$msg['msg']['mine']['id'],
                    'talk_msg_to_id'=>$msg['msg']['to']['id'],
                    'talk_msg_type'=>1,
                    'talk_msg_time'=>date('Y-m-d H:i:s',time()),
                    'talk_msg_msg'=>$msg['msg']['mine']['content'],
                    'talk_msg_is_read'=>1
                ];

                $admin_talk = self::$db->from('admin_talk')->select('admin_talk_pro')->where("admin_primary_id='".$msg['msg']['mine']['id']."'")->row();
                if(!$admin_talk){
                    GatewayWorker\channel\sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
                    return;
                }
                //2.获取客服列表
                if($admin_talk['admin_talk_pro'] == 0){
                    $admin_talks =  self::$db->from('admin_talk')->select('*')->query();
                    GatewayWorker\channel\sendSDK::resendToAdmin('admin',$talk_msg_data);
                }else{
                    $admin_talks =  self::$db->from('admin_talk')->select('*')->where('admin_talk_pro="'.$admin_talk['admin_talk_pro'].'"')->orwhere('admin_talk_pro=0')->query();
                    GatewayWorker\channel\sendSDK::resendToAdmin($admin_talk['admin_talk_pro'],$talk_msg_data);
                }

                //好友消息
                $send_data = \GatewayWorker\channel\sendSDK::msg_template($msg['msg']['mine']['username'],$msg['msg']['mine']['avatar'],$msg['msg']['mine']['id'],$msg['msg']['mine']['content'],$msg['msg']['mine']['id'],0,true);
                if(Gateway::isUidOnline($msg['msg']['to']['id'])){  //在线
                    foreach ($admin_talks as $talk){
                        $talk_msg_data['talk_msg_from_id'] = $talk['admin_primary_id'];
                        $data = \GatewayWorker\channel\sendSDK::msg_template($talk['admin_talk_name'],$talk['admin_talk_img'],$talk['admin_primary_id'],$msg['msg']['mine']['content'],$talk['admin_primary_id'],0,true);
                        if(Gateway::isUidOnline($talk['admin_primary_id'])){
                            GatewayWorker\channel\sendSDK::msgToAdminByPid($msg['msg']['to']['id'],$data);
                        }else{
                            $talk_msg_data['talk_msg_is_read'] = 2;
                        }

                        self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
                    }

                    //2. 发给客户
                    GatewayWorker\channel\sendSDK::msgToClientByPid($msg['msg']['to']['id'],$send_data);

                    GatewayWorker\channel\sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSendResponse','msg'=>'success','account'=>$msg['account']],0);
                }else{ //不在线
                    //所有客服不在线 消息未读
                    foreach ($admin_talks as $talk){
                        $talk_msg_data['talk_msg_from_id'] = $talk['admin_primary_id'];
                        $talk_msg_data['talk_msg_is_read'] = 0;
                        self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
                    }
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
               \GatewayWorker\channel\sendSDK::msgToAdmin(1,$_SESSION[$client_id]['ip_info']['country'],$data);
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
