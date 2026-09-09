<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * AI 聊天限流（票 #36）
 *
 * 登录用户按账号、游客（含无效 token）按 IP 双维度限流；
 * 外部 AI 调用由 HTTP 假对象拦截，保证离线可跑。
 */
class ChatRateLimitTest extends TestCase
{
    use InteractsWithApi;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.deepseek.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '（测试桩回复）']],
                ],
            ]),
        ]);
    }

    private function postChat(?string $token = null)
    {
        if ($token !== null) {
            return $this->withToken($token)->postJson('/api/chat/message', ['message' => '你好']);
        }

        return $this->postJson('/api/chat/message', ['message' => '你好']);
    }

    /**
     * 游客连续提问超过阈值返回 429 + code 6 信封；阈值内不受影响
     */
    public function test_guest_beyond_threshold_gets_429(): void
    {
        config(['throttle.chat.guest_max' => 2, 'throttle.chat.decay' => 60]);

        for ($i = 0; $i < 2; $i++) {
            $response = $this->postChat();
            $this->assertSuccessEnvelope($response);
            $this->assertSame('（测试桩回复）', $response->json('data.aiResponse'));
        }

        $response = $this->postChat();

        $response->assertStatus(429);
        $this->assertSame(ResponseCode::TOO_MANY_REQUESTS->value, $response->json('code'));
        $this->assertNotEmpty($response->headers->get('Retry-After'));
    }

    /**
     * 登录用户连续提问超过阈值返回 429 + code 6 信封；阈值内不受影响
     */
    public function test_authenticated_user_beyond_threshold_gets_429(): void
    {
        config(['throttle.chat.auth_max' => 2, 'throttle.chat.decay' => 60]);
        ['token' => $token] = $this->registerAndLogin();

        for ($i = 0; $i < 2; $i++) {
            $response = $this->postChat($token);
            $this->assertSuccessEnvelope($response);
            $this->assertSame('（测试桩回复）', $response->json('data.aiResponse'));
        }

        $response = $this->postChat($token);

        $response->assertStatus(429);
        $this->assertSame(ResponseCode::TOO_MANY_REQUESTS->value, $response->json('code'));
    }

    /**
     * 无效 token 按游客维度限流，不能借无效 token 绕过游客限额
     */
    public function test_invalid_token_is_limited_as_guest(): void
    {
        config(['throttle.chat.guest_max' => 1, 'throttle.chat.decay' => 60]);

        $first = $this->postChat('invalid-token');
        $this->assertSuccessEnvelope($first);
        $this->assertSame('（测试桩回复）', $first->json('data.aiResponse'));

        $second = $this->postChat('invalid-token');

        $second->assertStatus(429);
        $this->assertSame(ResponseCode::TOO_MANY_REQUESTS->value, $second->json('code'));
    }
}
