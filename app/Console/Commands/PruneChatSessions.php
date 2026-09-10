<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneChatSessions extends Command
{
    /**
     * 会话不活跃天数阈值（产品决策：30 天）
     */
    protected $signature = 'chat:prune-sessions {--days=30 : 会话不活跃天数阈值}';

    protected $description = '清理长期不活跃的 AI 会话及其消息（级联软删，幂等可重复执行）';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $threshold = now()->subDays($days);

        $staleSessions = ChatSession::active()
            ->where('updated_at', '<', $threshold);

        $sessionIds = (clone $staleSessions)->pluck('session_id');
        $count = $sessionIds->count();

        if ($count === 0) {
            $this->info("没有超过 {$days} 天不活跃的 AI 会话需要清理。");

            return self::SUCCESS;
        }

        // 软删除会话与消息（is_deleted/deleted_at 不在模型 fillable 内，
        // 须走构建器更新，Eloquent update 会静默丢弃非 fillable 字段）
        $staleSessions->update(['is_deleted' => true, 'deleted_at' => now()]);
        ChatMessage::active()
            ->whereIn('session_id', $sessionIds)
            ->update(['is_deleted' => true, 'deleted_at' => now()]);

        $this->info("已清理 {$count} 个超过 {$days} 天不活跃的 AI 会话。");

        Log::channel('business')->info('清理不活跃 AI 会话', [
            'days' => $days,
            'count' => $count,
        ]);

        return self::SUCCESS;
    }
}
