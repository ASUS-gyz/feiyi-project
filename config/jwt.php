<?php

/**
 * JWT 认证配置
 */
return [
    /*
     * JWT 密钥（默认使用 APP_KEY）
     */
    'secret' => env('JWT_SECRET', env('APP_KEY')),

    /*
     * 签名算法
     * 支持：HS256, HS384, HS512
     */
    'algorithm' => env('JWT_ALGORITHM', 'HS256'),

    /*
     * Token 过期时间（秒）
     * 默认：86400 = 24 小时
     */
    'ttl' => (int) env('JWT_TTL', 86400),

    /*
     * Token 签发者
     */
    'issuer' => env('APP_NAME', 'Laravel'),
];
