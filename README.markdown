# PhpRedis

This is a phpredis branch for fixing redis pconnect "protocol error, got '&' as reply type byte"(usually because of timeout). It is not perfect because non-block io is not reliable, but it will reduce the probability of getting this error. see [test_redis.php](https://github.com/grepmusic/phpredis_fix_pconnect_protocol_error/blob/develop/test_redis.php).

这个项目是phpredis的一个分支, 旨在修复phpredis长连接(一般因为超时, 例如并发量较大时)的错误 "protocol error, got '&' as reply type byte", 目前的解决方案不是很完美, 因为异步io不可靠, 但是至少它能降低出现这个错误的概率. 原理是在pconnect后, 通过异步io立即从redis socket读出脏数据, 这样就不会影响后续的redis命令了. 更多见 [test_redis.php](https://github.com/grepmusic/phpredis_fix_pconnect_protocol_error/blob/develop/test_redis.php).

