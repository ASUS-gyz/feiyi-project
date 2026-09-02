<?php

namespace App\Enums;

/**
 * 统一错误码规范（对应《后端总接口文档-V2》第 17 节）
 */
enum ResponseCode: int
{
    // ===== 通用响应码（0~9）=====
    case SUCCESS = 0;              // 成功
    case SYSTEM_EXCEPTION = 1;     // 系统异常（未知异常）
    case NOT_FOUND = 2;            // 数据不存在（查询结果为空）
    case DATA_EXISTS = 3;          // 数据已存在（唯一性校验失败）
    case OPERATION_FAILED = 4;     // 操作失败（通用失败）
    case FORBIDDEN_OPERATION = 5;  // 禁止操作（当前状态不允许执行）
    case TOO_MANY_REQUESTS = 6;    // 请求过于频繁（限流）
    case SERVICE_UNAVAILABLE = 7;  // 服务暂不可用（系统维护）
    case NETWORK_ERROR = 8;        // 网络异常（第三方服务异常）
    case CONCURRENT_CONFLICT = 9;  // 并发冲突（乐观锁冲突）

    // ===== 参数相关（10000 段）=====
    case PARAM_ERROR = 10001;        // 参数错误
    case PARAM_MISSING = 10002;      // 必填参数缺失
    case PARAM_FORMAT_ERROR = 10003; // 参数格式错误
    case PARAM_OUT_OF_RANGE = 10004; // 参数超出范围
    case PARAM_INVALID = 10005;      // 非法参数
    case FILE_FORMAT_ERROR = 10006;  // 文件格式错误
    case FILE_TOO_LARGE = 10007;     // 文件过大

    // ===== 认证授权（20000 段）=====
    case UNAUTHORIZED = 20001;     // 未登录
    case TOKEN_INVALID = 20002;    // Token 失效
    case TOKEN_ERROR = 20003;      // Token 错误
    case TOKEN_EXPIRED = 20004;    // 登录已过期
    case FORBIDDEN = 20005;        // 无访问权限
    case ACCOUNT_DISABLED = 20006; // 账号被禁用
    case PASSWORD_ERROR = 20008;   // 密码错误

    // ===== 数据相关（30000 段）=====
    case DATA_NOT_FOUND = 30001;         // 数据不存在
    case DATA_DUPLICATE = 30003;         // 数据重复
    case DATA_STATE_ERROR = 30004;       // 数据状态异常
    case DATA_LOCKED = 30005;            // 数据已锁定
    case DATA_RELATED = 30006;           // 数据关联存在
    case DATA_VALIDATION_FAILED = 30007; // 数据校验失败
    case DATA_VERSION_CONFLICT = 30008;  // 数据版本冲突

    // ===== 业务相关（40000 段）=====
    case BUSINESS_ERROR = 40001;         // 操作失败
    case BUSINESS_INVALID_STATE = 40002; // 当前状态不可操作
    case STOCK_NOT_ENOUGH = 40004;       // 库存不足
    case AMOUNT_LIMIT = 40005;           // 金额超限
    case QUOTA_EXCEEDED = 40006;         // 超出配额
    case BUSINESS_DUPLICATE = 40009;     // 重复提交
    case BUSINESS_LIMIT = 40010;         // 超出业务规则限制

    // ===== 第三方服务（50000 段）=====
    case WECHAT_ERROR = 50001;        // 微信接口异常
    case SMS_SEND_FAILED = 50003;     // 短信发送失败
    case EMAIL_SEND_FAILED = 50004;   // 邮件发送失败
    case OSS_UPLOAD_FAILED = 50005;   // OSS 上传失败
    case THIRD_PARTY_TIMEOUT = 50008; // 第三方接口超时

    // ===== 系统异常（90000 段）=====
    case SYSTEM_ERROR = 90001;          // 系统异常
    case UNKNOWN_ERROR = 90002;         // 未知错误
    case SERVICE_BUSY = 90004;          // 服务繁忙
    case CONFIG_ERROR = 90006;          // 配置错误
    case SERVER_INTERNAL_ERROR = 90008; // 服务器内部错误

    public function msg(): string
    {
        return match ($this) {
            self::SUCCESS => '成功',
            self::SYSTEM_EXCEPTION => '系统异常',
            self::NOT_FOUND => '数据不存在',
            self::DATA_EXISTS => '数据已存在',
            self::OPERATION_FAILED => '操作失败',
            self::FORBIDDEN_OPERATION => '禁止操作',
            self::TOO_MANY_REQUESTS => '请求过于频繁',
            self::SERVICE_UNAVAILABLE => '服务暂不可用',
            self::NETWORK_ERROR => '网络异常',
            self::CONCURRENT_CONFLICT => '并发冲突',

            self::PARAM_ERROR => '参数错误',
            self::PARAM_MISSING => '必填参数缺失',
            self::PARAM_FORMAT_ERROR => '参数格式错误',
            self::PARAM_OUT_OF_RANGE => '参数超出范围',
            self::PARAM_INVALID => '非法参数',
            self::FILE_FORMAT_ERROR => '文件格式错误',
            self::FILE_TOO_LARGE => '文件过大',

            self::UNAUTHORIZED => '请先登录',
            self::TOKEN_INVALID => 'Token 失效',
            self::TOKEN_ERROR => 'Token 错误',
            self::TOKEN_EXPIRED => '登录已过期，请重新登录',
            self::FORBIDDEN => '无访问权限',
            self::ACCOUNT_DISABLED => '账号已被禁用',
            self::PASSWORD_ERROR => '密码错误',

            self::DATA_NOT_FOUND => '记录不存在',
            self::DATA_DUPLICATE => '数据重复',
            self::DATA_STATE_ERROR => '数据状态异常',
            self::DATA_LOCKED => '数据已锁定',
            self::DATA_RELATED => '数据关联存在',
            self::DATA_VALIDATION_FAILED => '数据校验失败',
            self::DATA_VERSION_CONFLICT => '数据版本冲突',

            self::BUSINESS_ERROR => '业务处理失败',
            self::BUSINESS_INVALID_STATE => '当前状态不可操作',
            self::STOCK_NOT_ENOUGH => '库存不足',
            self::AMOUNT_LIMIT => '金额超限',
            self::QUOTA_EXCEEDED => '超出配额',
            self::BUSINESS_DUPLICATE => '请勿重复提交',
            self::BUSINESS_LIMIT => '超出业务规则限制',

            self::WECHAT_ERROR => '微信接口异常',
            self::SMS_SEND_FAILED => '短信发送失败',
            self::EMAIL_SEND_FAILED => '邮件发送失败',
            self::OSS_UPLOAD_FAILED => 'OSS 上传失败',
            self::THIRD_PARTY_TIMEOUT => '第三方接口超时',

            self::SYSTEM_ERROR => '系统异常',
            self::UNKNOWN_ERROR => '未知错误',
            self::SERVICE_BUSY => '服务繁忙',
            self::CONFIG_ERROR => '配置错误',
            self::SERVER_INTERNAL_ERROR => '服务器内部错误',
        };
    }
}
