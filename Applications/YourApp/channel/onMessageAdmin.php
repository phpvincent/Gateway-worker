<?php
namespace GatewayWorker\channel;

use \GatewayWorker\Lib\Gateway;
class onMessageAdmin
{
    private static $config_arr = ['auth'=>'','all' => 'admin_send_all', 'country' => 'admin_send_country', 'cliend' => 'client_send_client'];
    public static $db;

    /**
     * 客服接收消息与发送消息转接
     * @param $data
     * @param $db
     * @return bool
     */
    public static function get_message($client_id, $data, $db)
    {
        self::$db = $db;
        if(isset($data['type']) && $data['type']=='auth'){
            //用户登陆
            static::admin_login($client_id, $data);
            return;
        }
        //数据推送
        if(!isset($_SESSION[$client_id])||!isset($_SESSION[$client_id]['auth'])){
            //验证身份
            sendSDK::msgToAdmin(0,$client_id,['err'=>'auth unallow','type'=>'adminSend'],-2);
            GateWay::closeClient($client_id);
            return;
        }

        //推送数据
        if(!isset($data['touser'])){
            sendSDK::msgToAdmin(0,$client_id,['err'=>'touser not found','type'=>'adminSend'],-3);
            return;
        }

        if($data['touser']=='all' && isset($msg['country'])){
            $key = 'country';
        }elseif($data['touser']=='all' && !isset($msg['country'])){
            $key = 'all';
        }else{
            $key = 'cliend';
        }

        if (isset(self::$config_arr[$key])) {
            $fun_name = self::$config_arr[$key];
            return self::$fun_name($client_id, $data);
        } else {
            return false;
        }
    }

    /**
     * 客服登陆
     * @param $client_id
     * @param $data
     * @param $ip_info
     */
    public static function admin_login($client_id, $data)
    {
        $admin=self::$db->select('*')->from('admin')->where('admin_name="'.$data['admin_name'].'"')->offset(0)->limit(1)->query()[0];
        if($admin==false||password_verify($data['admin_password'], $admin['password'])==false){
            sendSDK::msgToAdmin(0,$client_id,['err'=>'auth unallow','type'=>'auth'],-1);
            return;
        }else{
            $_SESSION[$client_id]['auth']=$admin;
            Gateway::joinGroup($client_id,$data['language']);var_dump($data['language']); //TODO 需要完善
            Gateway::joinGroup($client_id,'admin');
            Gateway::bindUid($client_id,$admin['admin_id']);
            $_SESSION['pid'] = $admin['admin_id'];
            //判断用户是否存在



            self::$db->update('admin_talk')->cols(['admin_talk_status'=>1,'admin_talk_last_time'=>date('Y-m-d H:i:s',time())])->where('admin_primary_id='.$admin['admin_id'])->query();
            //告诉自己，通讯成功
            sendSDK::msgToAdminByPid($admin['admin_id'],['pid'=>$admin['admin_id'],'type'=>'auth'],1);

            //查看是否有未读消息，如果有，直接推送
            if($data['language'] == '0'){
                $talk_msg_infos = self::$db->select('*')->from('talk_msg')->where('talk_msg_type=0')->where('talk_msg_is_read=0')->query();
            }else{
                $talk_msg_infos = self::$db->select('*')->from('talk_msg')->where('talk_msg_type=0')->where("talk_msg_lan='".$data['language']."'")->where('talk_msg_is_read=0')->query();
            }

            if(!empty($talk_msg_infos)){
                foreach ($talk_msg_infos as $talk_admin_msg){
                    $talk_user = self::$db->select('*')->from('talk_user')->where("talk_user_pid='".$talk_admin_msg['talk_msg_from_id']."'")->row();
                    if(!$talk_user){
                        sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
                        return;
                    }
                    $datas = sendSDK::msg_template($talk_user['talk_user_name'],"/images/close.png",$talk_admin_msg['talk_msg_from_id'],$talk_admin_msg['talk_msg_msg'],$talk_admin_msg['talk_msg_from_id'],$talk_admin_msg['talk_msg_id'],false);
                    $datas['sendUser'] = 'old_user';
                    sendSDK::msgToAdminByPid($admin['admin_id'],$datas);
                }
                if($data['language'] == '0'){
                    self::$db->update('talk_msg')->cols(array('talk_msg_is_read'=>'1'))->where('talk_msg_type=0')->where('talk_msg_is_read=0')->query();
                }else{
                    self::$db->update('talk_msg')->cols(array('talk_msg_is_read'=>'1'))->where('talk_msg_type=0')->where("talk_msg_lan='".$data['language']."'")->where('talk_msg_is_read=0')->query();
                }
            }
        }
        return;
    }

    /**
     * 指定国家发送消息
     * @param $client_id
     * @param $msg
     */
    public static function admin_send_country($client_id, $msg)
    {
        if(!isset($msg['msg'])) sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'msg send fail,msg not found'],-5);
        $msg_arr=[];
        $msg_arr['type']='adminSend';
        $msg_arr['msg']=$msg['msg'];
        $country = sendSDK::getcountryandalias($msg['country']);
        $lan = sendSDK::getlanfromcountry($msg['country']);
        $admin_talk = self::$db->from('admin_talk')->where("admin_primary_id='".$msg['admin_id']."'")->row();
        if($admin_talk){
            sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
            return;
        }
        $send_data = sendSDK::msg_template($admin_talk['admin_talk_name'],$admin_talk['admin_talk_img'],$msg['admin_id'],$msg['msg'],$msg['admin_id'],3,false);
        $talk_msg_data = [
            'talk_msg_from_id'=>$msg['admin_id'],
            'talk_msg_to_id'=>0,
            'talk_msg_type'=>3,
            'talk_msg_time'=>date('Y-m-d H:i:s',time()),
            'talk_msg_msg'=>$msg['msg'],
            'talk_msg_is_read'=>1,
            'talk_msg_lan'=>$lan,
        ];
        $insert_id = self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
        if($insert_id){
            sendSDK::msgToClient($country,$send_data);
            //转发给对应语种服务端
            $code=sendSDK::resendToAdmin($msg['country'],$send_data);
            if($code==false){
                sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,country not allow'],-4);
            }
        }else{
            sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,country not allow'],-4);
        }
        return;
