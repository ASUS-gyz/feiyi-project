<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 登录限流：仅统计失败尝试，按「IP+账号」与「IP」双维度限制
 *
 * 单账号暴力破解由账号维度拦截，同 IP 换号轮询/撞库由 IP 维度兜底拦截；
 * 成功登录不消耗配额并清零账号维度计数（IP 维度不清零），
 * 正常用户偶尔输错重试不受影响。阈值见 config/throttle.php 的 login。
 */
class ThrottleFailedLogins
{
    public function handle(Request $request, Closure $next): mixed
    {
        $accountKey = $this->accountKey($request);
        $ipKey = $this->ipKey($request);
        $max = (int) config('throttle.login.max');
        $ipMax = (int) config('throttle.login.ip_max');

        if (RateLimiter::tooManyAttempts($ipKey, $ipMax)) {
            throw $this->throttleException($ipKey, $ipMax);
        }

        if (RateLimiter::tooManyAttempts($accountKey, $max)) {
            throw $this->throttleException($accountKey, $max);
        }

        $response = $next($request);

        // 登录成功不消耗配额，并清零账号维度失败计数
        $payload = $response instanceof \Illuminate\Http\JsonResponse ? $response->getData(true) : null;
        if ($response->getStatusCode() === 200 && is_array($payload) && ($payload['code'] ?? null) === 0) {
            RateLimiter::clear($accountKey);

            return $response;
        }

        $decay = (int) config('throttle.login.decay');
        RateLimiter::hit($accountKey, $decay);
        RateLimiter::hit($ipKey, $decay);

        return $response;
    }

    /**
     * 账号维度：IP + 目标账号（用户名小写归一，防大小写变体绕过）
     */
    private function accountKey(Request $request): string
    {
        return 'login|'.$request->ip().'|'.mb_strtolower(trim((string) $request->input('username')));
    }

    /**
     * IP 维度：同 IP 换号轮询/撞库的兜底约束
     */
    private function ipKey(Request $request): string
    {
        return 'login-ip|'.$request->ip();
    }

    private function throttleException(string $key, int $max): ThrottleRequestsException
    {
        return new ThrottleRequestsException('Too Many Attempts.', null, [
            'Retry-After' => RateLimiter::availableIn($key),
            'X-RateLimit-Limit' => $max,
            'X-RateLimit-Remaining' => 0,
        ]);
    }
}
