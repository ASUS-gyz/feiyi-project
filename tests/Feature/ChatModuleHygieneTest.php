<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 聊天模块卫生改造（票 #31）
 *
 * 游客单轮化（不产生持久会话）、统一表单请求验证、调试端点移除、响应统一信封。
 */
class ChatModuleHygieneTest extends TestCase
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

    /**
     * 游客提问获得单轮回答，数据库不新增任何会话与消息记录
     */
    public function test_guest_gets_single_turn_answer_without_persisting_session(): void
    {
        $response = $this->postJson('/api/chat/message', ['message' => '什么是烧箔画？']);

        $this->assertSuccessEnvelope($response);
        $this->assertSame('（测试桩回复）', $response->json('data.aiResponse'));
        $this->assertNull($response->json('data.sessionId'), '游客回答不应携带会话 ID');

        $this->assertSame(0, ChatSession::count(), '游客提问不应创建会话记录');
        $this->assertSame(0, ChatMessage::count(), '游客提问不应产生消息记录');
    }

    /**
     * 游客携带会话 ID 提问按会话不存在处理（会话语义仅对登录用户存在，防枚举）
     */
    public function test_guest_cannot_probe_sessions(): void
    {
        $response = $this->postJson('/api/chat/message', [
            'message' => '游客的提问',
            'sessionId' => 'sess_somebody_elses',
        ]);

        $this->assertErrorEnvelope($response, ResponseCode::DATA_NOT_FOUND->value);
        $this->assertSame(0, ChatMessage::count());
    }

    /**
     * 游客提交超长消息返回参数错误（10001）
     */
    public function test_guest_oversized_message_returns_param_error(): void
    {
        $response = $this->postJson('/api/chat/message', ['message' => str_repeat('问', 1001)]);

        $this->assertErrorEnvelope($response, ResponseCode::PARAM_ERROR->value);
        $this->assertSame(0, ChatMessage::count());
    }

    /**
     * AI 参数越界（maxTokens/temperature）返回参数错误（10001）
     */
    public function test_out_of_range_ai_params_return_param_error(): void
    {
        $maxTokens = $this->postJson('/api/chat/message', [
            'message' => '你好',
            'maxTokens' => 99999,
        ]);
        $this->assertErrorEnvelope($maxTokens, ResponseCode::PARAM_ERROR->value);

        $temperature = $this->postJson('/api/chat/message', [
            'message' => '你好',
            'temperature' => 9.9,
        ]);
        $this->assertErrorEnvelope($temperature, ResponseCode::PARAM_ERROR->value);
    }

    /**
     * 登录用户的会话聊天行为与验证规则同样生效（回归）
     */
    public function test_authenticated_user_session_flow_regression(): void
    {
        ['token' => $token] = $this->registerAndLogin();

        $first = $this->withToken($token)->postJson('/api/chat/message', ['message' => '第一条消息']);
        $this->assertSuccessEnvelope($first);
        $sessionId = $first->json('data.sessionId');
        $this->assertNotEmpty($sessionId, '登录用户提问应创建会话');

        $this->assertSame(1, ChatSession::count());
        $this->assertSame(2, ChatMessage::where('session_id', $sessionId)->count());

        $second = $this->withToken($token)->postJson('/api/chat/message', [
            'message' => '第二条消息',
            'sessionId' => $sessionId,
        ]);
        $this->assertSuccessEnvelope($second);
        $this->assertSame($sessionId, $second->json('data.sessionId'));
        $this->assertSame(4, ChatMessage::where('session_id', $sessionId)->count());
        $this->assertSame(1, ChatSession::count());

        // 超长消息被验证拦截，不产生写入
        $oversized = $this->withToken($token)->postJson('/api/chat/message', [
            'message' => str_repeat('问', 1001),
            'sessionId' => $sessionId,
        ]);
        $this->assertErrorEnvelope($oversized, ResponseCode::PARAM_ERROR->value);
        $this->assertSame(4, ChatMessage::where('session_id', $sessionId)->count());
    }

    /**
     * AI 调试端点已从生产路由移除，命中"接口不存在"信封
     */
    public function test_debug_endpoint_is_removed(): void
    {
        $response = $this->getJson('/api/chat/test');

        $this->assertErrorEnvelope($response, ResponseCode::DATA_NOT_FOUND->value);
        $this->assertSame('接口不存在', $response->json('msg'));
    }
}
