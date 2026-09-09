<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * Feature 测试基例（票 #27）
 *
 * 建立项目 API 测试的统一模式：
 * 1. 真实注册 → 登录 → 持真实 JWT 访问受保护接口的全链路
 * 2. 未认证访问受保护接口的错误信封
 * 3. 外部 AI 调用路径被 HTTP 假对象拦截（保证测试离线可跑）
 */
class AuthBaselineTest extends TestCase
{
    use InteractsWithApi;

    /**
     * 基例一：真实 JWT 全链路
     */
    public function test_registered_user_fetches_own_profile_with_real_jwt(): void
    {
        ['token' => $token, 'user' => $user] = $this->registerAndLogin();

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $this->assertSuccessEnvelope($response);
        $this->assertSame($user['userId'], $response->json('data.userId'));
        $this->assertSame($user['username'], $response->json('data.username'));
    }

    /**
     * 基例二：未认证访问受保护接口
     */
    public function test_protected_endpoint_without_token_returns_unauthorized_envelope(): void
    {
        $response = $this->getJson('/api/auth/me');

        $this->assertErrorEnvelope($response, ResponseCode::UNAUTHORIZED->value);
    }

    /**
     * 基例三：AI 调用路径离线可测（HTTP 假对象拦截外部请求）
     */
    public function test_ai_call_path_is_intercepted_by_http_fake(): void
    {
        // 显式给出 key，确保请求确实走外部调用路径并被假对象拦截
        config(['services.deepseek.api_key' => 'test-key']);

        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '（测试桩回复）']],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/chat/message', ['message' => '你好']);

        // 聊天端点当前返回自有结构而非统一信封（已知不一致，由票 #31 统一修正）。
        // 本基例只验证：外部 AI 调用路径被假对象拦截，测试离线可跑。
        $response->assertStatus(200);
        $this->assertSame('（测试桩回复）', $response->json('aiResponse'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.deepseek.com'));
    }
}
