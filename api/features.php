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
    $db->beginTransaction();$u=one('SELECT * FROM members WHERE id=? FOR UPDATE',[$u['id']]);
    if(!completeKyc($u)||$u['kyc_status']!=='verified')fail('Complete and verified KYC and bank details are required.');
    if((float)$u['wallet']<(float)$amt)fail('Insufficient wallet balance.');
    query('UPDATE members SET wallet=wallet-? WHERE id=?',[$amt,$u['id']]);
    query("INSERT INTO payouts (member_id,amount,status,bank_name,bank_account,bank_ifsc) VALUES (?,?,'pending',?,?,?)",[$u['id'],$amt,$u['bank_name'],$u['bank_account'],$u['bank_ifsc']]);
    $id=(int)$db->lastInsertId();query("INSERT INTO wallet_ledger (member_id,amount,kind,reference_id) VALUES (?,?,'payout_request',?)",[$u['id'],'-'.$amt,$id]);$db->commit();
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
    case 'admin_orders': admin();return query("SELECT o.*,m.name,m.member_code, GROUP_CONCAT(CONCAT(pr.name, ' x ',oi.quantity) ORDER BY oi.id SEPARATOR ', ') AS items FROM orders o JOIN members m ON m.id=o.member_id JOIN order_items oi ON oi.order_id=o.id JOIN products pr ON pr.id=oi.product_id GROUP BY o.id ORDER BY o.id DESC")->fetchAll();
    case 'admin_purchases': admin();return query('SELECT p.*,m.member_code,pr.name AS product_name FROM purchases p JOIN members m ON m.id=p.buyer_id JOIN products pr ON pr.id=p.product_id ORDER BY p.id DESC')->fetchAll();
    case 'admin_pages': admin();return query('SELECT * FROM pages ORDER BY id')->fetchAll();
    case 'admin_socials': admin();return query('SELECT * FROM social_links ORDER BY id')->fetchAll();
    case 'admin_media': admin();return query('SELECT * FROM product_media ORDER BY position,id')->fetchAll();
    case 'member_dashboard':
        $u=logged();return ['user'=>cleanUser($u),'direct_count'=>one('SELECT COUNT(*) n FROM members WHERE sponsor_id=?',[$u['id']])['n'],'ledger'=>query('SELECT amount,kind,created_at FROM wallet_ledger WHERE member_id=? ORDER BY id DESC LIMIT 100',[$u['id']])->fetchAll(),'payouts'=>query('SELECT id,amount,status,created_at,note FROM payouts WHERE member_id=? ORDER BY id DESC LIMIT 100',[$u['id']])->fetchAll(),'orders'=>query('SELECT id,total,status,created_at FROM orders WHERE member_id=? ORDER BY id DESC LIMIT 100',[$u['id']])->fetchAll()];
    case 'save_page':
        admin();$id=(int)($data['id']??0);$slug=strtolower(required($data,'slug',80));if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)||in_array($slug,['home','products','product','login','register','admin'],true))fail('Use an available lowercase page slug.');
        $values=[$slug,required($data,'title',160),trim((string)($data['subtitle']??'')),richText((string)($data['content']??'')),!empty($data['show_nav'])?1:0,!empty($data['published'])?1:0];
        if($id){$values[]=$id;query('UPDATE pages SET slug=?,title=?,subtitle=?,content=?,show_nav=?,published=? WHERE id=?',$values);}else query('INSERT INTO pages (slug,title,subtitle,content,show_nav,published) VALUES (?,?,?,?,?,?)',$values);return ['ok'=>true];
    case 'delete_page': admin();query('DELETE FROM pages WHERE id=?',[(int)($data['id']??0)]);return ['ok'=>true];
    case 'save_social':
        admin();$id=(int)($data['id']??0);$url=required($data,'url',500);if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower(parse_url($url,PHP_URL_SCHEME)??'')!=='https')fail('Social URL must be a valid HTTPS URL.');$icon=required($data,'icon',30);if(!in_array($icon,['facebook','instagram','youtube','x','linkedin','whatsapp','link'],true))fail('Invalid icon.');$values=[required($data,'name',80),$url,$icon];if($id){$values[]=$id;query('UPDATE social_links SET name=?,url=?,icon=? WHERE id=?',$values);}else query('INSERT INTO social_links (name,url,icon) VALUES (?,?,?)',$values);return ['ok'=>true];
    case 'delete_social': admin();query('DELETE FROM social_links WHERE id=?',[(int)($data['id']??0)]);return ['ok'=>true];
    case 'save_media':
        admin();$id=(int)($data['product_id']??0);if(!one('SELECT id FROM products WHERE id=?',[$id]))fail('Product not found.');$url=imageUrl(required($data,'url'));$type=$data['media_type']??'';if(!in_array($type,['image','video'],true))fail('Invalid media type.');query('INSERT INTO product_media (product_id,url,media_type,position) VALUES (?,?,?,?)',[$id,$url,$type,(int)($data['position']??0)]);return ['ok'=>true];
    case 'delete_media': admin();query('DELETE FROM product_media WHERE id=?',[(int)($data['id']??0)]);return ['ok'=>true];
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
        admin();$id=(int)($data['id']??0);$status=$data['status']??'';if(!in_array($status,['verified','rejected'],true))fail('Invalid status.');$db->beginTransaction();$u=one('SELECT * FROM members WHERE id=? FOR UPDATE',[$id]);if(!$u||$u['is_admin'])fail('Member not found.');if(!hash_equals(kycFingerprint($u),(string)($data['fingerprint']??'')))fail('The details changed since review. Reload and review them again.');if($status==='verified'&&!completeKyc($u))fail('Member must complete all KYC and bank fields.');query('UPDATE members SET kyc_status=? WHERE id=?',[$status,$id]);$db->commit();return ['ok'=>true];
    case 'payout_status':
        admin();$id=(int)($data['id']??0);$status=$data['status']??'';$note=trim((string)($data['note']??''));if(strlen($note)>500)fail('Note is too long.');$db->beginTransaction();$p=one('SELECT * FROM payouts WHERE id=? FOR UPDATE',[$id]);if(!$p)fail('Payout not found.');
        $allowed=['pending'=>['approved','rejected'],'approved'=>['paid','rejected']];if(!in_array($status,$allowed[$p['status']]??[],true))fail('Invalid payout transition.');
        if($status==='approved'||$status==='paid'){$u=one('SELECT * FROM members WHERE id=? FOR UPDATE',[$p['member_id']]);if(!$u||$u['kyc_status']!=='verified'||!completeKyc($u)||$u['bank_name']!==$p['bank_name']||$u['bank_account']!==$p['bank_account']||$u['bank_ifsc']!==$p['bank_ifsc'])fail('KYC must be verified and bank details must match the request. Reject and request again if details changed.');}
        if($status==='rejected')credit((int)$p['member_id'],$p['amount'],'payout_refund',$id);
        query('UPDATE payouts SET status=?,note=? WHERE id=?',[$status,$note,$id]);$db->commit();return ['ok'=>true];
    case 'run_royalty':
        admin();$period=required($data,'period',7);if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period)||$period!==date('Y-m'))fail('Only the current month can be run because eligibility uses current referrals.');
        $db->beginTransaction();$rules=query("SELECT * FROM rules WHERE kind='royalty'")->fetchAll();$members=query('SELECT m.id,COUNT(c.id) AS direct_count FROM members m LEFT JOIN members c ON c.sponsor_id=m.id WHERE m.is_admin=0 GROUP BY m.id')->fetchAll();$awarded=0;
        foreach($rules as $r)foreach($members as $m)if((int)$m['direct_count'] >= (int)$r['threshold']){$q=query('INSERT IGNORE INTO royalty_awards (member_id,rule_id,period,amount) VALUES (?,?,?,?)',[$m['id'],$r['id'],$period,$r['amount']]);if($q->rowCount()){credit((int)$m['id'],$r['amount'],'royalty',(int)$db->lastInsertId());$awarded++;}}
        $db->commit();return ['ok'=>true,'awarded'=>$awarded];
    default: return null;
    }
}
