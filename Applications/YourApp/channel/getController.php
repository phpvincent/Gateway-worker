<?php
use \Workerman\Autoloader;
use \GatewayWorker\Lib\Gateway;
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__.'/Controller.php';
class getController extends Controller{
	public static function http_init($con,$db_http,$http_data)
	{
		if(!isset($http_data['admin_id'])) return $con->send(json_encode(['code'=>1,'msg'=>'admin_id not found'])); 
           //初始化反馈数据
           $data=[];
           $admin=$db_http->select('*')->from('admin_talk')->where('admin_primary_id="'.$http_data['admin_id'].'"')->offset(0)->limit(1)->query();
           if($admin==null||count($admin)==0){
           	return $con->send(json_encode(['code'=>1,'msg'=>'admin data not found'])); 
           }else{
           	$admin=$admin[0];
           }
           $data['data']['mine']=[
           	'username'=>$admin['admin_talk_name'],
           	'id'=>$http_data['admin_id'],
           	'status'=>'online',
           	'sign'=>$admin['admin_talk_sign'],
           	'avatar'=>$admin['admin_talk_img']
           ];
           $group=[];
     
           //$group=Gateway::getAllGroupIdList();
           $admin_lan=$admin['admin_talk_pro'];
           $lans=$db_http->select('*')->from('lan')->query();
           if($admin_lan!=0){
           		$need_lan=$db_http->select('*')->from('lan')->where('lan_id="'.$admin_lan.'"')->offset(0)->limit(1)->query()[0]['lan_name'];
           }
           if(isset($need_lan)){
               	$group_m=[];
               	$group_m['groupname']=$need_lan;
               	$group_m['id']=$need_lan;
               	$users=$db_http->select('*')->from('talk_user')->where('talk_user_lan="'.$need_lan.'"')->query();
               	if(count($users)>0){
               		foreach($users as $val){
               			$user_m=[];
               			$user_m['username']=$val['talk_user_name']==null ? $val['talk_user_pid'] : $val['talk_user_name'];
	               		$user_m['id']=$val['talk_user_pid'];
	               		$user_m['avatar']=Gateway::isUidOnline($val['talk_user_pid'])==1 ? '/images/online.gif' : '/images/close.png';
	               		$user_m['sign']='语种:'.$val['talk_user_lan'].'，商品id:'.$val['talk_user_goods'];
	               		$user_m['status']=Gateway::isUidOnline($val['talk_user_pid'])==1 ? 'online' : null;
	               		$group_m['list'][]=$user_m;
               			unset($user_m);
               		}
               	}else{
               		$group_m['list']=null;
               	}
               	$data['data']['friend'][]=$group_m;
               	unset($group_m,$users,$need_lan);
           }elseif($admin_lan==0){
               	 foreach($lans as  $val){
               	 	$group_m=[];
               	 	$group_m['groupname']=$val['lan_name'];
               	 	$group_m['id']=$val['lan_name'];
               	 	$users=$db_http->select('*')->from('talk_user')->where('talk_user_lan="'.$val['lan_name'].'"')->query();
               	 	if(count($users)>0){
               	 		foreach($users as $v){
               	 			$user_m=[];
               	 		 	$user_m['username']=$v['talk_user_name']==null ? $v['talk_user_pid'] : $v['talk_user_name'];
		               		$user_m['id']=$v['talk_user_pid'];
		               		$user_m['avatar']=Gateway::isUidOnline($v['talk_user_pid'])==1 ? '/images/online.gif' : '/images/close.png';
		               		$user_m['sign']='语种:'.$v['talk_user_lan'].'，商品id:'.$v['talk_user_goods'];
		               		$user_m['status']=Gateway::isUidOnline($v['talk_user_pid'])==1 ? 'online' : null;
		               		$group_m['list'][]=$user_m;
               	 			unset($user_m);
               	 		}
               	 	}else{
               	 		$group_m['list']=null;
               	 	}
               	 	$data['data']['friend'][]=$group_m;
               	 	unset($group_m,$users);
               	 }
               	 unset($admin_lan);
           }else{
           	  $data['data']['friend']=[];
           }
           if(!isset($data['data']['friend'])) $data['data']['friend']=[];
           foreach($lans as $k){
           	 $lan_m=[];
           	 $lan_m['groupname']=$k['lan_name'].'-'.$k['lan_cname'];
           	 $lan_m['id']=$k['lan_name'];
           	 $lan_m['avatar']="http://52.14.183.239/img/site.png";
           	 $data['data']['group'][]=$lan_m;
           	 unset($lan_m);
           }
           unset($group,$lans,$admin);
           $data['code']='0';
           $data['msg']='';
           $con->send(json_encode($data));
           unset($data);
	}
	public static function people_num($con,$db_http,$http_data)
	{
		$con->send(json_encode(['status'=>1,'count'=>Gateway::getAllClientIdCount()]));
	}
	public static function getGroupUsers($con,$db_http,$http_data)
	{
		if(!isset($_GET['id'])) return $con->send(json_encode(['status'=>0,'msg'=>'id not find']));
            $group_id=$_GET['id'];
            $group_list=Gateway::getUidListByGroup($group_id);
            if(count($group_list)==0) return $con->send(json_encode(['code'=>0,'msg'=>'','data'=>null]));
            $data=[];
            foreach($group_list as $v){
            	$admin_m=[];
            	$admin=$db_http->select('*')->from('admin_talk')->where('admin_primary_id="'.$v.'"')->offset(0)->limit(1)->query();
            	if($admin!=null){	
            		$admin=$admin[0];
            		$admin_m=[
            		'username'=>$admin['admin_talk_name'],
	               	'id'=>$_GET['admin_primary_id'],
	               	'avatar'=>$admin['admin_talk_img'],
	               	'sign'=>$admin['admin_talk_sign']];
	               	$data['data']['list'][]=$admin_m;
	               	unset($admin_m,$admin);
            	}else{
            	  unset($admin_m,$admin);
            	  break;
            	}
            }
            $data['code']=0;
            $data['msg']='';
            $con->send(json_encode($data));
            unset($group_id,$data,$group_list);
	}
}