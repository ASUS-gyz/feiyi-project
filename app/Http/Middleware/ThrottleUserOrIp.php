<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 按请求身份限流：登录用户按账号、游客按 IP
 *
 * 与框架 throttle 的区别：框架默认优先级表会把 ThrottleRequests 重排到 jwt 中间件之前，
 * 导致限流时用户尚未绑定、登录用户被误归入游客维度；
 * 本中间件不在优先级表中，忠实保持路由声明顺序，须声明在 jwt 中间件之后。
 * 阈值见 config/throttle.php 对应配置段（$name 即配置键）。
 */
class ThrottleUserOrIp
{
    public function handle(Request $request, Closure $next, string $name): mixed
    {
        $key = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip();

        // 配置段可按身份区分阈值（auth_max/guest_max），未区分则统一用 max
        $max = $request->user()?->id
            ? (int) (config("throttle.$name.auth_max") ?? config("throttle.$name.max"))
            : (int) (config("throttle.$name.guest_max") ?? config("throttle.$name.max"));
        $decay = (int) config("throttle.$name.decay");

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => RateLimiter::availableIn($key),
                'X-RateLimit-Limit' => $max,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        $response = $next($request);

        RateLimiter::hit($key, $decay);

        $response->headers->add([
            'X-RateLimit-Limit' => $max,
            'X-RateLimit-Remaining' => max(0, $max - RateLimiter::attempts($key)),
            'X-RateLimit-Reset' => now()->getTimestamp() + RateLimiter::availableIn($key),
        ]);

        return $response;
    }
}
