<?php

namespace App\Http\Middleware;

use App\Enums\ResponseCode;
use App\Support\JWT;
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
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();

            if ($message === 'Token 已过期') {
                return Result::error(ResponseCode::TOKEN_EXPIRED);
            }

            return Result::error(ResponseCode::TOKEN_ERROR, $message);
        }

        // 从数据库中加载用户
        $userId = $payload['sub'] ?? null;
        if (!$userId) {
            return Result::error(ResponseCode::TOKEN_ERROR, 'Token 载荷无效');
        }

        $user = \App\Models\User::find($userId);
        if (!$user || $user->is_deleted) {
            return Result::error(ResponseCode::UNAUTHORIZED, '用户不存在或已被禁用');
        }

        // 将用户绑定到请求
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $next($request);
    }
}
