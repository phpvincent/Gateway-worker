<?php
use \Workerman\Autoloader;
use \GatewayWorker\Lib\Gateway;
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__.'/Controller.php';
class postController extends Controller{
	public static function getUserInfo($con,$db_http,$http_data)
	{
		 if(!isset($http_data['pid'])) return $con->send(json_encode(['status'=>0,'msg'=>'pid not find']));
	     $user=$db_http->select('*')->from('talk_user')->where('talk_user_pid="'.$http_data['pid'].'"')->offset(0)->limit(1)->query();
	     if(count($user)<=0) return $con->send(json_encode(['status'=>0,'msg'=>'user msg not found']));
	     $con->send(json_encode(['status'=>1,'msg'=>$user[0]]));
	}
	public static function upUserInfo($con,$db_http,$http_data)
	{
    	if(!isset($http_data['pid'])||$http_data['pid']==false) return $con->send(json_encode(['status'=>0,'msg'=>'talk_user_pid not allowed']));
    	$updata_a=[];
    	if(isset($http_data['talk_user_phone'])&&$http_data['talk_user_phone']!=null) $updata_a['talk_user_phone']=$http_data['talk_user_phone'];
    	if(isset($http_data['talk_user_email'])&&$http_data['talk_user_email']!=null) $updata_a['talk_user_email']=$http_data['talk_user_email'];
    	if(isset($http_data['talk_user_name'])&&$http_data['talk_user_name']!=null) $updata_a['talk_user_name']=$http_data['talk_user_name'];
    	if(isset($http_data['talk_user_remark'])&&$http_data['talk_user_remark']!=null) $updata_a['talk_user_remark']=$http_data['talk_user_remark'];
    	if($updata_a==[]) return $con->send(json_encode(['status'=>0,'msg'=>'data unallow']));
    	$db_http->update('talk_user')->cols($updata_a)->where('talk_user_pid="'.$http_data['pid'].'"')->query();
    	return $con->send(json_encode(['status'=>1,'msg'=>$http_data['pid'].'update success']));
	}
	public static function upAdminInfo($con,$db_http,$http_data)
	{
    	if(!isset($http_data['admin_id'])||$http_data['admin_id']==false) return $con->send(json_encode(['status'=>0,'msg'=>'admin_id not allowed']));
    	$updata_a=[];
    	if(isset($http_data['admin_talk_sign'])&&$http_data['admin_talk_sign']!=null) $updata_a['admin_talk_sign']=$http_data['admin_talk_sign'];
    	if($updata_a==[]) return $con->send(json_encode(['status'=>0,'msg'=>'data unallow']));
    	$db_http->update('admin_talk')->cols($updata_a)->where('admin_primary_id="'.$http_data['admin_id'].'"')->query();
    	return $con->send(json_encode(['status'=>1,'msg'=>$http_data['admin_id'].'update success']));
	}
	public static function file_upload($con,$db_http,$http_data)
	{	
		global $config;
    	if(count($_FILES)>1||count($_FILES)<=0) return $con->send(json_encode(['code'=>1,'msg'=>'file count not allowed']));
    	if($_FILES[0]['file_size']>10485760) return $con->send(json_encode(['code'=>2,'msg'=>'file size not allowed']));
    	file_put_contents('/tmp/up_files/'.$_FILES[0]['file_name'], $_FILES[0]['file_data']);
    	$con->send(json_encode(['code'=>0,'msg'=>'file upload success','data'=>['src'=>'http://'.$config['server']['server'].'/up_files/'.$_FILES[0]['file_name'],'name'=>$_FILES[0]['file_name']]]));
	}
	public static function img_upload($con,$db_http,$http_data)
	{	
		global $config;
    	if(count($_FILES)>1||count($_FILES)<=0) return $con->send(json_encode(['code'=>1,'msg'=>'img count not allowed']));
    	if($_FILES[0]['file_size']>10485760) return $con->send(json_encode(['code'=>2,'msg'=>'img size not allowed']));
    	file_put_contents('/tmp/up_images/'.$_FILES[0]['file_name'], $_FILES[0]['file_data']);
    	$con->send(json_encode(['code'=>0,'msg'=>'img upload success','data'=>['src'=>'http://'.$config['server']['server'].'/up_images/'.$_FILES[0]['file_name']]]));
	}
}