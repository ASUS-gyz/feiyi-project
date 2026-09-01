<?php

namespace App\Http\Middleware;

use App\Support\JWT;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OptionalJwtMiddleware
{
    /**
     * 处理请求 —— 可选 JWT 认证
     *
     * 携带合法 token 时绑定用户，无 token 或 token 无效时按游客放行。
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token) {
            try {
                $payload = JWT::decode($token);
                $userId  = $payload['sub'] ?? null;

                if ($userId) {
                    $user = \App\Models\User::find($userId);
                    if ($user && ! $user->is_deleted) {
                        $request->setUserResolver(function () use ($user) {
                            return $user;
                        });
                    }
                }
            } catch (\Throwable $e) {
                // 无效 token 当作游客处理，不阻断请求
            }
        }

        return $next($request);
    }
}
