<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use App\Models\Comment;
use App\Models\CommentLike;
use App\Models\Post;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 评论与点赞身份唯一化（票 #28）
 *
 * 发评论、点赞、取消点赞强制登录，身份一律取自 JWT；
 * 客户端自报的用户标识（User-ID 头/查询参数/请求体）不再产生任何效果。
 */
class CommentIdentityTest extends TestCase
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

    private function seedComment(int $postId): Comment
    {
        return Comment::create([
            'user_id' => $this->registerUser()['userId'],
            'post_id' => $postId,
            'content' => '被点赞的评论',
        ]);
    }

    /**
     * 未携带 token 的评论/点赞/取消点赞被拒（20001），不产生任何数据
     */
    public function test_guest_cannot_comment_like_or_unlike(): void
    {
        $postId = $this->seedPost()->id;
        $comment = $this->seedComment($postId);

        $commentResponse = $this->postJson('/api/comments', [
            'postId' => $postId,
            'content' => '游客评论',
        ]);
        $this->assertErrorEnvelope($commentResponse, ResponseCode::UNAUTHORIZED->value);

        $likeResponse = $this->postJson("/api/comments/{$comment->id}/like");
        $this->assertErrorEnvelope($likeResponse, ResponseCode::UNAUTHORIZED->value);

        $unlikeResponse = $this->deleteJson("/api/comments/{$comment->id}/like");
        $this->assertErrorEnvelope($unlikeResponse, ResponseCode::UNAUTHORIZED->value);

        $this->assertSame(1, Comment::count(), '游客发评论不应产生新数据（仅种子评论）');
        $this->assertSame(0, CommentLike::count(), '游客点赞不应产生数据');
        $this->assertSame(0, (int) $comment->fresh()->like_count, '游客点赞不应改变计数');
    }

    /**
     * 携带用户 A 的 token 但自报用户 B 的身份发评论：评论归属 A，自报身份被忽略
     */
    public function test_self_reported_identity_is_ignored_comment_attributed_to_token_user(): void
    {
        ['token' => $tokenA, 'userId' => $userAId] = $this->registerAndLogin();
        $userBId = $this->registerUser()['userId'];
        $postId = $this->seedPost()->id;

        // 三种自报通道全部尝试：User-ID 头、查询参数、请求体
        $viaHeader = $this->withToken($tokenA)
            ->withHeaders(['User-ID' => (string) $userBId])
            ->postJson('/api/comments', ['postId' => $postId, 'content' => 'A 的评论']);
        $this->assertSuccessEnvelope($viaHeader);

        $viaQuery = $this->withToken($tokenA)
            ->postJson("/api/comments?User-ID={$userBId}", ['postId' => $postId, 'content' => 'A 的评论']);
        $this->assertSuccessEnvelope($viaQuery);

        $viaBody = $this->withToken($tokenA)
            ->postJson('/api/comments', ['postId' => $postId, 'content' => 'A 的评论', 'userId' => $userBId]);
        $this->assertSuccessEnvelope($viaBody);

        $this->assertSame(3, Comment::count());
        Comment::all()->each(
            fn (Comment $c) => $this->assertSame($userAId, (int) $c->user_id, '评论应归属 token 用户 A，自报身份 B 被忽略')
        );
    }

    /**
     * 点赞/取消点赞以 token 用户身份记录，重复点赞与既有业务规则一致
     */
    public function test_like_and_unlike_record_token_user_identity_with_business_rules(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->registerAndLogin();
        $comment = $this->seedComment($this->seedPost()->id);

        $like = $this->withToken($token)->postJson("/api/comments/{$comment->id}/like");
        $this->assertSuccessEnvelope($like);
        $this->assertSame(1, (int) $like->json('data'));

        $this->assertSame(1, CommentLike::count());
        $this->assertSame($userId, (int) CommentLike::first()->user_id);
        $this->assertSame(1, (int) $comment->fresh()->like_count);

        // 重复点赞 → 40009
        $duplicate = $this->withToken($token)->postJson("/api/comments/{$comment->id}/like");
        $this->assertErrorEnvelope($duplicate, ResponseCode::BUSINESS_DUPLICATE->value);

        // 取消点赞 → 记录删除、计数回落
        $unlike = $this->withToken($token)->deleteJson("/api/comments/{$comment->id}/like");
        $this->assertSuccessEnvelope($unlike);
        $this->assertSame(0, (int) $unlike->json('data'));
        $this->assertSame(0, CommentLike::count());
        $this->assertSame(0, (int) $comment->fresh()->like_count);

        // 未点赞再取消 → 40002
        $again = $this->withToken($token)->deleteJson("/api/comments/{$comment->id}/like");
        $this->assertErrorEnvelope($again, ResponseCode::BUSINESS_INVALID_STATE->value);
    }
}
