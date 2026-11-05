<?php
// Shared by the admin API and CLI scheduler. Only the current month is supported.
function runRoyalties(PDO $db,string $period): int {
    if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period)||$period!==date('Y-m'))throw new InvalidArgumentException('Only the current month can be run because eligibility uses current referrals.');
    $db->beginTransaction();
    try {
        $rules=$db->query("SELECT * FROM rules WHERE kind='royalty'")->fetchAll(PDO::FETCH_ASSOC);
        $members=$db->query('SELECT m.id,COUNT(c.id) AS direct_count FROM members m LEFT JOIN members c ON c.sponsor_id=m.id WHERE m.is_admin=0 GROUP BY m.id')->fetchAll(PDO::FETCH_ASSOC);
        $award=$db->prepare('INSERT IGNORE INTO royalty_awards (member_id,rule_id,period,amount) VALUES (?,?,?,?)');
        $wallet=$db->prepare('UPDATE members SET wallet=wallet+? WHERE id=?');
        $ledger=$db->prepare("INSERT INTO wallet_ledger (member_id,amount,kind,reference_id) VALUES (?,?,'royalty',?)");$count=0;
        foreach($rules as $r)foreach($members as $m)if((int)$m['direct_count'] >= (int)$r['threshold']){
            $award->execute([$m['id'],$r['id'],$period,$r['amount']]);if($award->rowCount()){$id=(int)$db->lastInsertId();if((float)$r['amount']>0){$wallet->execute([$r['amount'],$m['id']]);$ledger->execute([$m['id'],$r['amount'],$id]);}$count++;}
        }
        $db->commit();return $count;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
