<?php
return [
	'database'=>[
		'route'=>'127.0.0.1',
		'port'=>'3306',
		'username'=>'homestead',
		'password'=>'secret',
		'database'=>'obj'
	],
	'routers'=>[
		'get'=>[
			'people_num'=>'people_num',
			'init'=>'http_init',
			'getGroupUsers'=>'getGroupUsers',
		],
		'post'=>[
			'getUserInfo' => 'getUserInfo',
			'upUserInfo' => 'upUserInfo',
			'upAdminInfo' => 'upAdminInfo',
			'file_upload' => 'file_upload',
			'img_upload' => 'img_upload',
		],
	],
];