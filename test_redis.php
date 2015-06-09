<?php

// in order to reproduce RedisException with message "protocol error, got '&' as reply type byte", can do
// env: redis & nginx + php-fpm(with phpredis)
// step 0: copy the php code to your server and save it as test_redis.php, put it under web root dir;
// step 1: temporarily set 'pm.max_children = 1' in your php-fpm.conf and restart php-fpm (kill -USR2 $PHP_FPM_MASTER_PID);
// step 2: visit or curl http://localhost/test_redis.php?s to set key "a" with 50M "&" characters; (url can be changed to where you can visit test_redis.php)
// step 3: visit or curl http://localhost/test_redis.php, you will got 'Fatal error: Maximum execution time of 1 second exceeded in xxxx' error, ok, it this error does not happen, visit it more times to get this error(or you can http://localhost/test_redis.php?ms=$ms , $ms should be close to 1000 and > LAST_MS and $ms < 1000), we are trying to make php to exit in the middle of Redis::get , so that redis socket does not read all data this time.
// step 4: visit or curl http://localhost/test_redis.php?ms=0, you will get "RedisException: protocol error, got '&' as reply type byte" because of the rest unread data of last request

// in step 3, we visit or curl http://localhost/test_redis.php , because we set max execution time to 1000 ms, and sleep 998 ms(possibly), when php executes " $r->get('a') ", php will execute the following pseudocode:
//   write(redis_sock_fd, request_packet, len); // send 'get' commands to redis
//    read(redis_sock_fd, response_packet, len); // len usually equals to 1024, read the first 1024 bytes from redis to get response, usually contains reply type and bytes('&' characters) we stored 
//    read(redis_sock_fd, response_packet, len); // len usually equals to 1024, read the next 1024 bytes from redis to get response
//    ... // read more data
//    read(redis_sock_fd, response_packet, len); // len usually equals to 1024  <--- interrupted by php max execution time, php reads N bytes('&' characters) (0 < N < 50 * 1024 * 1024) until now, the rest (50 * 1024 * 1024 - N) bytes are queued either in php stream or os tcp/ip kernel buffer because pconnect does close redis_sock
//  in step 4, we visit or curl http://localhost/test_redis.php?ms=0, php executes " $r->get('a') " without sleep, php will execute the following pseudocode:
//   write(redis_sock_fd, request_packet, len); // send 'get' commands to redis
//    read(redis_sock_fd, response_packet, len); // get "response"(the first len(=1024) bytes from the rest (50 * 1024 * 1024 - N) bytes of last request, it's not the response corresponding to get command in this request) from redis, phpredis got '&', which is wrong reply type, so it throws exception

// so we need to find a proper way to discard *dirty* data from last request because of timeout, we need read all dirty data either "after pconnect"(low performance, more reliable, actually it's more reliable that we read all dirty data before we executing any redis cmd) or "after close"(high performance, less reliable, or we can register it as a shutdown function so that it is reliable, you can uncomment line "register_shutdown_function ... ", then visit or curl http://localhost/test_redis.php?ms=10000 to froce timeout, you will got file /tmp/tmp_shutdown_out even though php throws an exception)
// or phpredis need to close redis socket when it reaches php timeout(I do not know whether a php extension can be notified if it exceeds maximum execution time or maximum memory etc. If it can be notified, phpredis can close redis socket to resolve protocol error issue.)

error_reporting(E_ALL);
ini_set('display_errors', 'on');

$r = new Redis;
$r->pconnect('127.0.0.1');

if(isset($_GET['s'])) { // step 2: set key "a" with 50M "&" characters
    $s = str_repeat('&', 50 * 1024 * 1024);
    $r->set('a', $s); // set key 'a' with 50M '&' characters
    echo 'set key "a" with 50M "&" characters: ' . ( $r->get('a') === $s ? 'ok' : 'failed' ) . "\n";
    exit;
}

set_time_limit(1); // set max execution time to 1000 ms

function sleep_ms($n) { // sleep up to $n ms
        $now = microtime(true);
        while(microtime(true) - $now < $n) {
        }
}

register_shutdown_function(function () { file_put_contents('/tmp/tmp_shutdown_out', time()); } ); // shutdown will execute even if php throws an exception

$t = microtime(true);

$ms = isset($_GET['ms']) ? (int)$_GET['ms'] : 998;
sleep_ms($ms / 1000); // sleep 998 ms to make php exited in the middle of Redis::get (sometimes)

try {
  echo 'length of get(a) is: ' . (int)strlen( $r->get('a') ) . "\n"; // ms=998: time consuming phrase, will exceed Maximum execution time of 1 second; or ms=0: we got the rest unread data of last request
  echo 'closed redis: ' . var_export( $r->close(), true ) . "\n";
} catch (RedisException $e) {
  $r->close();
  echo 'RedisException: ' . $e->getMessage() . "\n";
  // throws $e; // test for shutdown
}

echo 'It takes ' . floor((microtime(true) - $t) / 1000) . " ms from sleep_ms\n";

