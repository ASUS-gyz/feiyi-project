<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 聊天会话归属过滤（票 #30）
 *
 * 读取/续聊/删除统一增加所有者过滤：他人会话与不存在会话
 * 返回完全一致的 30001 响应（防会话 ID 枚举探测），续聊他人会话不产生任何写入。
 */
class ChatSessionOwnershipTest extends TestCase
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
     * 让指定用户开启一个会话，返回 sessionId
     */
    private function createSession(string $token, string $message = '会话起始消息'): string
    {
        $response = $this->withToken($token)->postJson('/api/chat/message', ['message' => $message]);
        $this->assertSuccessEnvelope($response);

        $sessionId = $response->json('data.sessionId');
        $this->assertNotEmpty($sessionId);

        return $sessionId;
    }

    /**
     * 用户 A 读取/续聊/删除用户 B 的会话：一律 30001，B 的历史内容不泄露、不被写入
     */
    public function test_user_cannot_read_continue_or_delete_another_users_session(): void
    {
        $userA = $this->registerAndLogin();
        $userB = $this->registerAndLogin();
        $tokenA = $userA['token'];
        $sessionIdB = $this->createSession($userB['token'], 'B 的私密问题');
        $this->assertSame(2, ChatMessage::where('session_id', $sessionIdB)->count());

        ['token' => $tokenA] = $this->registerAndLogin();

        // 读取
        $read = $this->withToken($tokenA)->getJson("/api/chat/sessions/{$sessionIdB}/messages");
        $this->assertErrorEnvelope($read, ResponseCode::DATA_NOT_FOUND->value);
        $this->assertStringNotContainsString('B 的私密问题', $read->getContent(), 'B 的历史内容不得出现在响应中');

        // 续聊：返回 30001 且不产生任何消息写入
        $continue = $this->withToken($tokenA)->postJson('/api/chat/message', [
            'message' => 'A 的追问',
            'sessionId' => $sessionIdB,
        ]);
        $this->assertErrorEnvelope($continue, ResponseCode::DATA_NOT_FOUND->value);
        $this->assertSame(2, ChatMessage::where('session_id', $sessionIdB)->count(), '向他人会话续聊不得产生消息写入');

        // 删除
        $delete = $this->withToken($tokenA)->deleteJson("/api/chat/sessions/{$sessionIdB}");
        $this->assertErrorEnvelope($delete, ResponseCode::DATA_NOT_FOUND->value);

        // B 的会话完好
        $this->assertTrue(ChatSession::where('session_id', $sessionIdB)->active()->exists());
    }

    /**
     * 他人会话与不存在会话的响应码、msg、success 完全一致
     */
    public function test_other_users_session_and_nonexistent_session_respond_identically(): void
    {
        $userA = $this->registerAndLogin();
        $userB = $this->registerAndLogin();
        $tokenA = $userA['token'];
        $sessionIdB = $this->createSession($userB['token']);

        foreach (["/api/chat/sessions/{$sessionIdB}/messages", '/api/chat/sessions/not-a-real-session/messages'] as $url) {
            $response = $this->withToken($tokenA)->getJson($url);
            $codes[] = $response->json('code');
            $msgs[] = $response->json('msg');
            $successes[] = $response->json('success');
        }

        $this->assertSame($codes[0], $codes[1], '读取：他人会话与不存在会话响应码应一致');
        $this->assertSame($msgs[0], $msgs[1], '读取：他人会话与不存在会话 msg 应一致');
        $this->assertSame($successes[0], $successes[1], '读取：他人会话与不存在会话 success 应一致');

        $deleteOther = $this->withToken($tokenA)->deleteJson("/api/chat/sessions/{$sessionIdB}");
        $deleteMissing = $this->withToken($tokenA)->deleteJson('/api/chat/sessions/not-a-real-session');

        $this->assertSame($deleteOther->json('code'), $deleteMissing->json('code'), '删除：响应码应一致');
        $this->assertSame($deleteOther->json('msg'), $deleteMissing->json('msg'), '删除：msg 应一致');
        $this->assertSame($deleteOther->json('success'), $deleteMissing->json('success'), '删除：success 应一致');
    }

    /**
     * 用户对自己会话的读取/续聊/删除行为不变（回归）
     */
    public function test_user_can_read_continue_and_delete_own_session(): void
    {
        ['token' => $token] = $this->registerAndLogin();
        $sessionId = $this->createSession($token, '第一条消息');

        // 续聊自己的会话
        $continue = $this->withToken($token)->postJson('/api/chat/message', [
            'message' => '第二条消息',
            'sessionId' => $sessionId,
        ]);
        $this->assertSuccessEnvelope($continue);
        $this->assertSame('（测试桩回复）', $continue->json('data.aiResponse'));
        $this->assertSame($sessionId, $continue->json('data.sessionId'));
        $this->assertSame(4, ChatMessage::where('session_id', $sessionId)->count(), '两轮对话应产生 4 条消息');

        // 读取自己的会话
        $read = $this->withToken($token)->getJson("/api/chat/sessions/{$sessionId}/messages");
        $this->assertSuccessEnvelope($read);
        $this->assertSame(4, $read->json('data.total'));

        // 删除自己的会话
        $delete = $this->withToken($token)->deleteJson("/api/chat/sessions/{$sessionId}");
        $this->assertSuccessEnvelope($delete);
        $this->assertSame(0, ChatMessage::where('session_id', $sessionId)->active()->count());

        // 删除后再读取 → 30001
        $readAfter = $this->withToken($token)->getJson("/api/chat/sessions/{$sessionId}/messages");
        $this->assertErrorEnvelope($readAfter, ResponseCode::DATA_NOT_FOUND->value);
    }

    /**
     * 游客不能续聊他人（登录用户）的会话
     */
    public function test_guest_cannot_continue_another_users_session(): void
    {
        $userB = $this->registerAndLogin();
        $sessionIdB = $this->createSession($userB['token'], 'B 的会话');

        // 清除 createSession 残留的持久 Authorization 头，模拟真实游客请求
        $response = $this->withoutToken()->postJson('/api/chat/message', [
            'message' => '游客的追问',
            'sessionId' => $sessionIdB,
        ]);

        $this->assertErrorEnvelope($response, ResponseCode::DATA_NOT_FOUND->value);
        $this->assertSame(2, ChatMessage::where('session_id', $sessionIdB)->count(), '游客续聊他人会话不得产生写入');
    }
}