//        $client_ids=Gateway::getUidListByGroup('client_'.$country);
//        if(!empty($client_ids)){
//            $str = '';
//            $lan  = sendSDK::getlanfromcountry($msg['country']);
//            if($lan === false){
//                sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,country not allow'],-4);
//            }
//
//            获取客服列表
//            $customers = self::$db->from('admin_talk')->select('*')->where('admin_talk_pro="'.$lan.'"')->orwhere("admin_talk_pro=0")->query();
//            if(count($customers) <= 0){
//                sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'This function has not been activated yet.'],-8);
//                return;
//            }
//            foreach ($customers as $customer){
//                $talk_msg_from_id = $customer['admin_primary_id'];
//                $talk_msg_is_read = 1;
//                $talk_msg_time = date('Y-m-d H:i:s');
//                $talk_msg_msg = $msg['msg'];
//                foreach($client_ids as $v) {
//                    $str .= "('$talk_msg_from_id','$v',1,'$talk_msg_time','$talk_msg_msg',$talk_msg_is_read),";
//                }
//            }
//            $str = trim($str,',');
//            self::$db->query('INSERT INTO `talk_msg` (`talk_msg_from_id`,`talk_msg_to_id`,`talk_msg_type`,`talk_msg_time`,`talk_msg_msg`,`talk_msg_is_read`) VALUE '.$str);
//        }else{
//            sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'adminReSend fail,client not online'],-4);
//        }
    }

    /**
     *  数据广播
     * @param $client_id
     * @param $msg
     */
    public static function admin_send_all($client_id, $msg)
    {
        if(!isset($msg['msg'])){
            sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSend','msg'=>'msg send fail,msg not found'],-5);
            return;
        }
        $uidList = Gateway::getAllUidList();
        if(!empty($uidList)){
            $str = '';
            $talk_msg_from_id = $_SESSION[$client_id]['auth']['admin_id'];
            $talk_msg_time = date('Y-m-d H:i:s');
            $talk_msg_msg = $msg['msg'];
            foreach($uidList as $v) {
                $str .= "('$talk_msg_from_id','$v',4,'$talk_msg_time','$talk_msg_msg',1),";
            }
            $str = trim($str,',');
            self::$db->query('INSERT INTO `talk_msg` (`talk_msg_from_id`,`talk_msg_to_id`,`talk_msg_type`,`talk_msg_time`,`talk_msg_msg`,`talk_msg_is_read`) VALUE '.$str);
        }

        sendSDK::msgToClient('all',$msg['msg']); //发送所以在线人员
        return;
    }

    /**
     * 客服对客户 聊天
     * @param $client_id
     * @param $msg
     */
    public static function client_send_client($client_id, $msg)
    {
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
            sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
            return;
        }

        //2.获取接收信息者信息
        $talk_user = self::$db->from('talk_user')->select('talk_user_lan')->where("talk_user_pid='".$msg['msg']['to']['id']."'")->row();
        if(!$talk_user){
            sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
            return;
        }
        $talk_msg_data['talk_msg_lan'] = $talk_user['talk_user_lan'];
        var_dump(5555);
        //3.获取客服列表
        $admin_talks =  self::$db->from('admin_talk')->select('*')->where('admin_talk_pro="0"')->orwhere('admin_talk_pro="'.$talk_user['talk_user_lan'].'"')->query();
//        sendSDK::resendToAdmin($admin_talk['admin_talk_pro'],$talk_msg_data);
        //好友消息
        $send_data = sendSDK::msg_template($msg['msg']['mine']['username'],$msg['msg']['mine']['avatar'],$msg['msg']['mine']['id'],$msg['msg']['mine']['content'],1,0,false);
        if(Gateway::isUidOnline($msg['msg']['to']['id'])){  //在线
            foreach ($admin_talks as $talk){
                $data = sendSDK::msg_template($msg['msg']['mine']['username'],$talk['admin_talk_img'],$talk['admin_primary_id'],$msg['msg']['mine']['content'],$talk['admin_primary_id'],0,true);
                if(Gateway::isUidOnline($talk['admin_primary_id']) && $talk['admin_primary_id'] != $msg['msg']['mine']['id']){
                    var_dump($talk['admin_primary_id']);
                    $data['sendUser'] = 'old_user';
                    sendSDK::msgToAdminByPid($talk['admin_primary_id'],$data);
                }
            }

            self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
            //2. 发给客户
            sendSDK::msgToClientByPid($msg['msg']['to']['id'],$send_data);

            sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSendResponse','msg'=>'success','account'=>$msg['account']],0);
        }else{ //不在线
            //所有客服不在线 消息未读
//            foreach ($admin_talks as $talk){
//                $talk_msg_data['talk_msg_from_id'] = $talk['admin_primary_id'];
//                $talk_msg_data['talk_msg_is_read'] = 0;
//                self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
//            }
            $talk_msg_data['talk_msg_is_read'] = 0;
            self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
            sendSDK::msgToAdmin(0,$client_id,['type'=>'adminSendResponse','msg'=>'failed','account'=>$msg['account']],0);
        }
        unset($msg);
        return;
    }
}