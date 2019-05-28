<?php
namespace GatewayWorker\channel;

use \GatewayWorker\Lib\Gateway;
class onMessageClient {
    private static $config_arr=['firstClient'=>'client_login','reClient'=>'client_refresh','clientSend'=>'client_send'];
    public static $db;

    /**
     * 客户接收消息与发送消息转接
     * @param $data
     * @param $db
     * @return bool
     */
    public static function get_message($client_id,$data,$ip_info,$db)
    {
        self::$db = $db;
        if(isset($data['type']) && isset(self::$config_arr[$data['type']])){
            $key=$data['type'];
            $fun_name=self::$config_arr[$key];
            return self::$fun_name($client_id,$data,$ip_info);
        }else{
            return false;
        }
    }

    /**
     * 客户首次访问 存储用户信息
     * @param $client_id
     * @param $data
     * @param $ip_info
     */
    private static function client_login($client_id,$data,$ip_info)
    {
        //初次链接，分配pid
        $pid='c'.time().sendSDK::getlanid($client_id).rand(10000,99999);
        Gateway::bindUid($client_id,$pid);
        Gateway::joinGroup($client_id, 'client_'.$ip_info['lan']);
        $country = sendSDK::getcountryandalias($ip_info['country']);
        Gateway::joinGroup($client_id, 'client_'.$country);
        Gateway::joinGroup($client_id, 'client');
        $_SESSION['pid'] = $pid;
        //判断用户是否存在
        $user_id=self::$db->insert('talk_user')->cols([
            'talk_user_lan'=>$ip_info['lan'],
            'talk_user_status'=>1,
            'talk_user_goods'=>$data['goods_id'],
            'talk_user_time'=>date('Y-m-d H:i:s',time()),
            'talk_user_is_shop'=>0,
            'talk_user_last_time'=>date('Y-m-d H:i:s',time()),
            'talk_user_pid'=>$pid,
            'talk_user_name'=>$ip_info['country']."--".$data['goods_id']."--".date('m-d H:i'),
            'talk_user_country'=>$ip_info['country']
        ])->query();
        if($user_id){
            sendSDK::msgToClient($client_id,['type'=>'first_client','pid'=>$pid]);
        }else{
            sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
        }
        return;
    }

    /**
     * 用户再次访问或者刷新页面
     * @param $client_id
     * @param $data
     * @param $ip_info
     */
    private static function client_refresh($client_id,$data,$ip_info)
    {
        if(!isset($ip_info['country']) || (isset($ip_info['country'])&&$ip_info['country']==null) || (isset($ip_info['country'])&&$ip_info['country']=='XX')){
            sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'country can not be read'],-6);
            return;
        }
        if(!isset($data['pid'])){
            sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'pid not found'],-3);
            return;
        }
        Gateway::bindUid($client_id,$data['pid']);
        Gateway::joinGroup($client_id, 'client_'.$ip_info['lan']);
        $country = sendSDK::getcountryandalias($ip_info['country']);
        Gateway::joinGroup($client_id, 'client_'.$country);
        Gateway::joinGroup($client_id, 'client');
        $_SESSION['pid'] = $data['pid'];
        $time = date('Y-m-d H:i:s');
        //更新用户线上状态
        $row_count =self::$db->update('talk_user')->cols(['talk_user_last_time'=>$time,"talk_user_status"=>1])->where('talk_user_pid="'.$data['pid'].'"')->query();

        //用户上线
        $datas = [
            "type"  => "friendStatus",
            "uid"   => $data['pid'],
            "status"=> 'online'
        ];
        if(Gateway::getUidCountByGroup($ip_info['lan'])>0){
            //客服好友上线
            sendSDK::msgToAdmin(1,$ip_info['lan'],$datas);
        }
        unset($row_count);
        unset($data);
        return;
    }

    /**
     * 用户发送消息给客服 并且存储聊天记录
     * @param $client_id
     * @param $data
     * @param $ip_info
     */
    private static function client_send($client_id,$data,$ip_info)
    {
        //判断用户是否第一次通讯，如果第一次通讯，需先添加好友
        if(!isset($ip_info['country']) || (isset($ip_info['country'])&&$ip_info['country']==null) || (isset($ip_info['country'])&&$ip_info['country']=='XX')){
            sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'country can not be read'],-6);
            return;
        }
        $talk_user = self::$db->select('*')->from('talk_user')->where("talk_user_pid='".$data['pid']."'")->row();
        $result = [
            'username'=> $talk_user['talk_user_name'],
            'avatar'=> "/images/close.png",
            'id'=> $data['pid'],
            'type'=> "friend",
            'content'=> $data['msg'],
            'cid'=> 0,
            'mine'=> false,
            'fromid'=> $data['pid'],
            'timestamp'=> time()*1000,
        ];

        $talk_msg = self::$db->select('talk_msg_from_id')->from('talk_msg')->where("talk_msg_from_id='".$data['pid']."'")->orwhere("talk_msg_to_id='".$data['pid']."'")->row();
        //判断是否为新用户（没有聊天记录为新用户，有聊天记录为老用户）
        if(!$talk_msg){
            //添加好友
            $result['sendUser'] = "new_user";
        }else{
            $result['sendUser'] = "old_user";
        }
        $talk_msg_data = [
            'talk_msg_from_id'=>$data['pid'],
            'talk_msg_to_id'=>0,
            'talk_msg_type'=>0,
            'talk_msg_msg'=>$data['msg'],
            'talk_msg_is_read'=>1, //0未读 1已读
            'talk_msg_time'=>date("Y-m-d H:i:s"),
            'talk_msg_lan'=>$ip_info['lan']
        ];

        //获取客服列表
        $customers = self::$db->from('admin_talk')->select('*')->where('admin_talk_pro="'.$ip_info['lan'].'"')->orwhere("admin_talk_pro=0")->query();
        if(count($customers) <= 0){
            sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'This function has not been activated yet.'],-8);
            return;
        }

        //修改客户发送消息最后时间
        self::$db->update('talk_user')->cols(['talk_user_last_time'=>date('Y-m-d H:i:s',time())])->where("talk_user_pid='".$data['pid']."'")->query();

        //如果有客服在线，标记已读，没有客服在线标记未读
        if(Gateway::getUidCountByGroup($ip_info['lan'])<=0){
            $talk_msg_data['talk_msg_is_read'] = 0;
        }else{
            $talk_msg_data['talk_msg_is_read'] = 1;
        }

        $insert_id = self::$db->insert('talk_msg')->cols($talk_msg_data)->query();
        if(!$insert_id){
            sendSDK::msgToClient($client_id,['type'=>'clientSend','err'=>'info err'],-7);
            return;
        }

        //存储消息记录，发送在线用户
        foreach ($customers as $customer){
            $pid = $customer['admin_primary_id'];
            $talk_msg_data['talk_msg_to_id'] = $pid;
//            if($talk_msg_data['talk_msg_is_read'] === 1 && !Gateway::isUidOnline($pid)){
//                $talk_msg_data['talk_msg_is_read'] = 2;
//            }
            $result['cid'] = $insert_id;
            if(Gateway::isUidOnline($pid) && $pid != $data['pid']){
                sendSDK::msgToAdminByPid($pid,$result);
            }
        }
        return;
    }
}