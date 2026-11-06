<?php
// Run only while the site and all workers are offline. See README for the recovery procedure.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc!==2){fwrite(STDERR,"Usage: php api/rotate-key.php /secure/path/new-config.php\n");exit(1);}
require __DIR__.'/security.php';
try {
    $old=require __DIR__.'/config.php';$new=require $argv[1];
    if(!is_array($old)||!is_array($new)||($old['dsn']??null)!==($new['dsn']??null)||($old['user']??null)!==($new['user']??null))throw new RuntimeException('Both configs must refer to the same database and user.');
    if(hash_equals(encryptionKey($old),encryptionKey($new)))throw new RuntimeException('The new key must differ from the old key.');
    $db=new PDO($old['dsn'],$old['user'],$old['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->beginTransaction();$cfg=$old;
    $members=$db->query('SELECT id,address,kyc_number,bank_name,bank_account,bank_ifsc,sensitive_cipher,mfa_secret_cipher,mfa_pending_cipher FROM members FOR UPDATE')->fetchAll();
    $save=$db->prepare('UPDATE members SET sensitive_cipher=? WHERE id=?');$mCount=0;
    foreach($members as $row){
        foreach(['address','kyc_number','bank_name','bank_account','bank_ifsc'] as $k)if(trim((string)($row[$k]??''))!=='')throw new RuntimeException('Legacy plaintext member data must be migrated first.');$cfg=$old;$value=privateMember($row);
        if(!empty($row['sensitive_cipher'])){$fields=[];foreach(['address','kyc_number','bank_name','bank_account','bank_ifsc'] as $k)$fields[$k]=$value[$k];$cfg=$new;$save->execute([sealFields($fields,'member:'.$row['id']),$row['id']]);$mCount++;}
        foreach(['mfa_secret_cipher'=>'mfa:','mfa_pending_cipher'=>'mfa-pending:'] as $column=>$prefix)if(!empty($row[$column])){$context=$prefix.$row['id'];$cfg=$old;$fields=openFields($row[$column],$context);$cfg=$new;$encrypted=sealFields($fields,$context);$db->prepare("UPDATE members SET $column=? WHERE id=?")->execute([$encrypted,$row['id']]);}
    }
    $cfg=$old;$payouts=$db->query('SELECT id,bank_name,bank_account,bank_ifsc,bank_cipher FROM payouts FOR UPDATE')->fetchAll();$save=$db->prepare('UPDATE payouts SET bank_cipher=? WHERE id=?');$pCount=0;
    foreach($payouts as $row){foreach(['bank_name','bank_account','bank_ifsc'] as $k)if(trim((string)($row[$k]??''))!=='')throw new RuntimeException('Legacy plaintext payout data must be migrated first.');$cfg=$old;$value=privatePayout($row);if(empty($row['bank_cipher']))continue;$fields=[];foreach(['bank_name','bank_account','bank_ifsc'] as $k)$fields[$k]=$value[$k];$cfg=$new;$save->execute([sealFields($fields,'payout:'.$row['id']),$row['id']]);$pCount++;}
    $log=$db->prepare("INSERT INTO audit_events (actor_id,action,subject) VALUES (NULL,'rotate_encryption_key',?)");$log->execute(["members=$mCount,payouts=$pCount"]);$db->commit();
    echo "Rotated $mCount member and $pCount payout records. Keep the site offline, update api/config.php to use the new key, verify and restore service.\n";
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,"Rotation failed: ".$e->getMessage()."\n");exit(1);}
