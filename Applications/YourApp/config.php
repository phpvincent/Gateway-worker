<?php
return [
	'database'=>[
		'route'=>'127.0.0.1',
		'port'=>'3306',
		'username'=>'root',
		'password'=>'root',
		'database'=>'obj'
	],
	'routers'=>[
		'get'=>[
			'people_num'=>'people_num',
			'init'=>'http_init',
			'getGroupUsers'=>'getGroupUsers',
			'getTalkMsg'=>'getTalkMsg',
			'getTalkMsgCount'=>'getTalkMsgCount',
		],
		'post'=>[
			'getUserInfo' => 'getUserInfo',
			'upUserInfo' => 'upUserInfo',
			'upAdminInfo' => 'upAdminInfo',
			'file_upload' => 'file_upload',
			'img_upload' => 'img_upload',
		],
	],
	'server'=>[
		//'server'=>'192.168.10.166'
		'server'=>'13.229.73.221'
	]
];