<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 聊天消息与 AI 回复净化（票 #56）
 *
 * 用户消息写前净化（落库/会话标题/发往 AI 的载荷），AI 回复作为不可信第三方
 * 内容在入库与返回前净化；游客直返路径同样净化且不落库，纯文本比较符不误伤。
 */
class ChatSanitizationTest extends TestCase
{
    use InteractsWithApi;

    /**
     * 基础桩配置：DeepSeek API key + 默认桩回复
     */
    protected function fakeAi(string $content): void
    {
        config(['services.deepseek.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => $content]],
                ],
            ]),
        ]);
    }

    /**
     * 用户消息标记写前剥离：落库历史与发往 AI 的载荷均为净化后文本
     */
    public function test_user_message_markup_is_stripped_before_persist_and_ai_call(): void
    {
        $captured = [];
        config(['services.deepseek.api_key' => 'test-key']);
        Http::fake([
            '*' => function ($request) use (&$captured) {
                $captured[] = $request->data();
                return Http::response([
                    'choices' => [['message' => ['content' => '（测试桩回复）']]],
                ]);
            },
        ]);

        ['token' => $token] = $this->registerAndLogin();

        $response = $this->withToken($token)->postJson('/api/chat/message', [
            'message' => '<script>alert(1)</script>什么是烧箔画？<img src=x onerror=alert(2)>',
        ]);
        $this->assertSuccessEnvelope($response);
        $sessionId = $response->json('data.sessionId');

        $history = $this->withToken($token)->getJson("/api/chat/sessions/{$sessionId}/messages");
        $this->assertSuccessEnvelope($history);
        $contents = collect($history->json('data.list'))->pluck('content')->implode("\n");
        $this->assertStringNotContainsString('<script', $contents, '用户消息标记不得存留');
        $this->assertStringNotContainsString('onerror', $contents, '用户消息事件属性不得存留');
        $this->assertStringContainsString('什么是烧箔画？', $contents, '正文其余部分应保留');

        $this->assertNotEmpty($captured, '应已调用 AI 接口');
        $aiPayload = json_encode($captured, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('<script', $aiPayload, '发往 AI 的消息应为净化后文本');
        $this->assertStringContainsString('什么是烧箔画？', $aiPayload);
    }

    /**
     * AI 回复含标记：响应载荷与会话历史读回均为净化后文本
     */
    public function test_ai_response_markup_is_sanitized_in_payload_and_history(): void
    {
        $this->fakeAi('<script>alert(1)</script>烧箔画以金属箔为材<img src=x onerror=alert(2)>，经高温烧制。');

        ['token' => $token] = $this->registerAndLogin();

        $response = $this->withToken($token)->postJson('/api/chat/message', ['message' => '介绍下烧箔画']);
        $this->assertSuccessEnvelope($response);
        $aiResponse = (string) $response->json('data.aiResponse');
        $this->assertStringNotContainsString('<script', $aiResponse, 'AI 回复标记不得存留');
        $this->assertStringNotContainsString('onerror', $aiResponse, 'AI 回复事件属性不得存留');
        $this->assertStringContainsString('烧箔画以金属箔为材', $aiResponse);
        $this->assertStringContainsString('，经高温烧制。', $aiResponse);

        $sessionId = $response->json('data.sessionId');
        $history = $this->withToken($token)->getJson("/api/chat/sessions/{$sessionId}/messages");
        $assistantContents = collect($history->json('data.list'))
            ->where('role', 'ROLE_ASSISTANT')->pluck('content')->implode("\n");
        $this->assertStringNotContainsString('<script', $assistantContents, 'AI 回复入库前应已净化');
        $this->assertStringContainsString('烧箔画以金属箔为材', $assistantContents);
    }

    /**
     * 游客路径：AI 回复净化后直返，且不产生任何会话与消息记录
     */
    public function test_guest_path_response_sanitized_without_persistence(): void
    {
        $this->fakeAi('<svg onload=alert(1)>烧箔画源于唐代。');

        $response = $this->postJson('/api/chat/message', ['message' => '什么是烧箔画？']);
        $this->assertSuccessEnvelope($response);
        $aiResponse = (string) $response->json('data.aiResponse');
        $this->assertStringNotContainsString('onload', $aiResponse, '游客回复标记不得存留');
        $this->assertStringContainsString('烧箔画源于唐代。', $aiResponse);
        $this->assertNull($response->json('data.sessionId'), '游客回答不应携带会话 ID');
        $this->assertSame(0, ChatSession::count(), '游客提问不应创建会话记录');
        $this->assertSame(0, ChatMessage::count(), '游客提问不应产生消息记录');
    }

    /**
     * 用户消息纯文本比较符不误伤：落库与读回原样保留
     */
    public function test_user_message_plain_comparison_operators_pass_through(): void
    {
        $this->fakeAi('（测试桩回复）');

        ['token' => $token] = $this->registerAndLogin();

        $raw = '1 < 2 > 3 是真的吗？';
        $response = $this->withToken($token)->postJson('/api/chat/message', ['message' => $raw]);
        $this->assertSuccessEnvelope($response);

        $history = $this->withToken($token)->getJson('/api/chat/sessions/'.$response->json('data.sessionId').'/messages');
        $userContents = collect($history->json('data.list'))
            ->where('role', 'ROLE_USER')->pluck('content')->implode("\n");
        $this->assertSame($raw, $userContents, '纯文本比较符应原样保留');
    }
}
