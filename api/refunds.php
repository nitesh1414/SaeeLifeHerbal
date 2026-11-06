<?php
// Monetary calculations are performed in integer paise. Call inside the request transaction.
function moneyCents(string $amount): int {
    if(!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/',$amount,$m))throw new InvalidArgumentException('Invalid amount.');
    return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}
function centsDecimal(int $cents): string {return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);}
function proportionalCents(int $amount,int $units,int $quantity): int {
    // Half-up rounding on the cumulative amount; differences between cumulative amounts
    // guarantee that a sequence of partial returns reverses exactly the original credit.
    return intdiv($amount*$units*2+$quantity,2*$quantity);
}
function recordRefund(string $action,array $data,array $actor): array {
    global $db;
    $id=(int)($data['id']??0);$ref=required($data,'refund_reference',100);$reason=required($data,'reason',500);
    $requested=null;if(isset($data['quantity'])){if(!is_scalar($data['quantity'])||!preg_match('/^[1-9][0-9]{0,4}$/',(string)$data['quantity']))fail('Refund quantity must be a positive whole number.');$requested=(int)$data['quantity'];}
    $amountText=required($data,'customer_amount',30);
    if(!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/',$amountText))fail('Enter a valid customer refund amount.');
    $customerCents=moneyCents($amountText);
    $db->beginTransaction();$order=null;
    if($action==='refund_order'){
        $order=one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id]);
        if(!$order||!in_array($order['status'],['confirmed','partially_refunded'],true))fail('Only confirmed or partially refunded orders can be refunded.');
        $purchases=query('SELECT * FROM purchases WHERE order_id=? ORDER BY id FOR UPDATE',[$id])->fetchAll();
    } else {
        $peek=one('SELECT order_id FROM purchases WHERE id=?',[$id]);if(!$peek)fail('Purchase not found.');
        if($peek['order_id']){$order=one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$peek['order_id']]);if(!$order||!in_array($order['status'],['confirmed','partially_refunded'],true))fail('Order cannot be refunded.');}
        $purchase=one('SELECT * FROM purchases WHERE id=? FOR UPDATE',[$id]);if(!$purchase)fail('Purchase not found.');$purchases=[$purchase];
    }
    $lines=[];$maxCents=0;
    foreach($purchases as $p){
        $total=(int)$p['quantity'];$prior=(int)one('SELECT COALESCE(SUM(quantity),0) n FROM refund_lines WHERE purchase_id=?',[$p['id']])['n'];$remaining=$total-$prior;
        if($total<1||$prior<0||$prior>$total)fail('Refund history is inconsistent. Reconcile before proceeding.');
        if($action==='refund_order')$qty=$remaining;else $qty=$requested??$remaining;
        if($qty<0||$qty>$remaining||($action==='refund_purchase'&&$qty<1))fail('Refund quantity exceeds the unrefunded quantity.');
        if($qty===0)continue;
        $maxCents+=moneyCents((string)$p['price'])*$qty;
        $lines[]=['purchase'=>$p,'before'=>$prior,'quantity'=>$qty,'total'=>$total];
    }
    if(!$lines)fail('No unrefunded quantity remains.');
    if($customerCents>$maxCents)fail('Customer refund cannot exceed the recorded value of returned units.');
    query('INSERT INTO refund_batches (refund_reference,reason,actor_id,customer_amount) VALUES (?,?,?,?)',[$ref,$reason,$actor['id'],centsDecimal($customerCents)]);$batch=(int)$db->lastInsertId();$reversals=[];
    foreach($lines as &$line){
        query('INSERT INTO refund_lines (purchase_id,batch_id,quantity) VALUES (?,?,?)',[$line['purchase']['id'],$batch,$line['quantity']]);$line['id']=(int)$db->lastInsertId();
        foreach(query("SELECT * FROM wallet_ledger WHERE reference_id=? AND kind IN ('direct_purchase','grand_purchase') ORDER BY member_id,id",[$line['purchase']['id']])->fetchAll() as $credit){
            $amount=moneyCents((string)$credit['amount']);$before=proportionalCents($amount,$line['before'],$line['total']);$after=proportionalCents($amount,$line['before']+$line['quantity'],$line['total']);
            if($after>$before)$reversals[]=['member_id'=>(int)$credit['member_id'],'original_id'=>(int)$credit['id'],'line_id'=>$line['id'],'amount'=>centsDecimal($after-$before)];
        }
    }unset($line);
    usort($reversals,fn($a,$b)=>$a['member_id']<=>$b['member_id']?:$a['original_id']<=>$b['original_id']);
    foreach($reversals as $r){$q=query('UPDATE members SET wallet=wallet-? WHERE id=? AND wallet>=?',[$r['amount'],$r['member_id'],$r['amount']]);if(!$q->rowCount())fail('A sponsor has insufficient available wallet balance. Reconcile before recording the refund.');query("INSERT INTO wallet_ledger (member_id,amount,kind,reference_id) VALUES (?,?,'purchase_reversal',?)",[$r['member_id'],'-'.$r['amount'],$r['line_id']]);$reversal=(int)$db->lastInsertId();query('INSERT INTO refund_ledger_links (refund_line_id,original_ledger_id,reversal_ledger_id) VALUES (?,?,?)',[$r['line_id'],$r['original_id'],$reversal]);}
    if($order){$all=query('SELECT p.id,p.quantity,COALESCE((SELECT SUM(r.quantity) FROM refund_lines r WHERE r.purchase_id=p.id),0) AS refunded FROM purchases p WHERE p.order_id=?',[$order['id']])->fetchAll();$complete=true;foreach($all as $item)if((int)$item['refunded']<(int)$item['quantity'])$complete=false;query('UPDATE orders SET status=? WHERE id=?',[$complete?'refunded':'partially_refunded',$order['id']]);}
    auditEvent((int)$actor['id'],$action,(string)$id);$db->commit();return ['ok'=>true,'batch_id'=>$batch];
}
