<?php

namespace App\Support;

use App\Enums\ResponseCode;
use RuntimeException;

/**
 * Token 验证失败异常
 *
 * 携带应渲染的响应码，由认证中间件统一渲染为 401 错误信封：
 * 过期 → TOKEN_EXPIRED（20004），其余无效 → UNAUTHORIZED（20001）。
 */
class JwtException extends RuntimeException
{
    public function __construct(
        public readonly ResponseCode $responseCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Token 已过期
     */
    public static function expired(string $message = 'Token 已过期'): self
    {
        return new self(ResponseCode::TOKEN_EXPIRED, $message);
    }

    /**
     * Token 无效（格式损坏 / 签名不符 / 载荷异常等）
     */
    public static function invalid(string $message): self
    {
        return new self(ResponseCode::UNAUTHORIZED, $message);
    }
}
