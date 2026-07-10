<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\TraceIdMiddleware;
use App\Support\Result;
use App\Enums\ResponseCode;
use App\Exceptions\BusinessException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(TraceIdMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        /**
         * 参数验证异常
         */
        $exceptions->render(function (ValidationException $e, $request) {
            return Result::error(
                ResponseCode::PARAM_ERROR,
                collect($e->errors())->flatten()->first()
            );
        });

        /**
         * 未登录
         */
        $exceptions->render(function (AuthenticationException $e, $request) {
            return Result::error(ResponseCode::UNAUTHORIZED);
        });

        /**
         * 模型不存在
         */
        $exceptions->render(function (ModelNotFoundException $e, $request) {
            return Result::error(ResponseCode::DATA_NOT_FOUND);
        });

        /**
         * 路由不存在
         */
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            return Result::error(ResponseCode::DATA_NOT_FOUND, '接口不存在');
        });

        /**
         * 业务异常
         */
        $exceptions->render(function (BusinessException $e, $request) {
            return Result::error($e->codeEnum, $e->getMessage());
        });

        /**
         * 数据库异常
         */
        $exceptions->render(function (QueryException $e, $request) {
            Log::channel('exception')->error('数据库异常', [
                'trace_id' => $request->attributes->get('trace_id'),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
                'message' => $e->getMessage(),
            ]);

            return Result::error(ResponseCode::DATABASE_ERROR);
        });

        /**
         * 系统异常日志（兜底）
         */
        $exceptions->report(function (\Throwable $e) {
            Log::channel('exception')->error($e->getMessage(), [
                'trace_id' => request()->attributes->get('trace_id'),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        });

    })->create();
