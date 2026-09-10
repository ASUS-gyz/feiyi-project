<?php

namespace Tests\Feature;

use App\Models\Masterpiece;
use App\Models\Notification;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 作品被赞通知（票 #76，#47 豁免项补完）
 *
 * 有归属的名作被他人点赞时，作者收到「作品被赞」通知（NOTIFY_LIKE，
 * related_id 指向作品），与点赞主操作同事务；自我点赞、无归属名作
 * 被赞不产生通知；点赞/取消反复操作按同事件去重，通知不堆积。
 */
class MasterpieceLikeNotificationTest extends TestCase
{
    use InteractsWithApi;

    private function seedMasterpiece(?int $authorId = null): Masterpiece
    {
        return Masterpiece::create([
            'name' => '被赞测试名作',
            'period' => '宋代',
            'school' => '测试流派',
            'cover_image' => 'covers/test.jpg',
            'user_id' => $authorId,
        ]);
    }

    /**
     * B 点赞 A 归属的名作 → A 收到作品被赞通知，B 不收到
     */
    public function test_liking_owned_masterpiece_notifies_author(): void
    {
        ['token' => $authorToken, 'userId' => $authorId] = $this->registerAndLogin(['nickname' => '匠人甲']);
        ['token' => $likerToken] = $this->registerAndLogin(['nickname' => '路过的赞者']);
        $masterpiece = $this->seedMasterpiece($authorId);

        $response = $this->withToken($likerToken)->postJson("/api/masterpieces/{$masterpiece->id}/like");
        $this->assertSuccessEnvelope($response);

        $notifications = $this->withToken($authorToken)->getJson('/api/notifications');
        $this->assertSuccessEnvelope($notifications);

        $likes = collect($notifications->json('data.list'))->where('type', 'NOTIFY_LIKE');
        $this->assertSame(1, $likes->count(), '作者应收到恰好一条作品被赞通知');
        $notice = $likes->first();
        $this->assertSame($masterpiece->id, $notice['relatedId'], 'related_id 应指向被赞作品');
        $this->assertSame('作品收到新点赞', $notice['title']);
        $this->assertFalse($notice['isRead'], '新通知应未读');

        $likerNotifications = $this->withToken($likerToken)->getJson('/api/notifications');
        $this->assertSame(0, collect($likerNotifications->json('data.list'))->count(), '点赞者本人不应收到通知');
    }

    /**
     * 作者点赞自己的名作不产生任何通知
     */
    public function test_self_like_does_not_notify(): void
    {
        ['token' => $authorToken, 'userId' => $authorId] = $this->registerAndLogin();
        $masterpiece = $this->seedMasterpiece($authorId);

        $response = $this->withToken($authorToken)->postJson("/api/masterpieces/{$masterpiece->id}/like");
        $this->assertSuccessEnvelope($response);
        $this->assertSame(1, (int) $response->json('data'), '自赞计数照常');

        $this->assertSame(0, Notification::count(), '自我点赞不得产生通知');
    }

    /**
     * 无归属名作被赞不产生通知（没有接收者）
     */
    public function test_unowned_masterpiece_like_writes_no_notification(): void
    {
        ['token' => $likerToken] = $this->registerAndLogin();
        $masterpiece = $this->seedMasterpiece();

        $response = $this->withToken($likerToken)->postJson("/api/masterpieces/{$masterpiece->id}/like");
        $this->assertSuccessEnvelope($response);
        $this->assertSame(1, (int) $response->json('data'), '无归属名作点赞计数照常');

        $this->assertSame(0, Notification::count(), '无归属名作被赞不得产生通知');
    }

    /**
     * 点赞-取消-再赞反复操作：通知按同事件去重不堆积（与评论被赞约定一致）
     */
    public function test_like_unlike_cycle_does_not_stack_notifications(): void
    {
        ['token' => $authorToken, 'userId' => $authorId] = $this->registerAndLogin();
        ['token' => $likerToken] = $this->registerAndLogin();
        $masterpiece = $this->seedMasterpiece($authorId);

        $this->withToken($likerToken)->postJson("/api/masterpieces/{$masterpiece->id}/like");
        $this->withToken($likerToken)->deleteJson("/api/masterpieces/{$masterpiece->id}/like");
        $this->withToken($likerToken)->postJson("/api/masterpieces/{$masterpiece->id}/like");
        // 幂等重复点赞同样不得堆积
        $this->withToken($likerToken)->postJson("/api/masterpieces/{$masterpiece->id}/like");

        $notifications = $this->withToken($authorToken)->getJson('/api/notifications');
        $likes = collect($notifications->json('data.list'))->where('type', 'NOTIFY_LIKE');
        $this->assertSame(1, $likes->count(), '反复点赞后作者仍应只有一条作品被赞通知');
        $this->assertSame(1, (int) $masterpiece->fresh()->like_count, '点赞行与计数保持一致');
    }
}
