<?php
header('Content-Type: text/plain');

foreach (['RESEND_API_KEY', 'RESEND_FROM', 'MAIL_FROM'] as $k) {
    $g = getenv($k);
    echo $k . ': getenv=' . (($g !== false && $g !== '') ? 'SET (length ' . strlen($g) . ')' : 'EMPTY')
       . ' | $_SERVER=' . (!empty($_SERVER[$k]) ? 'SET' : 'EMPTY') . "\n";
}

echo "\nVariable names PHP can see (RESEND / MAIL / RAILWAY service info):\n";
foreach (array_keys(array_merge(getenv(), $_SERVER)) as $n) {
    if (preg_match('/RESEND|MAIL|RAILWAY_(SERVICE|ENVIRONMENT)/i', $n)) {
        echo "- $n\n";
    }
}