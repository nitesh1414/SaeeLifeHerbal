<?php
// RFC 6238 SHA-1 / 30-second TOTP with a one-step drift window and replay protection.
function base32Encode(string $bytes): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits=0;$value=0;$out='';
    for($i=0;$i<strlen($bytes);$i++){$value=($value<<8)|ord($bytes[$i]);$bits+=8;while($bits>=5){$bits-=5;$out.=$alphabet[($value>>$bits)&31];}$value &= (1<<$bits)-1;}
    if($bits)$out.=$alphabet[($value<<(5-$bits))&31];return $out;
}
function base32Decode(string $encoded): string {
    if(!preg_match('/^[A-Z2-7]+$/',$encoded))throw new RuntimeException('Invalid authenticator secret.');
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits=0;$value=0;$out='';
    for($i=0;$i<strlen($encoded);$i++){$value=($value<<5)|strpos($alphabet,$encoded[$i]);$bits+=5;if($bits>=8){$bits-=8;$out.=chr(($value>>$bits)&255);}$value &= (1<<$bits)-1;}
    return $out;
}
function totpAt(string $secret,int $counter): string {
    $message=pack('N2',intdiv($counter,4294967296),$counter%4294967296);$digest=hash_hmac('sha1',$message,base32Decode($secret),true);$offset=ord($digest[19])&15;
    $number=((ord($digest[$offset])&127)<<24)|(ord($digest[$offset+1])<<16)|(ord($digest[$offset+2])<<8)|ord($digest[$offset+3]);return str_pad((string)($number%1000000),6,'0',STR_PAD_LEFT);
}
function matchTotp(string $secret,string $code,int $lastCounter): ?int {
    if(!preg_match('/^\d{6}$/',$code))return null;$now=intdiv(time(),30);
    for($step=-1;$step<=1;$step++){$counter=$now+$step;if($counter>$lastCounter&&hash_equals(totpAt($secret,$counter),$code))return $counter;}return null;
}
function mfaAdmin(): array {$u=logged();if(empty($u['is_admin']))fail('Administrator access required.',403);return $u;}
function mfaThrottle(int $id): string {
    $key=hash('sha256','mfa|'.($_SERVER['REMOTE_ADDR']??'unknown').'|'.$id);$attempt=one('SELECT attempts,(last_attempt > DATE_SUB(NOW(),INTERVAL 15 MINUTE)) AS recent FROM login_attempts WHERE attempt_key=?',[$key]);
    if($attempt&&(int)$attempt['attempts']>=5&&!empty($attempt['recent']))fail('Too many verification attempts. Try again in 15 minutes.',429);
    query('INSERT INTO login_attempts (attempt_key,attempts,last_attempt) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt < DATE_SUB(NOW(),INTERVAL 15 MINUTE),1,attempts+1),last_attempt=NOW()',[$key]);return $key;
}
function checkMfaCode(array $u,string $code): bool {
    $secret=openFields($u['mfa_secret_cipher'],'mfa:'.$u['id'])['secret']??'';$counter=matchTotp($secret,$code,(int)$u['mfa_last_counter']);
    if($counter!==null){query('UPDATE members SET mfa_last_counter=? WHERE id=?',[$counter,$u['id']]);return true;}
    if(preg_match('/^[a-f0-9]{16}$/',$code)){$q=query('DELETE FROM mfa_recovery_codes WHERE member_id=? AND code_hash=?',[$u['id'],hash('sha256',$code)]);if($q->rowCount())return true;}
    return false;
}
function handleMfa(string $action,array $data) {
    global $db;
    switch($action){
    case 'admin_security': $u=mfaAdmin();return ['enabled'=>!empty($u['mfa_secret_cipher'])];
    case 'mfa_begin':
        $u=mfaAdmin();$key=mfaThrottle((int)$u['id']);if(!password_verify((string)($data['password']??''),$u['password_hash']))fail('Invalid credentials.',401);if(!empty($u['mfa_secret_cipher']))fail('Two-step verification is already enabled.');
        $secret=base32Encode(random_bytes(20));$q=query('UPDATE members SET mfa_pending_cipher=?,mfa_pending_expires=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=? AND auth_version=? AND is_active=1 AND mfa_secret_cipher IS NULL',[sealFields(['secret'=>$secret],'mfa-pending:'.$u['id']),$u['id'],$u['auth_version']]);if(!$q->rowCount())fail('Account changed. Sign in again.',401);query('DELETE FROM login_attempts WHERE attempt_key=?',[$key]);auditEvent((int)$u['id'],'mfa_begin');return ['secret'=>$secret,'email'=>$u['email']];
    case 'mfa_enable':
        $u=mfaAdmin();$key=mfaThrottle((int)$u['id']);$db->beginTransaction();$u=one('SELECT *, (mfa_pending_expires>NOW()) AS pending_valid FROM members WHERE id=? FOR UPDATE',[$u['id']]);if(!$u||!$u['is_active']||(int)$u['auth_version']!==(int)$_SESSION['auth_version']||!empty($u['mfa_secret_cipher'])||empty($u['mfa_pending_cipher'])||empty($u['pending_valid']))fail('Setup expired. Begin again.');
        $secret=openFields($u['mfa_pending_cipher'],'mfa-pending:'.$u['id'])['secret']??'';$counter=matchTotp($secret,(string)($data['code']??''),-1);if($counter===null){$db->rollBack();fail('Invalid authenticator code.',401);}
        $codes=[];for($i=0;$i<10;$i++){$code=bin2hex(random_bytes(8));$codes[]=$code;query('INSERT INTO mfa_recovery_codes (member_id,code_hash) VALUES (?,?)',[$u['id'],hash('sha256',$code)]);}
        query('UPDATE members SET mfa_secret_cipher=?,mfa_pending_cipher=NULL,mfa_pending_expires=NULL,mfa_last_counter=?,auth_version=auth_version+1 WHERE id=?',[sealFields(['secret'=>$secret],'mfa:'.$u['id']),$counter,$u['id']]);auditEvent((int)$u['id'],'mfa_enabled');$db->commit();query('DELETE FROM login_attempts WHERE attempt_key=?',[$key]);$_SESSION['auth_version']=(int)$u['auth_version']+1;return ['recovery_codes'=>$codes];
    case 'mfa_disable':
        $u=mfaAdmin();$key=mfaThrottle((int)$u['id']);$db->beginTransaction();$u=one('SELECT * FROM members WHERE id=? FOR UPDATE',[$u['id']]);if(!$u||!$u['is_active']||(int)$u['auth_version']!==(int)$_SESSION['auth_version']||!password_verify((string)($data['password']??''),$u['password_hash']))fail('Invalid credentials.',401);if(empty($u['mfa_secret_cipher']))fail('Two-step verification is not enabled.');if(!checkMfaCode($u,strtolower(trim((string)($data['code']??''))))){$db->rollBack();fail('Invalid or already used code.',401);}query('UPDATE members SET mfa_secret_cipher=NULL,mfa_pending_cipher=NULL,mfa_pending_expires=NULL,mfa_last_counter=-1,auth_version=auth_version+1 WHERE id=?',[$u['id']]);query('DELETE FROM mfa_recovery_codes WHERE member_id=?',[$u['id']]);auditEvent((int)$u['id'],'mfa_disabled');$db->commit();query('DELETE FROM login_attempts WHERE attempt_key=?',[$key]);$_SESSION['auth_version']=(int)$u['auth_version']+1;return ['ok'=>true];
    case 'mfa_finish_login':
        $id=(int)($_SESSION['pending_mfa_uid']??0);$version=(int)($_SESSION['pending_mfa_version']??-1);if(!$id||($_SESSION['pending_mfa_expires']??0)<time())fail('Sign-in challenge expired. Sign in again.',401);
        $key=mfaThrottle($id);$db->beginTransaction();$u=one('SELECT * FROM members WHERE id=? FOR UPDATE',[$id]);if(!$u||!$u['is_active']||!$u['is_admin']||(int)$u['auth_version']!==$version||empty($u['mfa_secret_cipher']))fail('Sign-in challenge expired. Sign in again.',401);
        if(!checkMfaCode($u,strtolower(trim((string)($data['code']??''))))){$db->rollBack();fail('Invalid or already used code.',401);}auditEvent($id,'mfa_login');$db->commit();query('DELETE FROM login_attempts WHERE attempt_key=?',[$key]);session_regenerate_id(true);$_SESSION=['uid'=>$id,'auth_version'=>$version,'csrf'=>bin2hex(random_bytes(32))];return ['user'=>cleanUser($u),'csrf'=>$_SESSION['csrf']];
    default:return null;
    }
}
