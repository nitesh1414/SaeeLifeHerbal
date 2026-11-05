<?php
// No database or production key is used. Run: php tests/crypto.php
require __DIR__.'/../api/security.php';
$cfg=['encryption_key'=>base64_encode(str_repeat('t',32))];
$original=['bank_account'=>'001234','kyc_number'=>'DOC'];$encoded=sealFields($original,'member:42');
if(openFields($encoded,'member:42')!==$original)throw new RuntimeException('Round trip failed');
$rejected=0;foreach([['cipher'=>$encoded,'context'=>'member:43'],['cipher'=>base64_encode(substr_replace(base64_decode($encoded),"x",30,1)),'context'=>'member:42']] as $test){try{openFields($test['cipher'],$test['context']);}catch(Throwable $e){$rejected++;}}
if($rejected!==2)throw new RuntimeException('Tampered ciphertext was accepted');
$cfg['encryption_key']=base64_encode(str_repeat('x',32));try{openFields($encoded,'member:42');throw new RuntimeException('Wrong key was accepted');}catch(RuntimeException $e){if($e->getMessage()==='Wrong key was accepted')throw $e;}
echo "PASS: encryption round trip, associated data, tampering and wrong-key rejection\n";
