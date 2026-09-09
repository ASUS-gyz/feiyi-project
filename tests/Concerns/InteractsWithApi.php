<?php

namespace Tests\Concerns;

use App\Enums\ResponseCode;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * API 测试交互模式（项目统一约定）
 *
 * 认证走真实流程：注册 → 登录 → 持真实 JWT 请求受保护接口，
 * 不 mock 认证层；断言统一响应信封（code/msg/data/success/trace_id）。
 * 后续所有 Feature 测试沿用本 trait。
 */
trait InteractsWithApi
{
    /**
     * 注册一个用户（不登录）
     *
     * @return array{username: string, password: string, userId: int, nickname: ?string}
     */
    protected function registerUser(array $overrides = []): array
    {
        $payload = array_merge([
            'username' => $this->uniqueUsername(),
            'password' => 'password123',
            'nickname' => '测试用户',
        ], $overrides);

        $response = $this->postJson('/api/auth/register', $payload);

        $this->assertSuccessEnvelope($response);

        return [
            'username' => $payload['username'],
            'password' => $payload['password'],
            'userId' => $response->json('data.userId'),
            'nickname' => $response->json('data.nickname'),
        ];
    }

    /**
     * 用已注册的账号登录，返回真实 JWT
     */
    protected function loginUser(string $username, string $password): string
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);

        $this->assertSuccessEnvelope($response);

        $token = $response->json('data.token');
        $this->assertNotEmpty($token, '登录响应应包含 token');

        return $token;
    }

    /**
     * 注册并登录，一步拿到 token 与用户信息
     *
     * @return array{token: string, username: string, password: string, userId: int, user: array}
     */
    protected function registerAndLogin(array $overrides = []): array
    {
        $user = $this->registerUser($overrides);
        $token = $this->loginUser($user['username'], $user['password']);

        return array_merge($user, [
            'token' => $token,
            'user' => [
                'userId' => $user['userId'],
                'username' => $user['username'],
                'nickname' => $user['nickname'],
            ],
        ]);
    }

    /**
     * 断言成功信封：code=0、success=true、trace_id 非空
     */
    protected function assertSuccessEnvelope(TestResponse $response, int $status = 200): void
    {
        $response->assertStatus($status);

        $this->assertSame(ResponseCode::SUCCESS->value, $response->json('code'), '成功响应信封 code 应为 0，实际响应：'.$response->getContent());
        $this->assertTrue($response->json('success'), '成功响应信封 success 应为 true');
        $this->assertNotEmpty($response->json('msg'), '响应信封应包含 msg');
        $this->assertNotEmpty($response->json('trace_id'), '响应信封应包含非空 trace_id');
        $this->assertArrayHasKey('data', $response->json());
    }

    /**
     * 断言失败信封：指定错误码、success=false
     */
    protected function assertErrorEnvelope(TestResponse $response, int $expectedCode, int $status = 200): void
    {
        $response->assertStatus($status);

        $this->assertSame($expectedCode, $response->json('code'), "响应信封 code 应为 {$expectedCode}，实际响应：".$response->getContent());
        $this->assertFalse($response->json('success'), '失败响应信封 success 应为 false');
        $this->assertNotEmpty($response->json('msg'), '响应信封应包含 msg');
        $this->assertNotEmpty($response->json('trace_id'), '响应信封应包含非空 trace_id');
    }

    /**
     * 生成不重复的测试用户名
     */
    protected function uniqueUsername(string $prefix = 'user'): string
    {
        return $prefix.'_'.Str::lower(Str::random(10));
    }
}
