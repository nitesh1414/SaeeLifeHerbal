<?php
// Run only from CLI, once after migration-003, while the website is offline.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/security.php';
try {
    $cfg=require __DIR__.'/config.php';encryptionKey($cfg);
    $db=new PDO($cfg['dsn'],$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->beginTransaction();$members=$db->query('SELECT id,address,kyc_number,bank_name,bank_account,bank_ifsc,sensitive_cipher FROM members FOR UPDATE')->fetchAll();
    $save=$db->prepare("UPDATE members SET sensitive_cipher=?, address=NULL,kyc_number=NULL,bank_name=NULL,bank_account=NULL,bank_ifsc=NULL WHERE id=?");
    foreach($members as $row){$values=[];foreach(['address','kyc_number','bank_name','bank_account','bank_ifsc'] as $k)$values[$k]=(string)($row[$k]??'');$has=(bool)array_filter($values,fn($v)=>$v!=='');if($has&&!empty($row['sensitive_cipher']))throw new RuntimeException('A member has both encrypted and legacy values; resolve manually.');if($has)$save->execute([sealFields($values,'member:'.$row['id']),$row['id']]);}
    $payouts=$db->query('SELECT id,bank_name,bank_account,bank_ifsc,bank_cipher FROM payouts FOR UPDATE')->fetchAll();
    $save=$db->prepare("UPDATE payouts SET bank_cipher=?, bank_name='',bank_account='',bank_ifsc='' WHERE id=?");
    foreach($payouts as $row){$values=[];foreach(['bank_name','bank_account','bank_ifsc'] as $k)$values[$k]=(string)($row[$k]??'');$has=(bool)array_filter($values,fn($v)=>$v!=='');if($has&&!empty($row['bank_cipher']))throw new RuntimeException('A payout has both encrypted and legacy values; resolve manually.');if($has)$save->execute([sealFields($values,'payout:'.$row['id']),$row['id']]);}
    $db->commit();echo "Private data encrypted. Back up the encryption key separately from the database.\n";
} catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,"Migration failed: ".$e->getMessage()."\n");exit(1);}
