<?php

namespace App\Exceptions;

use App\Enums\ResponseCode;
use Exception;

/**
 * 业务异常类
 *
 * 用于 Service 层主动抛出业务异常，
 * 由统一异常处理器捕获并转换为标准响应。
 */
class BusinessException extends Exception
{
    /**
     * @param ResponseCode $codeEnum 错误码枚举
     * @param string|null $message 自定义错误消息
     */
    public function __construct(
        public readonly ResponseCode $codeEnum,
        ?string $message = null
    ) {
        parent::__construct($message ?? $codeEnum->msg());
    }
}
