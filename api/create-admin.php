<?php
// Run from the command line only: php api/create-admin.php name email password
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
if($argc!==4 || strlen($argv[3])<12 || !filter_var($argv[2],FILTER_VALIDATE_EMAIL)){fwrite(STDERR,"Usage: php api/create-admin.php 'Name' email password-at-least-12-characters\n");exit(1);}
$config=require __DIR__.'/config.php';$db=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$q=$db->prepare("INSERT INTO members (member_code,name,email,password_hash,is_admin,admin_role) VALUES (?,?,?,?,1,'owner')");$q->execute(['COMPANY',$argv[1],strtolower($argv[2]),password_hash($argv[3],PASSWORD_DEFAULT)]);echo "Administrator created with company sponsor ID COMPANY.\n";
