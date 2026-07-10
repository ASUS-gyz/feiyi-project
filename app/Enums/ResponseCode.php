<?php

namespace App\Enums;

enum ResponseCode: int
{
    /**
     * 成功
     */
    case SUCCESS = 0;

    /**
     * 数据已存在
     */
    case DATA_EXISTS = 3;

    /**
     * 参数异常
     */
    case PARAM_ERROR = 10001;

    /**
     * 必填参数缺失
     */
    case PARAM_MISSING = 10002;

    /**
     * 参数格式错误
     */
    case PARAM_FORMAT_ERROR = 10003;

    /**
     * 参数超出范围
     */
    case PARAM_OUT_OF_RANGE = 10004;

    /**
     * 非法参数
     */
    case PARAM_INVALID = 10005;

    /**
     * 未登录
     */
    case UNAUTHORIZED = 20001;

    /**
     * Token 失效
     */
    case TOKEN_INVALID = 20002;

    /**
     * Token 错误
     */
    case TOKEN_ERROR = 20003;

    /**
     * 登录已过期
     */
    case TOKEN_EXPIRED = 20004;

    /**
     * 无访问权限
     */
    case FORBIDDEN = 20005;

    /**
     * 密码错误
     */
    case PASSWORD_ERROR = 20008;

    /**
     * 数据不存在
     */
    case DATA_NOT_FOUND = 30001;

    /**
     * 业务异常
     */
    case BUSINESS_ERROR = 40001;

    /**
     * 当前状态不可操作
     */
    case BUSINESS_INVALID_STATE = 40002;

    /**
     * 重复提交
     */
    case BUSINESS_DUPLICATE = 40009;

    /**
     * 超出业务规则限制
     */
    case BUSINESS_LIMIT = 40010;

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
            self::DATA_EXISTS => '数据已存在',
            self::PARAM_ERROR => '参数错误',
            self::PARAM_MISSING => '必填参数缺失',
            self::PARAM_FORMAT_ERROR => '参数格式错误',
            self::PARAM_OUT_OF_RANGE => '参数超出范围',
            self::PARAM_INVALID => '非法参数',
            self::UNAUTHORIZED => '请先登录',
            self::TOKEN_INVALID => 'Token 失效',
            self::TOKEN_ERROR => 'Token 错误',
            self::TOKEN_EXPIRED => '登录已过期，请重新登录',
            self::FORBIDDEN => '无访问权限',
            self::PASSWORD_ERROR => '密码错误',
            self::DATA_NOT_FOUND => '记录不存在',
            self::BUSINESS_ERROR => '业务处理失败',
            self::BUSINESS_INVALID_STATE => '当前状态不可操作',
            self::BUSINESS_DUPLICATE => '请勿重复提交',
            self::BUSINESS_LIMIT => '超出业务规则限制',
            self::THIRD_PARTY_ERROR => '第三方服务异常',
            self::DATABASE_ERROR => '数据库异常',
            self::SYSTEM_ERROR => '系统异常',
        };
    }
}
