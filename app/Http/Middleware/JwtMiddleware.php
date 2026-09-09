<?php

namespace App\Http\Middleware;

use App\Enums\ResponseCode;
use App\Support\JWT;
use App\Support\JwtException;
use App\Support\Result;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtMiddleware
{
    /**
     * 处理请求 —— 验证 JWT Token
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return Result::error(ResponseCode::UNAUTHORIZED);
        }

        try {
            $payload = JWT::decode($token);
        } catch (JwtException $e) {
            return Result::error($e->responseCode, $e->getMessage());
        }

        // 从数据库中加载用户
        $userId = $payload['sub'] ?? null;
        if (!$userId) {
            return Result::error(ResponseCode::UNAUTHORIZED, 'Token 载荷无效');
        }

        $user = \App\Models\User::find($userId);
        if (!$user || $user->is_deleted) {
            return Result::error(ResponseCode::UNAUTHORIZED, '用户不存在或已被禁用');
        }

        // 改密失效：token 签发时间不晚于最近一次改密时间 → 按登录过期处理
        // （改密响应中续期 token 的 iat 取改密秒 +1，不受此判定影响）
        if ($user->pwd_changed_at !== null && (int) ($payload['iat'] ?? 0) <= $user->pwd_changed_at->getTimestamp()) {
            return Result::error(ResponseCode::TOKEN_EXPIRED);
        }

        // 将用户绑定到请求
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $next($request);
    }
}
