<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use App\Support\JWT;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * Token 验证加固（票 #43）
 *
 * 验签算法由服务端钉死、token 头 alg 声明不被采信；
 * 一切验证失败统一渲染为 401 错误信封（过期 20004，其余 20001），不再出现 500。
 */
class TokenVerificationTest extends TestCase
{
    use InteractsWithApi;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        ['userId' => $this->userId] = $this->registerUser();
    }

    /**
     * 构造指定算法的 token（可用真实密钥签名，用于算法混淆用例）
     */
    private function craftToken(array $payload, string $alg, bool $sign = true): string
    {
        $headB64 = $this->b64((string) json_encode(['alg' => $alg, 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $payloadB64 = $this->b64((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = "$headB64.$payloadB64";

        if (!$sign) {
            return "$signingInput.";
        }

        $hash = match ($alg) {
            'HS384' => 'sha384',
            'HS512' => 'sha512',
            default => 'sha256',
        };

        return "$signingInput.".$this->b64((string) hash_hmac($hash, $signingInput, config('jwt.secret'), true));
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function validPayload(): array
    {
        return ['sub' => $this->userId, 'iat' => time(), 'exp' => time() + 3600];
    }

    /**
     * alg=none 的无签名 token 被拒绝为 20001（改造前会触发 500）
     */
    public function test_alg_none_token_is_rejected_as_unauthorized(): void
    {
        $token = $this->craftToken($this->validPayload(), 'none', sign: false);

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $this->assertErrorEnvelope($response, ResponseCode::UNAUTHORIZED->value);
    }

    /**
     * 用真实密钥但以 HS384 签发的 token 被拒绝：验签算法钉死为服务端配置，头部声明不采信
     */
    public function test_token_signed_with_non_pinned_algorithm_is_rejected(): void
    {
        $token = $this->craftToken($this->validPayload(), 'HS384');

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $this->assertErrorEnvelope($response, ResponseCode::UNAUTHORIZED->value);
    }

    /**
     * 签名被篡改的 token 返回 20001 信封
     */
    public function test_tampered_signature_is_rejected(): void
    {
        ['token' => $token] = $this->registerAndLogin();

        $segments = explode('.', $token);
        $last = $segments[2];
        $flipped = $last[strlen($last) - 1] === 'A' ? 'B'.substr($last, 1) : 'A'.substr($last, 1);
        $segments[2] = $flipped;
        $tampered = implode('.', $segments);

        $this->assertNotSame($token, $tampered);

        $response = $this->withToken($tampered)->getJson('/api/auth/me');

        $this->assertErrorEnvelope($response, ResponseCode::UNAUTHORIZED->value);
    }

    /**
     * 格式残缺的 token 返回 20001 信封
     */
    public function test_malformed_token_is_rejected(): void
    {
        foreach (['not-a-token', 'a.b', 'a.b.c.d'] as $token) {
            $response = $this->withToken($token)->getJson('/api/auth/me');

            $this->assertErrorEnvelope($response, ResponseCode::UNAUTHORIZED->value);
        }
    }

    /**
     * header 段是合法 JSON 但非对象时返回 20001 信封（改造前会触发类型错误 500）
     */
    public function test_non_object_token_header_is_rejected(): void
    {
        $headB64 = $this->b64('"abc"');
        $payloadB64 = $this->b64((string) json_encode($this->validPayload(), JSON_UNESCAPED_SLASHES));
        $signingInput = "$headB64.$payloadB64";
        $token = "$signingInput.".$this->b64((string) hash_hmac('sha256', $signingInput, config('jwt.secret'), true));

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $this->assertErrorEnvelope($response, ResponseCode::UNAUTHORIZED->value);
    }

    /**
     * 已过期 token 返回 20004 信封（与其他无效区分，供前端静默续期）
     */
    public function test_expired_token_returns_token_expired_envelope(): void
    {
        $token = JWT::encode(['sub' => $this->userId, 'exp' => time() - 100]);

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $this->assertErrorEnvelope($response, ResponseCode::TOKEN_EXPIRED->value);
    }

    /**
     * 回归：有效 token 的正常访问不受加固影响
     */
    public function test_valid_token_still_grants_access(): void
    {
        ['token' => $token] = $this->registerAndLogin();

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $this->assertSuccessEnvelope($response);
    }
}
