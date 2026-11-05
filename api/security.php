<?php
// Authenticated encryption with record-specific associated data. Keep the key outside the database.
function encryptionKey(array $cfg): string {
    $key=base64_decode((string)($cfg['encryption_key']??''),true);
    if($key===false||strlen($key)!==32)throw new RuntimeException('A 32-byte base64 encryption key is required.');
    return $key;
}
function sealFields(array $fields,string $context): string {
    global $cfg;
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt(json_encode($fields,JSON_THROW_ON_ERROR),'aes-256-gcm',encryptionKey($cfg),OPENSSL_RAW_DATA,$iv,$tag,$context,16);
    if($cipher===false)throw new RuntimeException('Encryption failed.');
    return base64_encode($iv.$tag.$cipher);
}
function openFields(string $encoded,string $context): array {
    global $cfg;
    $raw=base64_decode($encoded,true);
    if($raw===false||strlen($raw)<29)throw new RuntimeException('Encrypted record is invalid.');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',encryptionKey($cfg),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$context);
    if($plain===false)throw new RuntimeException('Encrypted record could not be authenticated.');
    $out=json_decode($plain,true,512,JSON_THROW_ON_ERROR);if(!is_array($out))throw new RuntimeException('Encrypted record is invalid.');return $out;
}
function privateFields(array $row,string $cipherField,array $keys,string $context): array {
    if(!empty($row[$cipherField])){
        $values=openFields($row[$cipherField],$context);
        foreach($keys as $key)$row[$key]=(string)($values[$key]??'');
    } else {
        foreach($keys as $key)if(trim((string)($row[$key]??''))!=='')throw new RuntimeException('Legacy private data must be migrated before the website is used.');
        foreach($keys as $key)$row[$key]='';
    }
    unset($row[$cipherField]);return $row;
}
function privateMember(array $row): array {return privateFields($row,'sensitive_cipher',['address','kyc_number','bank_name','bank_account','bank_ifsc'],'member:'.$row['id']);}
function privatePayout(array $row): array {return privateFields($row,'bank_cipher',['bank_name','bank_account','bank_ifsc'],'payout:'.$row['id']);}
function auditEvent(?int $actor,string $action,string $subject=''): void {
    if(!$actor)return;
    query('INSERT INTO audit_events (actor_id,action,subject) VALUES (?,?,?)',[$actor,$action,substr($subject,0,160)]);
}

function auditAdminAction(?array $actor,string $action,array $data): void {
    $actions=['upload','save_page','delete_page','save_social','delete_social','save_media','delete_media','verify_kyc','payout_status','run_royalty','order_status','save_product','save_slide','save_settings','save_rule','delete_product','delete_slide','delete_rule','record_purchase','record_payout','create_staff','update_staff'];
    if(!$actor||empty($actor['is_admin'])||!in_array($action,$actions,true))return;
    $subject=(string)($data['id']??($data['member_code']??($data['period']??'')));
    try{auditEvent((int)$actor['id'],$action,$subject);}catch(Throwable $e){error_log('Audit recording failed: '.$e->getMessage());}
}
