<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * JWT 工具类
 *
 * 使用 HMAC-SHA 系列算法实现 JWT 的签发与验证，
 * 不依赖第三方包，仅使用 PHP 内置函数。
 */
class JWT
{
    /**
     * 签发 JWT Token
     *
     * @param array $payload 载荷数据
     * @return string JWT token
     */
    public static function encode(array $payload): string
    {
        $header = [
            'alg' => config('jwt.algorithm', 'HS256'),
            'typ' => 'JWT',
        ];

        // 添加标准声明
        $payload['iss'] = config('jwt.issuer');
        $payload['iat'] = $payload['iat'] ?? time();
        $payload['exp'] = $payload['exp'] ?? time() + config('jwt.ttl', 86400);

        $segments = [];
        $segments[] = self::base64UrlEncode(self::jsonEncode($header));
        $segments[] = self::base64UrlEncode(self::jsonEncode($payload));

        $signingInput = implode('.', $segments);
        $signature = self::sign($signingInput, config('jwt.secret'), config('jwt.algorithm', 'HS256'));
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * 解析并验证 JWT Token
     *
     * @param string $token JWT token
     * @return array 解码后的 payload
     *
     * @throws RuntimeException 当 token 无效或过期时
     */
    public static function decode(string $token): array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            throw new RuntimeException('Token 格式错误');
        }

        [$headB64, $payloadB64, $sigB64] = $segments;

        // 解码 header
        $header = self::jsonDecode(self::base64UrlDecode($headB64));
        if ($header === null) {
            throw new RuntimeException('Token Header 解析失败');
        }

        // 验证签名
        $signingInput = "$headB64.$payloadB64";
        $signature = self::base64UrlDecode($sigB64);

        $algorithm = $header['alg'] ?? 'none';
        $expected = self::sign($signingInput, config('jwt.secret'), $algorithm);

        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Token 签名验证失败');
        }

        // 解码 payload
        $payload = self::jsonDecode(self::base64UrlDecode($payloadB64));
        if ($payload === null) {
            throw new RuntimeException('Token Payload 解析失败');
        }

        // 验证过期时间
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new RuntimeException('Token 已过期');
        }

        return $payload;
    }

    /**
     * HMAC 签名
     */
    private static function sign(string $input, string $secret, string $algorithm): string
    {
        $hashMethod = match ($algorithm) {
            'HS256' => 'sha256',
            'HS384' => 'sha384',
            'HS512' => 'sha512',
            default => throw new InvalidArgumentException("不支持的签名算法: $algorithm"),
        };

        return hash_hmac($hashMethod, $input, $secret, true);
    }

    /**
     * Base64Url 编码（URL 安全的 base64，去掉填充 =）
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64Url 解码
     */
    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * JSON 编码（带异常检查）
     */
    private static function jsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('JSON 编码失败: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * JSON 解码（带异常检查）
     */
    private static function jsonDecode(string $data): ?array
    {
        $result = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return $result;
    }
}
