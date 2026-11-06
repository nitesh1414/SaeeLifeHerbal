<?php
// Emergency recovery. Run locally by a trusted operator only, never from the web.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc!==2||!filter_var($argv[1],FILTER_VALIDATE_EMAIL)){fwrite(STDERR,"Usage: php api/reset-admin-mfa.php admin@example.com\n");exit(1);}
try{
    $cfg=require __DIR__.'/config.php';$db=new PDO($cfg['dsn'],$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->beginTransaction();
    $q=$db->prepare('SELECT id FROM members WHERE email=? AND is_admin=1 FOR UPDATE');$q->execute([strtolower($argv[1])]);$u=$q->fetch();if(!$u)throw new RuntimeException('Administrator not found.');
    $db->prepare('UPDATE members SET mfa_secret_cipher=NULL,mfa_pending_cipher=NULL,mfa_pending_expires=NULL,mfa_last_counter=-1,auth_version=auth_version+1 WHERE id=?')->execute([$u['id']]);$db->prepare('DELETE FROM mfa_recovery_codes WHERE member_id=?')->execute([$u['id']]);$db->prepare("INSERT INTO audit_events (actor_id,action,subject) VALUES (NULL,'admin_mfa_reset',?)")->execute([(string)$u['id']]);$db->commit();echo "Two-step verification reset; all existing sessions revoked. Re-enroll after sign-in.\n";
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,"MFA reset failed: ".$e->getMessage()."\n");exit(1);}
