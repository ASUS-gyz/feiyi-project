<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * AI 上下文窗口截断（票 #65）
 *
 * 多轮对话时发往大模型的历史仅取最近 10 轮（用户/AI 消息各 10 条）+
 * 系统提示词；完整历史仍全量落库、会话历史读回端点展示不变。
 */
class ChatHistoryWindowTest extends TestCase
{
    use InteractsWithApi;

    private const ROUNDS = 10;

    /**
     * 捕获发往 AI 的请求载荷
     */
    private function fakeAiCapturing(array &$captured): void
    {
        config(['services.deepseek.api_key' => 'test-key']);
        Http::fake([
            '*' => function ($request) use (&$captured) {
                $captured[] = $request->data();
                return Http::response([
                    'choices' => [['message' => ['content' => '（测试桩回复）']]],
                ]);
            },
        ]);
    }

    /**
     * 播种指定轮数的对话（每轮用户/AI 各一条，时间错开保证排序稳定）
     */
    private function seedRounds(string $sessionId, int $rounds): void
    {
        foreach (range(1, $rounds) as $i) {
            $at = now()->subMinutes(200 - $i * 10);
            ChatMessage::unguarded(function () use ($sessionId, $i, $at) {
                ChatMessage::create(['session_id' => $sessionId, 'role' => 'ROLE_USER', 'content' => "第{$i}问", 'created_at' => $at]);
                ChatMessage::create(['session_id' => $sessionId, 'role' => 'ROLE_ASSISTANT', 'content' => "第{$i}答", 'created_at' => $at]);
            });
        }
    }

    /**
     * 超过窗口的会话：发往 AI 的载荷只含系统提示词 + 最近 10 轮 + 当前提问
     */
    public function test_ai_payload_contains_only_last_ten_rounds(): void
    {
        $captured = [];
        $this->fakeAiCapturing($captured);
        ['token' => $token, 'userId' => $userId] = $this->registerAndLogin();

        $session = ChatSession::create([
            'session_id' => 'sess_window_test',
            'user_id' => $userId,
            'title' => '历史窗口测试',
            'last_message' => '',
        ]);
        $this->seedRounds($session->session_id, self::ROUNDS + 2);

        $response = $this->withToken($token)->postJson('/api/chat/message', [
            'message' => '第13问',
            'sessionId' => $session->session_id,
        ]);
        $this->assertSuccessEnvelope($response);

        $this->assertCount(1, $captured, '应已调用一次 AI 接口');
        $messages = $captured[0]['messages'];

        // 系统提示词 + 10 轮历史（20 条）+ 当前提问 = 22 条
        $this->assertCount(1 + self::ROUNDS * 2 + 1, $messages, '载荷应只含系统提示词 + 最近 10 轮 + 当前提问');
        $this->assertSame('system', $messages[0]['role'], '首条应为系统提示词');
        $this->assertSame('第3问', $messages[1]['content'], '窗口最旧应为第 3 轮用户消息');
        $this->assertSame('第12答', $messages[self::ROUNDS * 2]['content'], '窗口末尾应为第 12 轮 AI 回复');

        $payload = json_encode($messages, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('第1问', $payload, '窗口外的早期消息不应发往 AI');
        $this->assertStringNotContainsString('第2答', $payload, '窗口外的早期消息不应发往 AI');
        $this->assertSame('第13问', $messages[count($messages) - 1]['content'], '末条应为当前提问');
    }

    /**
     * 窗口边界：恰好 10 轮时全部历史照发，不多截
     */
    public function test_exactly_ten_rounds_send_full_history(): void
    {
        $captured = [];
        $this->fakeAiCapturing($captured);
        ['token' => $token, 'userId' => $userId] = $this->registerAndLogin();

        $session = ChatSession::create([
            'session_id' => 'sess_window_edge',
            'user_id' => $userId,
            'title' => '窗口边界测试',
            'last_message' => '',
        ]);
        $this->seedRounds($session->session_id, self::ROUNDS);

        $response = $this->withToken($token)->postJson('/api/chat/message', [
            'message' => '第11问',
            'sessionId' => $session->session_id,
        ]);
        $this->assertSuccessEnvelope($response);

        $messages = $captured[0]['messages'];
        $this->assertCount(1 + self::ROUNDS * 2 + 1, $messages);
        $this->assertSame('第1问', $messages[1]['content'], '恰好 10 轮时最早一条也应照发');
        $this->assertSame('第11问', $messages[count($messages) - 1]['content']);
    }

    /**
     * 落库不截断：会话历史读回仍返回全部轮次
     */
    public function test_history_readback_still_returns_full_history(): void
    {
        $captured = [];
        $this->fakeAiCapturing($captured);
        ['token' => $token, 'userId' => $userId] = $this->registerAndLogin();

        $session = ChatSession::create([
            'session_id' => 'sess_readback_full',
            'user_id' => $userId,
            'title' => '全量读回测试',
            'last_message' => '',
        ]);
        $this->seedRounds($session->session_id, self::ROUNDS + 2);

        $this->withToken($token)->postJson('/api/chat/message', [
            'message' => '第13问',
            'sessionId' => $session->session_id,
        ]);

        $history = $this->withToken($token)->getJson("/api/chat/sessions/{$session->session_id}/messages");
        $this->assertSuccessEnvelope($history);
        // 12 轮播种（24 条）+ 当前轮用户/AI 各 1 条 = 26 条
        $this->assertSame((self::ROUNDS + 2) * 2 + 2, $history->json('data.total'), '完整历史应全量落库可读回');

        $contents = collect($history->json('data.list'))->pluck('content')->implode("\n");
        $this->assertStringContainsString('第1问', $contents, '窗口外早期消息仍应可读回');
        $this->assertStringContainsString('第13问', $contents);
    }
}
