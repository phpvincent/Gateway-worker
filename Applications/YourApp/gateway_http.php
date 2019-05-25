<?php
use \Workerman\Worker;
use \GatewayWorker\Lib\Gateway;
use \Workerman\Autoloader;
require_once __DIR__ . '/../../vendor/autoload.php';
require_once './Applications/YourApp/channel/Router.php';
Autoloader::setRootPath(__DIR__);

// #### 内部推送端口(假设当前服务器内网ip为192.168.100.100) ####
// #### 端口不能与原来start_gateway.php中一样 ####
$internal_gateway = new Worker("http://0.0.0.0:7273");
$internal_gateway->name='internalGateway';
// #### 不要与原来start_gateway.php的一样####
// #### 比原来跨度大一些，比如在原有startPort基础上+1000 ####
$internal_gateway->startPort = 3300;
// #### 这里设置成与原start_gateway.php 一样 ####
//$internal_gateway->registerAddress = '127.0.0.1:1238';
$internal_gateway->onWorkerStart=function($worker)
{	
	global $Router,$config;
	$Router=new GatewayWorker\channel\Router();
	$Router::setDb($config['database']['route'],$config['database']['port'], $config['database']['username'], $config['database']['password'],$config['database']['database']);
	$Router::setRouter(
		GatewayWorker\channel\Router::group('get',$config['routers']['get']),
		GatewayWorker\channel\Router::group('post',$config['routers']['post'])
	);
};
$internal_gateway->onMessage=function($con,$message){
	global $Router;
	try {
		$Router->http_set($message['server']['REQUEST_METHOD'])->http_data(GatewayWorker\channel\Router::get_http_data($message))->exec($con);
	} catch (\Exception $e) {
		$con->send(json_encode(['status'=>0,'msg'=>$e->getMessage()]));
	}
	return;
	/////////////////////////////////////////////////////////////
	/*GatewayWorker\channel\Router::method($message['server']['REQUEST_METHOD']);
	 if((isset($message['get'])||isset($message['post']))&&isset($message['server']['REQUEST_METHOD'])){var_dump($message);*/
		   /*if(!isset($_GET['admin_id'])||!isset($_POST['admin_id']))  return $con->send(json_encode(['status'=>0,'msg'=>'admin_id not found']));
		   $client_id=isset($_GET['admin_id']) ? $_GET['admin_id'] : $_POST['admin_id'];
		   if($client_id==false) $client_id=$con->id;
		   if(!isset($_SESSION[$client_id])||!isset($_SESSION[$client_id]['auth'])){
	              //验证身份
	               return $con->send(json_encode(['status'=>0,'msg'=>'auth not allowed'])); 
	              GateWay::closeClient($client_id);
	              return;
	        }*/
	      //http
	      /*$http_data=isset($message['get']) ? $message['get'] : $message['post'];
	      if(!isset($http_data['type'])) return $con->send(json_encode(['status'=>0,'msg'=>'can not find type'])); 
	          switch ($http_data['type']) {
	            case 'people_num':
	               $con->send(json_encode(['status'=>1,'count'=>Gateway::getAllClientIdCount()]));
	               return;
	              break;
	            case 'init':
		              if(!isset($_GET['admin_id'])) return $con->send(json_encode(['code'=>1,'msg'=>'admin_id not found'])); 
		               //初始化反馈数据
		               $data=[];
		               $admin=$db_http->select('*')->from('admin_talk')->where('admin_primary_id="'.$_GET['admin_id'].'"')->offset(0)->limit(1)->query();
		               if($admin==null||count($admin)==0){
		               	return $con->send(json_encode(['code'=>1,'msg'=>'admin data not found'])); 
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
		               return;
		               break;
	            case 'getGroupUsers':var_dump($_GET);
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
	              break;
	            case 'getUserInfo':
	                if(!isset($_POST['pid'])) return $con->send(json_encode(['status'=>0,'msg'=>'pid not find']));
	                $user=$db_http->select('*')->from('talk_user')->where('talk_user_pid="'.$_POST['pid'].'"')->offset(0)->limit(1)->query();
	                if(count($user)<=0) return $con->send(json_encode(['status'=>0,'msg'=>'user msg not found']));
	                $con->send(json_encode(['status'=>1,'msg'=>$user[0]]));
	            	break;
	            case 'upUserInfo':
	            	if($message['server']['REQUEST_METHOD']!='POST') return $con->send(json_encode(['status'=>0,'msg'=>'method not allowed']));
	            	if(!isset($_POST['pid'])||$_POST['pid']==false) return $con->send(json_encode(['status'=>0,'msg'=>'talk_user_pid not allowed']));
	            	$updata_a=[];
	            	if(isset($_POST['talk_user_phone'])&&$_POST['talk_user_phone']!=null) $updata_a['talk_user_phone']=$_POST['talk_user_phone'];
	            	if(isset($_POST['talk_user_email'])&&$_POST['talk_user_email']!=null) $updata_a['talk_user_email']=$_POST['talk_user_email'];
	            	if(isset($_POST['talk_user_name'])&&$_POST['talk_user_name']!=null) $updata_a['talk_user_name']=$_POST['talk_user_name'];
	            	if(isset($_POST['talk_user_remark'])&&$_POST['talk_user_remark']!=null) $updata_a['talk_user_remark']=$_POST['talk_user_remark'];
	            	if($updata_a==[]) return $con->send(json_encode(['status'=>0,'msg'=>'data unallow']));
	            	$db_http->update('talk_user')->cols($updata_a)->where('talk_user_pid="'.$_POST['pid'].'"')->query();
	            	return $con->send(json_encode(['status'=>1,'msg'=>$_POST['pid'].'update success']));
	            	break;
	            case 'upAdminInfo':
	            	if($message['server']['REQUEST_METHOD']!='POST') return $con->send(json_encode(['status'=>0,'msg'=>'method not allowed']));
	            	if(!isset($_POST['admin_id'])||$_POST['admin_id']==false) return $con->send(json_encode(['status'=>0,'msg'=>'admin_id not allowed']));
	            	$updata_a=[];
	            	if(isset($_POST['admin_talk_sign'])&&$_POST['admin_talk_sign']!=null) $updata_a['admin_talk_sign']=$_POST['admin_talk_sign'];
	            	if($updata_a==[]) return $con->send(json_encode(['status'=>0,'msg'=>'data unallow']));
	            	$db_http->update('admin_talk')->cols($updata_a)->where('admin_primary_id="'.$_POST['admin_id'].'"')->query();
	            	return $con->send(json_encode(['status'=>1,'msg'=>$_POST['admin_id'].'update success']));
	            	break;
	            case 'file_upload':
	            	if(count($_FILES)>1||count($_FILES)<=0) return $con->send(json_encode(['code'=>1,'msg'=>'file count not allowed']));
	            	if($_FILES[0]['file_size']>10485760) return $con->send(json_encode(['code'=>2,'msg'=>'file size not allowed']));
	            	file_put_contents('/tmp/up_FILESs'.$_FILES[0]['file_name'], $_FILES[0]['file_data']);
	            	$con->send(['code'=>0,'msg'=>'file upload success','data'=>['src'=>'http://13.229.73.221/tmp/up_FILESs'.$_FILES[0]['file_name'],'name'=>$_FILES[0]['file_name']]]);
	            	return;
	            case 'img_upload':
	            	if(count($_FILES)>1||count($_FILES)<=0) return $con->send(json_encode(['code'=>1,'msg'=>'img count not allowed']));
	            	if($_FILES[0]['file_size']>10485760) return $con->send(json_encode(['code'=>2,'msg'=>'img size not allowed']));
	            	file_put_contents('/tmp/up_images'.$_FILES[0]['file_name'], $_FILES[0]['file_data']);
	            	$con->send(['code'=>0,'msg'=>'img upload success','data'=>['src'=>'http://13.229.73.221/tmp/up_images'.$_FILES[0]['file_name'],'name'=>$_FILES[0]['file_name']]]);
	                return;
	            break;
	            default:
	              $con->send(json_encode(['status'=>0,'msg'=>'http type not allowed'])); 
	              return;
	              break;
	          }
	          return;
	      }
      $con->send(json_encode(['status'=>0,'msg'=>'http type not allowed']));*/
};
// #### 内部推送端口设置完毕 ####

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}