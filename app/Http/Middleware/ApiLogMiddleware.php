<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * API 请求日志（接口级监控）
 *
 * 记录所有 HTTP API 请求的地址、方法、来源、耗时与状态码，写入 api 通道，
 * 用于性能分析与接口监控。须注册在 TraceIdMiddleware 之后，trace_id 随
 * 日志上下文自动携带。
 */
class ApiLogMiddleware
{
    /**
     * 不落日志的敏感字段（手册：日志禁止记录 password / token）
     */
    private const SENSITIVE_KEYS = ['token', 'authorization'];

    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        $response = $next($request);

        Log::channel('api')->info('API请求记录', [
            'trace_id' => $request->attributes->get('trace_id'),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_id' => $request->user()?->id,
            'request' => $this->sanitize($request->all()),
            'response_status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ]);

        return $response;
    }

    /**
     * 敏感字段置为 ***；文件字段只记元数据，不落二进制内容
     */
    private function sanitize(mixed $input): mixed
    {
        if (is_array($input)) {
            $clean = [];

            foreach ($input as $key => $value) {
                if ($this->isSensitive((string) $key)) {
                    $clean[$key] = '***';
                } elseif ($value instanceof UploadedFile) {
                    $clean[$key] = [
                        'name' => $value->getClientOriginalName(),
                        'size' => $value->getSize(),
                        'mime' => $value->getClientMimeType(),
                    ];
                } else {
                    $clean[$key] = $this->sanitize($value);
                }
            }

            return $clean;
        }

        return $input;
    }

    private function isSensitive(string $key): bool
    {
        if (str_contains(strtolower($key), 'password')) {
            return true;
        }

        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }
}
