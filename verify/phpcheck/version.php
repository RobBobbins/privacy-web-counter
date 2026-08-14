<?php
header('Content-Type: text/plain; charset=utf-8');
echo "PHP version   : " . PHP_VERSION . "\n";
echo "SAPI          : " . php_sapi_name() . "\n";
echo "Server        : " . (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'unknown') . "\n";
echo "short_open_tag: " . (ini_get('short_open_tag') ? 'On' : 'Off') . "\n";
