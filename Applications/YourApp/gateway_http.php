<?php
use \Workerman\Worker;
use \GatewayWorker\Lib\Gateway;
use \Workerman\Autoloader;
require_once __DIR__ . '/../../vendor/autoload.php';
require_once './vendor/mysql-master/src/Connection.php';
Autoloader::setRootPath(__DIR__);

// #### 内部推送端口(假设当前服务器内网ip为192.168.100.100) ####
// #### 端口不能与原来start_gateway.php中一样 ####
$internal_gateway = new Worker("http://0.0.0.0:7273");
\Workerman\Protocols\Http::header('Access-Control-Allow-Origin:*');
$internal_gateway->name='internalGateway';
// #### 不要与原来start_gateway.php的一样####
// #### 比原来跨度大一些，比如在原有startPort基础上+1000 ####
$internal_gateway->startPort = 3300;
// #### 这里设置成与原start_gateway.php 一样 ####
$internal_gateway->registerAddress = '127.0.0.1:1238';
$internal_gateway->onWorkerStart=function($worker)
{	
	global $db_http;
	$db_http = new \Workerman\MySQL\Connection('127.0.0.1', '3306', 'adminuser', 'Ydzs2018', 'obj');
};
$internal_gateway->onMessage=function($con,$message){
	global $db_http;
	 if((isset($message['get'])||isset($message['post']))&&isset($message['server']['REQUEST_METHOD'])){/*var_dump($message);*/
      //http
      $http_data=isset($message['get']) ? $message['get'] : $message['post'];
      if(!isset($http_data['type'])) $con->send(json_encode(['status'=>0,'msg'=>'can not find type']));
      \Workerman\Protocols\Http::header('Access-Control-Allow-Origin: *');
          switch ($http_data['type']) {
            case 'people_num':
               $con->send(json_encode(['status'=>1,'count'=>Gateway::getAllClientIdCount()]));
              break;
            case 'init':
	              if(!isset($_GET['admin_id'])) $con->send(json_encode(['code'=>1,'msg'=>'admin_id not found']));
	               //初始化反馈数据
	               $data=[];
	               $admin=$db_http->select('*')->from('admin_talk')->where('admin_primary_id="'.$_GET['admin_id'].'"')->offset(0)->limit(1)->query();
	               if($admin==null||count($admin)==0){
	               	$con->send(json_encode(['code'=>1,'msg'=>'admin data not found']));
	               }else{
	               	$admin=$admin[0];
	               }
	               $data['data']['mine']=[
	               	'username'=>$admin['admin_talk_name'],
	               	'id'=>$_GET['admin_id'],
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
		               	if(count($user)>0){
		               		foreach($user as $val){
		               			$user_m=[];
		               			$user_m['username']=$val['talk_user_name']==null ? $val['talk_user_pid'] : $val['talk_user_name'];
			               		$user_m['id']=$val['talk_user_pid'];
			               		$user_m['avatar']=Gateway::isUidOnline($v['talk_user_pid'])==1 ? '/images/online.gif' : '/images/close.png';
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
	               break;
            case 'getGroupUsers':var_dump($_GET);
		            if(!isset($_GET['id'])) $con->send(json_encode(['status'=>0,'msg'=>'id not find']));
		            $group_id=$_GET['id'];
		            $group_list=Gateway::getUidListByGroup($group_id);
		            if(count($group_list)==0) $con->send(json_encode(['code'=>0,'msg'=>'','data'=>null]));
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
              break;
            case 'file_upload':
            	if(count($_File)>1||count($_File)<=0) $con->send(json_encode(['code'=>1,'msg'=>'file count not allowed']));
            	if($_File[0]['file_size']>10485760) $con->send(json_encode(['code'=>2,'msg'=>'file size not allowed']));
            	file_put_contents('/tmp/up_files'.$_File[0]['file_name'], $_File[0]['file_data']);
            	$con->send(['code'=>0,'msg'=>'file upload success','data'=>['src'=>'http://13.229.73.221/tmp/up_files'.$_File[0]['file_name'],'name'=>$_File[0]['file_name']]]);
            	return;
            case 'img_upload':
            	if(count($_File)>1||count($_File)<=0) $con->send(json_encode(['code'=>1,'msg'=>'img count not allowed']));
            	if($_File[0]['file_size']>10485760) $con->send(json_encode(['code'=>2,'msg'=>'img size not allowed']));
            	file_put_contents('/tmp/up_images'.$_File[0]['file_name'], $_File[0]['file_data']);
            	$con->send(['code'=>0,'msg'=>'img upload success','data'=>['src'=>'http://13.229.73.221/tmp/up_images'.$_File[0]['file_name'],'name'=>$_File[0]['file_name']]]);
                return;
            break;
            default:
              $con->send(json_encode(['status'=>0,'msg'=>'http type not allowed']));
              break;
          }
          return;
      }
      $con->send(json_encode(['status'=>0,'msg'=>'http type not allowed']));
};
// #### 内部推送端口设置完毕 ####

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}