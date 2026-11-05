<?php
// Named entry points share one shell and the same PHP API; content remains in MySQL.
// The fixed base keeps asset and API URLs relative to the installation root.
header('Content-Type: text/html; charset=utf-8');
$shell=file_get_contents(__DIR__.'/../index.html');
if($shell===false){http_response_code(500);exit('Website template unavailable.');}
echo str_replace('<base href="./">','<base href="../">',$shell);
