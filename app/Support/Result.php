<?php

namespace App\Support;

use App\Enums\ResponseCode;
use Illuminate\Http\JsonResponse;

/**
 * 统一响应类
 *
 * 所有接口必须通过 Result 返回数据
 */
class Result
{
    /**
     * 成功响应
     *
     * @param string $msg 提示信息
     * @param mixed $data 返回数据
     */
    public static function success(
        string $msg = '成功',
        mixed $data = null
    ): JsonResponse {
        return response()->json([
            'code' => ResponseCode::SUCCESS->value,
            'msg' => $msg,
            'data' => $data,
            'success' => true,

            // TraceId链路追踪ID
            'trace_id' => request()->attributes->get('trace_id'),
        ]);
    }

    /**
     * 失败响应
     *
     * @param ResponseCode $code 错误码枚举
     * @param string|null $msg 自定义错误消息
     * @param mixed $data 返回数据
     */
    public static function error(
        ResponseCode $code,
        ?string $msg = null,
        mixed $data = null
    ): JsonResponse {
        return response()->json([
            'code' => $code->value,
            'msg' => $msg ?? $code->msg(),
            'data' => $data,
            'success' => false,

            // TraceId链路追踪ID
            'trace_id' => request()->attributes->get('trace_id'),
        ]);
    }
}
