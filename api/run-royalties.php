<?php
// Run through cron with the same business timezone and configuration as the website.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/royalties.php';
try{$cfg=require __DIR__.'/config.php';date_default_timezone_set($cfg['timezone']??'Asia/Kolkata');$db=new PDO($cfg['dsn'],$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$period=date('Y-m');$count=runRoyalties($db,$period);$log=$db->prepare("INSERT INTO audit_events (actor_id,action,subject) VALUES (NULL,'scheduled_royalty',?)");$log->execute([$period]);echo "Royalty $period: $count award(s).\n";}catch(Throwable $e){fwrite(STDERR,"Royalty run failed: ".$e->getMessage()."\n");exit(1);}
