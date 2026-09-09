<?php

/**
 * 限流策略与阈值（集中配置，环境变量可覆盖）
 *
 * max：窗口内最大请求数；decay：窗口秒数。
 * 已知约束：限流计数存于默认缓存驱动，单实例部署成立；
 * 未来多实例部署需切换共享缓存驱动（如 redis）。
 */
return [
    // 全站 API 兜底：每 IP
    'global' => [
        'max' => (int) env('THROTTLE_GLOBAL_MAX', 60),
        'decay' => (int) env('THROTTLE_GLOBAL_DECAY', 60),
    ],

    // 登录：仅计失败尝试；账号维度防爆破，IP 维度防换号撞库（throttle.login 中间件读取）
    'login' => [
        'max' => (int) env('THROTTLE_LOGIN_MAX', 5),
        'decay' => (int) env('THROTTLE_LOGIN_DECAY', 60),
        'ip_max' => (int) env('THROTTLE_LOGIN_IP_MAX', 10),
    ],

    // 注册：每 IP
    'auth-register' => [
        'max' => (int) env('THROTTLE_REGISTER_MAX', 5),
        'decay' => (int) env('THROTTLE_REGISTER_DECAY', 3600),
    ],

    // 上传：登录按用户，未认证回退 IP
    'upload' => [
        'max' => (int) env('THROTTLE_UPLOAD_MAX', 30),
        'decay' => (int) env('THROTTLE_UPLOAD_DECAY', 60),
    ],

    // AI 聊天：登录按账号、游客按 IP（throttle.user 中间件按身份取 key）
    'chat' => [
        'auth_max' => (int) env('THROTTLE_CHAT_AUTH_MAX', 10),
        'guest_max' => (int) env('THROTTLE_CHAT_GUEST_MAX', 3),
        'decay' => (int) env('THROTTLE_CHAT_DECAY', 60),
    ],

    // 捐赠创建：登录按用户，未认证回退 IP
    'donation' => [
        'max' => (int) env('THROTTLE_DONATION_MAX', 5),
        'decay' => (int) env('THROTTLE_DONATION_DECAY', 60),
    ],
];
