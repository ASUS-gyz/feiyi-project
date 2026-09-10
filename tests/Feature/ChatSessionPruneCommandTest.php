<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 过期会话清理命令（票 #66）
 *
 * 30 天不活跃（以会话最近更新时间为准）的 AI 会话连同其消息级联软删；
 * 命令幂等可重复执行；近期有往来的老会话不误删。
 */
class ChatSessionPruneCommandTest extends TestCase
{
    use InteractsWithApi;

    private function createSession(int $userId, string $sessionId): ChatSession
    {
        return ChatSession::create([
            'session_id' => $sessionId,
            'user_id' => $userId,
            'title' => '清理测试会话',
            'last_message' => '',
        ]);
    }

    /**
     * 将会话的最近更新时间回拨到指定天数前（构建器更新绕过模型自动触碰）
     */
    private function ageSession(ChatSession $session, int $days): void
    {
        ChatSession::where('id', $session->id)->update(['updated_at' => now()->subDays($days)]);
    }

    private function seedMessage(string $sessionId, string $content): void
    {
        ChatMessage::unguarded(fn () => ChatMessage::create([
            'session_id' => $sessionId,
            'role' => 'ROLE_USER',
            'content' => $content,
        ]));
    }

    /**
     * 过期会话及其消息被软删，近期会话不受影响
     */
    public function test_stale_sessions_and_messages_are_pruned(): void
    {
        $userId = $this->registerUser()['userId'];

        $stale = $this->createSession($userId, 'sess_prune_stale');
        $this->seedMessage($stale->session_id, '老问题一');
        $this->seedMessage($stale->session_id, '老问题二');
        $this->ageSession($stale, 40);

        $fresh = $this->createSession($userId, 'sess_prune_fresh');
        $this->seedMessage($fresh->session_id, '新问题');
        $this->ageSession($fresh, 5);

        $this->artisan('chat:prune-sessions')
            ->expectsOutputToContain('已清理 1 个')
            ->assertExitCode(0);

        $this->assertTrue((bool) $stale->fresh()->is_deleted, '过期会话应被软删');
        $this->assertNotNull($stale->fresh()->deleted_at, '过期会话应有软删时间');
        $this->assertSame(2, ChatMessage::where('session_id', 'sess_prune_stale')->where('is_deleted', true)->count(), '过期会话的消息应级联软删');
        $this->assertFalse((bool) $fresh->fresh()->is_deleted, '近期会话不得误删');
        $this->assertSame(1, ChatMessage::where('session_id', 'sess_prune_fresh')->where('is_deleted', false)->count(), '近期会话消息不得误删');
    }

    /**
     * 创建于很久以前但近期有消息往来的老会话不误删（以 updated_at 为准）
     */
    public function test_old_session_with_recent_activity_is_not_pruned(): void
    {
        $userId = $this->registerUser()['userId'];

        $session = $this->createSession($userId, 'sess_prune_active');
        $this->seedMessage($session->session_id, '最近还在聊');
        $this->ageSession($session, 3);

        $this->artisan('chat:prune-sessions')->assertExitCode(0);

        $this->assertFalse((bool) $session->fresh()->is_deleted, '3 天前仍有往来的会话不得误删');
        $this->assertSame(1, ChatMessage::where('session_id', 'sess_prune_active')->where('is_deleted', false)->count());
    }

    /**
     * 命令幂等：重复执行第二次清扫数为 0，无副作用
     */
    public function test_prune_is_idempotent(): void
    {
        $userId = $this->registerUser()['userId'];

        $stale = $this->createSession($userId, 'sess_prune_idem');
        $this->seedMessage($stale->session_id, '很久以前的问题');
        $this->ageSession($stale, 35);

        $this->artisan('chat:prune-sessions')
            ->expectsOutputToContain('已清理 1 个')
            ->assertExitCode(0);

        $this->artisan('chat:prune-sessions')
            ->expectsOutputToContain('没有超过')
            ->assertExitCode(0);

        $this->assertTrue((bool) $stale->fresh()->is_deleted);
    }
}
