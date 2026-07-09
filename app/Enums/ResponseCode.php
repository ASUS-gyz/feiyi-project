<?php

namespace App\Enums;

enum ResponseCode: int
{
    /**
     * 成功
     */
    case SUCCESS = 0;

    /**
     * 参数异常
     */
    case PARAM_ERROR = 10001;

    /**
     * 未登录
     */
    case UNAUTHORIZED = 20001;

    /**
     * 无权限
     */
    case FORBIDDEN = 20002;

    /**
     * 数据不存在
     */
    case DATA_NOT_FOUND = 30001;

    /**
     * 业务异常
     */
    case BUSINESS_ERROR = 40001;

    /**
     * 第三方接口异常
     */
    case THIRD_PARTY_ERROR = 50001;

    /**
     * 数据库异常
     */
    case DATABASE_ERROR = 60001;

    /**
     * 系统异常
     */
    case SYSTEM_ERROR = 90001;

    public function msg(): string
    {
        return match ($this) {
            self::SUCCESS => '成功',
            self::PARAM_ERROR => '参数错误',
            self::UNAUTHORIZED => '未登录',
            self::FORBIDDEN => '无权限访问',
            self::DATA_NOT_FOUND => '记录不存在',
            self::BUSINESS_ERROR => '业务处理失败',
            self::THIRD_PARTY_ERROR => '第三方服务异常',
            self::DATABASE_ERROR => '数据库异常',
            self::SYSTEM_ERROR => '系统异常',
        };
    }
}
