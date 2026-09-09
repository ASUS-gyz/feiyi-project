<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 通知创建：被回复 / 评论被赞（票 #47）
 *
 * 事件与主操作同事务写入通知；自我动作不产生通知；
 * 同条评论只在首次被点赞时写通知，反复点赞/取消不堆积。
 */
class NotificationTriggerTest extends TestCase
{
    use InteractsWithApi;

    private function seedPost(): Post
    {
        return Post::create([
            'user_id' => $this->registerUser()['userId'],
            'title' => '测试帖子',
            'content' => '测试内容',
            'category' => 'QA',
        ]);
    }

    /**
     * B 回复 A 的评论：A 收到评论回复通知，related_id 指向触发回复
     */
    public function test_reply_notifies_parent_author(): void
    {
        $author = $this->registerAndLogin();
        $replier = $this->registerAndLogin();
        $post = $this->seedPost();

        $top = $this->withToken($author['token'])->postJson('/api/comments', [
            'postId' => $post->id,
            'content' => 'A 的评论',
        ]);
        $this->assertSuccessEnvelope($top);
        $topId = (int) $top->json('data.id');

        $reply = $this->withToken($replier['token'])->postJson('/api/comments', [
            'postId' => $post->id,
            'content' => 'B 的回复',
            'parentId' => $topId,
        ]);
        $this->assertSuccessEnvelope($reply);
        $replyId = (int) $reply->json('data.id');

        $notification = Notification::where('user_id', $author['userId'])->sole();

        $this->assertSame('NOTIFY_COMMENT_REPLY', $notification->type);
        $this->assertSame($replyId, (int) $notification->related_id);
        $this->assertFalse((bool) $notification->is_read, '新通知应未读');
    }

    /**
     * A 回复自己的评论：不产生通知
     */
    public function test_self_reply_does_not_notify(): void
    {
        $author = $this->registerAndLogin();
        $post = $this->seedPost();

        $top = $this->withToken($author['token'])->postJson('/api/comments', [
            'postId' => $post->id,
            'content' => 'A 的评论',
        ]);
        $topId = (int) $top->json('data.id');

        $this->withToken($author['token'])->postJson('/api/comments', [
            'postId' => $post->id,
            'content' => 'A 自己的回复',
            'parentId' => $topId,
        ]);

        $this->assertSame(0, Notification::count(), '自我回复不应产生通知');
    }

    /**
     * B 点赞 A 的评论：A 收到评论被赞通知；反复点赞/取消不堆积（仅首次点赞时写）
     */
    public function test_comment_like_notifies_author_without_duplication(): void
    {
        $author = $this->registerAndLogin();
        $liker = $this->registerAndLogin();
        $post = $this->seedPost();

        $comment = Comment::create([
            'user_id' => $author['userId'],
            'post_id' => $post->id,
            'content' => 'A 的评论',
        ]);

        $like = $this->withToken($liker['token'])->postJson("/api/comments/{$comment->id}/like");
        $this->assertSuccessEnvelope($like);

        $notification = Notification::where('user_id', $author['userId'])->sole();
        $this->assertSame('NOTIFY_LIKE', $notification->type);
        $this->assertSame($comment->id, (int) $notification->related_id);

        // 取消 → 再点赞：通知不重复堆积
        $this->withToken($liker['token'])->deleteJson("/api/comments/{$comment->id}/like");
        $this->withToken($liker['token'])->postJson("/api/comments/{$comment->id}/like");

        $this->assertSame(1, Notification::where('user_id', $author['userId'])->count(), '反复点赞不得堆积通知');
    }

    /**
     * A 点赞自己的评论：不产生通知
     */
    public function test_self_like_does_not_notify(): void
    {
        $author = $this->registerAndLogin();
        $post = $this->seedPost();

        $comment = Comment::create([
            'user_id' => $author['userId'],
            'post_id' => $post->id,
            'content' => 'A 的评论',
        ]);

        $like = $this->withToken($author['token'])->postJson("/api/comments/{$comment->id}/like");
        $this->assertSuccessEnvelope($like);

        $this->assertSame(0, Notification::count(), '自我点赞不应产生通知');
    }

    /**
     * 现有通知接口读到新数据：列表、未读数、已读行为不变
     */
    public function test_notification_endpoints_return_real_data(): void
    {
        $author = $this->registerAndLogin();
        $liker = $this->registerAndLogin();
        $post = $this->seedPost();

        $comment = Comment::create([
            'user_id' => $author['userId'],
            'post_id' => $post->id,
            'content' => 'A 的评论',
        ]);
        $this->withToken($liker['token'])->postJson("/api/comments/{$comment->id}/like");

        // 未读数
        $unread = $this->withToken($author['token'])->getJson('/api/notifications/unread-count');
        $this->assertSuccessEnvelope($unread);
        $this->assertSame(1, (int) $unread->json('data.total'));
        $this->assertSame(1, (int) $unread->json('data.byType.NOTIFY_LIKE'));

        // 列表
        $list = $this->withToken($author['token'])->getJson('/api/notifications');
        $this->assertSuccessEnvelope($list);
        $this->assertSame(1, (int) $list->json('data.total'));
        $this->assertSame('评论收到新点赞', $list->json('data.list.0.title'));

        // 标记已读后未读数归零
        $notificationId = $list->json('data.list.0.id');
        $read = $this->withToken($author['token'])->postJson("/api/notifications/{$notificationId}/read");
        $this->assertSuccessEnvelope($read);

        $unreadAfter = $this->withToken($author['token'])->getJson('/api/notifications/unread-count');
        $this->assertSame(0, (int) $unreadAfter->json('data.total'));
    }

    /**
     * 通知只发给当事人：其他用户的通知列表不受影响
     */
    public function test_notification_belongs_only_to_the_target_user(): void
    {
        $author = $this->registerAndLogin();
        $liker = $this->registerAndLogin();
        $bystander = $this->registerAndLogin();
        $post = $this->seedPost();

        $comment = Comment::create([
            'user_id' => $author['userId'],
            'post_id' => $post->id,
            'content' => 'A 的评论',
        ]);
        $this->withToken($liker['token'])->postJson("/api/comments/{$comment->id}/like");

        $list = $this->withToken($bystander['token'])->getJson('/api/notifications');
        $this->assertSuccessEnvelope($list);
        $this->assertSame(0, (int) $list->json('data.total'), '旁观者不应看到他人的通知');
        $this->assertSame(1, Notification::where('user_id', $author['userId'])->count());
    }
}
