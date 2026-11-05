<?php
// Extended CMS and member workflows. Included after authentication helpers and CSRF checks.
function richText(string $html): string {
    if (strlen($html)>50000) fail('Content is too long.');
    if (!class_exists('DOMDocument')) fail('The PHP DOM extension is required for rich text.',500);
    $doc=new DOMDocument();
    $previous=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $root=$doc->getElementsByTagName('div')->item(0);
    $allowed=['p','br','strong','b','em','i','u','h2','h3','h4','ul','ol','li','blockquote','a'];
    $walk=function($node) use (&$walk,$allowed) {
        for($i=$node->childNodes->length-1;$i>=0;$i--){
            $child=$node->childNodes->item($i);
            if($child->nodeType===XML_COMMENT_NODE){$node->removeChild($child);continue;}
            if($child->nodeType!==XML_ELEMENT_NODE)continue;
            $tag=strtolower($child->nodeName);
            if(!in_array($tag,$allowed,true)){
                if(in_array($tag,['script','style','iframe','object','svg','math'],true)){$node->removeChild($child);continue;}
                $walk($child); while($child->firstChild)$node->insertBefore($child->firstChild,$child);$node->removeChild($child);continue;
            }
            $href=$tag==='a'?$child->getAttribute('href'):'';
            while($child->attributes->length)$child->removeAttributeNode($child->attributes->item(0));
            if($tag==='a' && preg_match('~^(https://|mailto:|#)[^\s<>"\']*$~i',$href)){$child->setAttribute('href',$href);$child->setAttribute('rel','noopener noreferrer');}
            $walk($child);
        }
    };
    $walk($root);$out='';foreach($root->childNodes as $child)$out.=$doc->saveHTML($child);return $out;
}
function completeKyc(array $u): bool {foreach(['address','kyc_number','bank_name','bank_account','bank_ifsc'] as $key)if(trim((string)$u[$key])==='')return false;return true;}
function kycFingerprint(array $u): string {return hash('sha256',json_encode(array_map(fn($k)=>(string)($u[$k]??''),['address','kyc_number','bank_name','bank_account','bank_ifsc'])));}
function payoutRequest(array $u,string $amt): void {
    global $db;
    if((float)$amt<=0)fail('Amount must be positive.');
    $db->beginTransaction();$u=privateMember(one('SELECT * FROM members WHERE id=? FOR UPDATE',[$u['id']]));
    if(!completeKyc($u)||$u['kyc_status']!=='verified')fail('Complete and verified KYC and bank details are required.');
    if((float)$u['wallet']<(float)$amt)fail('Insufficient wallet balance.');
    query('UPDATE members SET wallet=wallet-? WHERE id=?',[$amt,$u['id']]);
    query("INSERT INTO payouts (member_id,amount,status) VALUES (?,?,'pending')",[$u['id'],$amt]);
    $id=(int)$db->lastInsertId();query('UPDATE payouts SET bank_cipher=? WHERE id=?',[sealFields(['bank_name'=>$u['bank_name'],'bank_account'=>$u['bank_account'],'bank_ifsc'=>$u['bank_ifsc']],'payout:'.$id),$id]);query("INSERT INTO wallet_ledger (member_id,amount,kind,reference_id) VALUES (?,?,'payout_request',?)",[$u['id'],'-'.$amt,$id]);$db->commit();
}
function handleFeature(string $action,array $data) {
    global $db;
    switch($action){
    case 'upload':
        admin(); if(empty($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK)fail('Select a file to upload.');
        $f=$_FILES['file']; if($f['size']<1||$f['size']>30*1024*1024)fail('File must be under 30 MB.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','video/webm'=>'webm'];
        if(!isset($types[$mime]))fail('Only JPEG, PNG, WebP, MP4 and WebM files are allowed.');
        $name=bin2hex(random_bytes(16)).'.'.$types[$mime];$dir=dirname(__DIR__).'/uploads/';
        if(!move_uploaded_file($f['tmp_name'],$dir.$name))fail('Upload failed. Check upload directory permissions.',500);
        return ['url'=>'uploads/'.$name,'type'=>str_starts_with($mime,'video/')?'video':'image'];
    case 'admin_staff': admin();return query('SELECT id,member_code,name,email,admin_role,is_active,created_at FROM members WHERE is_admin=1 ORDER BY id')->fetchAll();
    case 'create_staff':
        admin();$name=required($data,'name',160);$email=strtolower(required($data,'email',190));$password=required($data,'password',255);$role=$data['role']??'';
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12||!in_array($role,['content','operations','finance'],true))fail('Provide a valid email, role and password of at least 12 characters.');
        $code='ST'.strtoupper(bin2hex(random_bytes(5)));query('INSERT INTO members (member_code,name,email,password_hash,is_admin,admin_role) VALUES (?,?,?,?,1,?)',[$code,$name,$email,password_hash($password,PASSWORD_DEFAULT),$role]);return ['ok'=>true,'id'=>(int)$db->lastInsertId()];
    case 'update_staff':
        admin();$id=(int)($data['id']??0);$role=$data['role']??'';$active=$data['active']??null;if(!in_array($role,['content','operations','finance'],true)||!in_array($active,['0','1',0,1],true))fail('Invalid role or status.');
        $db->beginTransaction();$staff=one('SELECT id,is_admin,admin_role,is_active FROM members WHERE id=? FOR UPDATE',[$id]);if(!$staff||!$staff['is_admin']||$staff['admin_role']==='owner')fail('Only non-owner staff accounts can be changed here.');query('UPDATE members SET admin_role=?,is_active=?,auth_version=auth_version+1 WHERE id=?',[$role,(int)$active,$id]);$db->commit();return ['ok'=>true];
    case 'admin_orders': admin();return query("SELECT o.*,m.name,m.member_code, GROUP_CONCAT(CONCAT(pr.name, ' x ',oi.quantity) ORDER BY oi.id SEPARATOR ', ') AS items FROM orders o JOIN members m ON m.id=o.member_id JOIN order_items oi ON oi.order_id=o.id JOIN products pr ON pr.id=oi.product_id GROUP BY o.id ORDER BY o.id DESC")->fetchAll();
    case 'admin_refunds': admin();return query("SELECT b.*,m.member_code AS actor, (SELECT GROUP_CONCAT(CONCAT('Purchase #',r.purchase_id,' x ',r.quantity) ORDER BY r.id SEPARATOR ', ') FROM refund_lines r WHERE r.batch_id=b.id) AS items FROM refund_batches b JOIN members m ON m.id=b.actor_id ORDER BY b.id DESC")->fetchAll();
    case 'admin_audit': admin();return query('SELECT a.id,a.action,a.subject,a.created_at,m.member_code AS actor FROM audit_events a LEFT JOIN members m ON m.id=a.actor_id ORDER BY a.id DESC LIMIT 500')->fetchAll();
    case 'admin_purchases': admin();return query('SELECT p.*,m.member_code,pr.name AS product_name,(SELECT COALESCE(SUM(r.quantity),0) FROM refund_lines r WHERE r.purchase_id=p.id) AS refunded_quantity,(SELECT b.refund_reference FROM refund_lines r JOIN refund_batches b ON b.id=r.batch_id WHERE r.purchase_id=p.id ORDER BY r.id DESC LIMIT 1) AS refund_reference FROM purchases p JOIN members m ON m.id=p.buyer_id JOIN products pr ON pr.id=p.product_id ORDER BY p.id DESC')->fetchAll();
    case 'admin_pages': admin();return query('SELECT * FROM pages ORDER BY id')->fetchAll();
    case 'admin_socials': admin();return query('SELECT * FROM social_links ORDER BY id')->fetchAll();
    case 'admin_media': admin();return query('SELECT * FROM product_media ORDER BY position,id')->fetchAll();
    case 'member_dashboard':
        $u=logged();return ['user'=>cleanUser($u),'direct_count'=>one('SELECT COUNT(*) n FROM members WHERE sponsor_id=?',[$u['id']])['n'],'ledger'=>query('SELECT amount,kind,created_at FROM wallet_ledger WHERE member_id=? ORDER BY id DESC LIMIT 100',[$u['id']])->fetchAll(),'payouts'=>query('SELECT id,amount,status,created_at,note FROM payouts WHERE member_id=? ORDER BY id DESC LIMIT 100',[$u['id']])->fetchAll(),'orders'=>query('SELECT id,total,status,created_at FROM orders WHERE member_id=? ORDER BY id DESC LIMIT 100',[$u['id']])->fetchAll()];
    case 'save_page':
        admin();$id=(int)($data['id']??0);$slug=strtolower(required($data,'slug',80));if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)||in_array($slug,['home','products','product','login','register','admin','cart','forgot','reset','verify-email'],true))fail('Use an available lowercase page slug.');
        $values=[$slug,required($data,'title',160),trim((string)($data['subtitle']??'')),richText((string)($data['content']??'')),!empty($data['show_nav'])?1:0,!empty($data['published'])?1:0];
        if($id){$values[]=$id;query('UPDATE pages SET slug=?,title=?,subtitle=?,content=?,show_nav=?,published=? WHERE id=?',$values);}else query('INSERT INTO pages (slug,title,subtitle,content,show_nav,published) VALUES (?,?,?,?,?,?)',$values);return ['ok'=>true];
    case 'delete_page': admin();query('DELETE FROM pages WHERE id=?',[(int)($data['id']??0)]);return ['ok'=>true];
    case 'save_social':
        admin();$id=(int)($data['id']??0);$url=required($data,'url',500);if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower(parse_url($url,PHP_URL_SCHEME)??'')!=='https')fail('Social URL must be a valid HTTPS URL.');$icon=required($data,'icon',30);if(!in_array($icon,['facebook','instagram','youtube','x','linkedin','whatsapp','link'],true))fail('Invalid icon.');$values=[required($data,'name',80),$url,$icon];if($id){$values[]=$id;query('UPDATE social_links SET name=?,url=?,icon=? WHERE id=?',$values);}else query('INSERT INTO social_links (name,url,icon) VALUES (?,?,?)',$values);return ['ok'=>true];
    case 'delete_social': admin();query('DELETE FROM social_links WHERE id=?',[(int)($data['id']??0)]);return ['ok'=>true];
    case 'save_media':
        admin();$id=(int)($data['product_id']??0);if(!one('SELECT id FROM products WHERE id=?',[$id]))fail('Product not found.');$url=imageUrl(required($data,'url'));$type=$data['media_type']??'';if(!in_array($type,['image','video'],true))fail('Invalid media type.');query('INSERT INTO product_media (product_id,url,media_type,position) VALUES (?,?,?,?)',[$id,$url,$type,(int)($data['position']??0)]);return ['ok'=>true];
    case 'delete_media': admin();query('DELETE FROM product_media WHERE id=?',[(int)($data['id']??0)]);return ['ok'=>true];
    case 'request_verification':
        global $cfg;$u=logged();if(!empty($u['email_verified_at']))return ['ok'=>true];$url=rtrim((string)($cfg['public_url']??''),'/');$from=(string)($cfg['mail_from']??'');
        if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https'||parse_url($url,PHP_URL_QUERY)!==null||parse_url($url,PHP_URL_FRAGMENT)!==null||parse_url($url,PHP_URL_USER)!==null||!filter_var($from,FILTER_VALIDATE_EMAIL)||strpbrk($from,"\r\n")!==false)fail('Verification email is not configured.',503);
        $key=hash('sha256','verify|'.($_SERVER['REMOTE_ADDR']??'unknown').'|'.$u['id']);$attempt=one('SELECT attempts,(last_attempt > DATE_SUB(NOW(),INTERVAL 15 MINUTE)) AS recent FROM login_attempts WHERE attempt_key=?',[$key]);if($attempt&&(int)$attempt['attempts']>=3&&!empty($attempt['recent']))fail('Please wait before requesting another verification email.',429);
        query('INSERT INTO login_attempts (attempt_key,attempts,last_attempt) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt < DATE_SUB(NOW(),INTERVAL 15 MINUTE),1,attempts+1),last_attempt=NOW()',[$key]);
        $token=bin2hex(random_bytes(32));$db->beginTransaction();$u=one('SELECT id,email,email_verified_at,is_active FROM members WHERE id=? FOR UPDATE',[$u['id']]);if(!$u||!$u['is_active'])fail('Account unavailable.',403);if($u['email_verified_at']){$db->commit();return ['ok'=>true];}query('UPDATE email_verifications SET used_at=NOW() WHERE member_id=? AND used_at IS NULL',[$u['id']]);query('INSERT INTO email_verifications (member_id,email,token_hash,expires_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))',[$u['id'],$u['email'],hash('sha256',$token)]);$db->commit();
        $link=$url.'/#verify-email/'.$token;$message="Confirm your Saee Life Herbal email address using this link within 24 hours:\n\n".$link."\n\nIf you did not request this, ignore this message.";if(!@mail($u['email'],'Confirm your Saee Life Herbal email',$message,"From: ".$from."\r\nContent-Type: text/plain; charset=UTF-8")){error_log('Verification email delivery failed.');fail('Could not send verification email. Please try again later.',503);}return ['ok'=>true];
    case 'verify_email':
        $token=(string)($data['token']??'');if(!preg_match('/^[a-f0-9]{64}$/',$token))fail('Invalid or expired verification link.');$db->beginTransaction();$verification=one('SELECT * FROM email_verifications WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[hash('sha256',$token)]);if(!$verification)fail('Invalid or expired verification link.');$u=one('SELECT id,email,is_active FROM members WHERE id=? FOR UPDATE',[$verification['member_id']]);if(!$u||!$u['is_active']||strtolower($u['email'])!==strtolower($verification['email']))fail('Invalid or expired verification link.');query('UPDATE members SET email_verified_at=NOW() WHERE id=?',[$u['id']]);query('UPDATE email_verifications SET used_at=NOW() WHERE member_id=? AND used_at IS NULL',[$u['id']]);$db->commit();return ['ok'=>true];
    case 'forgot_password':
        global $cfg;$email=strtolower(required($data,'email',190));if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Provide a valid email address.');$url=rtrim((string)($cfg['public_url']??''),'/');$from=(string)($cfg['mail_from']??'');if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https'||parse_url($url,PHP_URL_QUERY)!==null||parse_url($url,PHP_URL_FRAGMENT)!==null||parse_url($url,PHP_URL_USER)!==null||!filter_var($from,FILTER_VALIDATE_EMAIL)||strpbrk($from,"\r\n")!==false)fail('Password recovery email is not configured.',503);
        $key=hash('sha256','reset|'.($_SERVER['REMOTE_ADDR']??'unknown').'|'.$email);$attempt=one('SELECT attempts,(last_attempt > DATE_SUB(NOW(),INTERVAL 15 MINUTE)) AS recent FROM login_attempts WHERE attempt_key=?',[$key]);if($attempt&&(int)$attempt['attempts']>=3&&!empty($attempt['recent']))return ['ok'=>true];
        query('INSERT INTO login_attempts (attempt_key,attempts,last_attempt) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt < DATE_SUB(NOW(),INTERVAL 15 MINUTE),1,attempts+1),last_attempt=NOW()',[$key]);$u=one('SELECT id,email FROM members WHERE email=? AND is_active=1',[$email]);
        if($u){$token=bin2hex(random_bytes(32));$db->beginTransaction();query('UPDATE password_resets SET used_at=NOW() WHERE member_id=? AND used_at IS NULL',[$u['id']]);query('INSERT INTO password_resets (member_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))',[$u['id'],hash('sha256',$token)]);$db->commit();$link=$url.'/#reset/'.$token;$message="Use this link to reset your Saee Life Herbal password within 30 minutes:\n\n".$link."\n\nIf you did not request this, ignore this message.";if(!@mail($u['email'],'Saee Life Herbal password reset',$message,"From: ".$from."\r\nContent-Type: text/plain; charset=UTF-8"))error_log('Password reset email delivery failed.');}
        return ['ok'=>true];
    case 'reset_password':
        $token=(string)($data['token']??'');$password=required($data,'password',255);if(!preg_match('/^[a-f0-9]{64}$/',$token)||strlen($password)<8)fail('Invalid or expired reset link, or password shorter than 8 characters.');$db->beginTransaction();$reset=one('SELECT * FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[hash('sha256',$token)]);if(!$reset)fail('Invalid or expired reset link.');$member=one('SELECT is_admin,is_active FROM members WHERE id=? FOR UPDATE',[$reset['member_id']]);if(!$member||!$member['is_active'])fail('Invalid or expired reset link.');if($member['is_admin']&&strlen($password)<12)fail('Staff passwords must be at least 12 characters.');query('UPDATE members SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$reset['member_id']]);query('UPDATE password_resets SET used_at=NOW() WHERE member_id=? AND used_at IS NULL',[$reset['member_id']]);$db->commit();return ['ok'=>true];
    case 'refund_order': case 'refund_purchase': return recordRefund($action,$data,admin());
    case 'place_order':
        $u=logged();if($u['is_admin'])fail('Admin accounts cannot place orders.');$address=required($data,'shipping_address',500);$phone=required($data,'contact_phone',40);$items=$data['items']??null;if(!is_array($items)||count($items)<1||count($items)>20)fail('Your basket must contain between 1 and 20 products.');
        $normalized=[];foreach($items as $item){$id=(int)($item['product_id']??0);$qty=(int)($item['quantity']??0);if($id<1||$qty<1||$qty>1000||isset($normalized[$id]))fail('Invalid basket.');$normalized[$id]=$qty;}
        $db->beginTransaction();$lines=[];$total=0;foreach($normalized as $id=>$qty){$p=one('SELECT * FROM products WHERE id=?',[$id]);if(!$p)fail('A product is no longer available.');if($p['is_sample'])fail('Sample products cannot be ordered.');$total+=(float)$p['price']*$qty;if($total>1000000000)fail('Order total is too large.');$lines[]=[$p,$qty];}
        query("INSERT INTO orders (member_id,shipping_address,contact_phone,total,status) VALUES (?,?,?,?,'pending')",[$u['id'],$address,$phone,number_format($total,2,'.','')]);$orderId=(int)$db->lastInsertId();foreach($lines as [$p,$qty])query('INSERT INTO order_items (order_id,product_id,quantity,unit_price,direct_commission,grand_commission) VALUES (?,?,?,?,?,?)',[$orderId,$p['id'],$qty,$p['price'],$p['direct_commission'],$p['grand_commission']]);$db->commit();return ['ok'=>true,'id'=>$orderId];
    case 'order_status':
        admin();$id=(int)($data['id']??0);$status=$data['status']??'';if(!in_array($status,['confirmed','cancelled'],true))fail('Invalid order status.');$db->beginTransaction();$order=one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id]);if(!$order||$order['status']!=='pending')fail('Only pending orders may be confirmed or cancelled.');
        if($status==='confirmed'){$ref=required($data,'receipt_reference',100);query('INSERT INTO sale_receipts (reference) VALUES (?)',[$ref]);$buyer=one('SELECT * FROM members WHERE id=?',[$order['member_id']]);$s=$buyer['sponsor_id']?one('SELECT * FROM members WHERE id=?',[$buyer['sponsor_id']]):null;foreach(query('SELECT * FROM order_items WHERE order_id=?',[$id])->fetchAll() as $line){query('INSERT INTO purchases (buyer_id,product_id,price,quantity,order_id) VALUES (?,?,?,?,?)',[$buyer['id'],$line['product_id'],$line['unit_price'],$line['quantity'],$id]);$pid=(int)$db->lastInsertId();if($s){credit((int)$s['id'],amount((float)$line['direct_commission']*(int)$line['quantity']),'direct_purchase',$pid);if($s['sponsor_id'])credit((int)$s['sponsor_id'],amount((float)$line['grand_commission']*(int)$line['quantity']),'grand_purchase',$pid);}}query('UPDATE orders SET status=?,receipt_reference=? WHERE id=?',[$status,$ref,$id]);}else query('UPDATE orders SET status=? WHERE id=?',[$status,$id]);$db->commit();return ['ok'=>true];
    case 'request_payout': $u=logged();if($u['is_admin'])fail('Admin accounts cannot request payouts.');payoutRequest($u,amount($data['amount']??null));return ['ok'=>true];
    case 'verify_kyc':
        admin();$id=(int)($data['id']??0);$status=$data['status']??'';if(!in_array($status,['verified','rejected'],true))fail('Invalid status.');$db->beginTransaction();$u=one('SELECT * FROM members WHERE id=? FOR UPDATE',[$id]);if(!$u||$u['is_admin'])fail('Member not found.');$u=privateMember($u);if(!hash_equals(kycFingerprint($u),(string)($data['fingerprint']??'')))fail('The details changed since review. Reload and review them again.');if($status==='verified'&&!completeKyc($u))fail('Member must complete all KYC and bank fields.');query('UPDATE members SET kyc_status=? WHERE id=?',[$status,$id]);$db->commit();return ['ok'=>true];
    case 'payout_status':
        admin();$id=(int)($data['id']??0);$status=$data['status']??'';$note=trim((string)($data['note']??''));if(strlen($note)>500)fail('Note is too long.');$db->beginTransaction();$p=one('SELECT * FROM payouts WHERE id=? FOR UPDATE',[$id]);if(!$p)fail('Payout not found.');$p=privatePayout($p);
        $allowed=['pending'=>['approved','rejected'],'approved'=>['paid','rejected']];if(!in_array($status,$allowed[$p['status']]??[],true))fail('Invalid payout transition.');
        if($status==='approved'||$status==='paid'){$u=one('SELECT * FROM members WHERE id=? FOR UPDATE',[$p['member_id']]);if($u)$u=privateMember($u);if(!$u||$u['kyc_status']!=='verified'||!completeKyc($u)||$u['bank_name']!==$p['bank_name']||$u['bank_account']!==$p['bank_account']||$u['bank_ifsc']!==$p['bank_ifsc'])fail('KYC must be verified and bank details must match the request. Reject and request again if details changed.');}
        if($status==='rejected')credit((int)$p['member_id'],$p['amount'],'payout_refund',$id);
        query('UPDATE payouts SET status=?,note=? WHERE id=?',[$status,$note,$id]);$db->commit();return ['ok'=>true];
    case 'run_royalty':
        admin();$period=required($data,'period',7);try{$awarded=runRoyalties($db,$period);}catch(InvalidArgumentException $e){fail($e->getMessage());}return ['ok'=>true,'awarded'=>$awarded];
    default: return null;
    }
}
