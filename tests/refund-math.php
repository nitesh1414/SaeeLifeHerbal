<?php
// Run: php tests/refund-math.php
require __DIR__.'/../api/refunds.php';
if(moneyCents('10.01')!==1001 || moneyCents('9999999999.99')!==999999999999 || centsDecimal(3)!=='0.03')throw new RuntimeException('Money conversion failed');
foreach([[1,3],[5,3],[101,7],[10000000000,10000]] as [$amount,$quantity]){
    $sum=0;$last=0;
    for($n=1;$n<=$quantity;$n++){$current=proportionalCents($amount,$n,$quantity);if($current<$last)throw new RuntimeException('Non-monotonic refund');$sum+=$current-$last;$last=$current;}
    if($sum!==$amount)throw new RuntimeException('Partial reversals do not sum to the original credit');
}
echo "PASS: integer currency and cumulative partial-refund rounding\n";
