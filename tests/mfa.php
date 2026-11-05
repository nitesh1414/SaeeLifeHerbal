<?php
// RFC 6238 SHA-1 reference vector (six-digit form of the eight-digit value).
require __DIR__.'/../api/mfa.php';
$secret=base32Encode('12345678901234567890');
if(base32Decode($secret)!=='12345678901234567890')throw new RuntimeException('Base32 round trip failed');
if(totpAt($secret,1)!=='287082')throw new RuntimeException('TOTP test vector failed');
$timeCounter=intdiv(time(),30);$code=totpAt($secret,$timeCounter);
if(matchTotp($secret,$code,-1)!==$timeCounter||matchTotp($secret,$code,$timeCounter)!==null)throw new RuntimeException('TOTP validation or replay protection failed');
echo "PASS: Base32, TOTP reference vector and replay protection\n";
