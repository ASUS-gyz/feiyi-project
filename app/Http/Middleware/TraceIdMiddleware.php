<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TraceIdMiddleware
{
    /**
     * 为每个请求生成唯一TraceId
     */
    public function handle($request, Closure $next)
    {
        $traceId = (string) Str::uuid();

        /**
         * 存入当前Request对象
         */
        $request->attributes->set(
            'trace_id',
            $traceId
        );

        /**
         * 写入日志上下文
         */
        Log::withContext([
            'trace_id' => $traceId,
        ]);

        return $next($request);
    }
}
