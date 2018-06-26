<?php

return [
    'default' => [     // 默认配置
        'driver' => 'mysql',   // pgsql(postgresql)
        'host' => '127.0.0.1',
        'port' => '3306',
        'username' => 'root',
        'password' => '123',
        'dbname' => 'local_kdm',
        'charset' => 'utf8',
        'pconnect' => false,
        'time_out' => 3,
        'prefix' => 'kdm_',
        'throw_exception' => true
    ]
];