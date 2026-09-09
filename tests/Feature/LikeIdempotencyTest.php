<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CommentLike;
use App\Models\Masterpiece;
use App\Models\MasterpieceLike;
use App\Models\Post;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 点赞/取消点赞事务化与并发幂等（票 #45）
 *
 * 点赞写入与计数增减同事务提交；重复点赞、未赞而取消一律幂等成功，
 * 点赞行不重复、计数不虚增不变负；响应结构不变。
 */
class LikeIdempotencyTest extends TestCase
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

    private function seedMasterpiece(): Masterpiece
    {
        return Masterpiece::create([
            'name' => '测试名作',
            'period' => '宋代',
            'school' => '测试流派',
            'cover_image' => 'covers/test.jpg',
            'user_id' => $this->registerUser()['userId'],
        ]);
    }

    /**
     * 评论：重复点赞幂等成功，点赞行与计数保持一致
     */
    public function test_duplicate_comment_like_is_idempotent(): void
    {
        ['token' => $token] = $this->registerAndLogin();
        $comment = $this->seedComment($this->seedPost()->id);

        $first = $this->withToken($token)->postJson("/api/comments/{$comment->id}/like");
        $this->assertSuccessEnvelope($first);
        $this->assertSame(1, (int) $first->json('data'));

        $second = $this->withToken($token)->postJson("/api/comments/{$comment->id}/like");

        $this->assertSuccessEnvelope($second);
        $this->assertSame(1, (int) $second->json('data'), '重复点赞应返回当前计数');
        $this->assertSame(1, CommentLike::count(), '点赞行不得重复');
        $this->assertSame(1, (int) $comment->fresh()->like_count, '计数不得虚增');
    }

    /**
     * 评论：未点赞时取消幂等成功，计数不变负
     */
    public function test_unlike_without_like_is_idempotent(): void
    {
        ['token' => $token] = $this->registerAndLogin();
        $comment = $this->seedComment($this->seedPost()->id);

        $response = $this->withToken($token)->deleteJson("/api/comments/{$comment->id}/like");

        $this->assertSuccessEnvelope($response);
        $this->assertSame(0, (int) $response->json('data'));
        $this->assertSame(0, CommentLike::count());
        $this->assertSame(0, (int) $comment->fresh()->like_count);
    }

    /**
     * 评论：点赞-取消-再点赞循环后，行与计数完全一致
     */
    public function test_like_unlike_cycle_keeps_row_and_count_consistent(): void
    {
        ['token' => $token] = $this->registerAndLogin();
        $comment = $this->seedComment($this->seedPost()->id);

        $this->withToken($token)->postJson("/api/comments/{$comment->id}/like");
        $this->withToken($token)->deleteJson("/api/comments/{$comment->id}/like");
        $cycle = $this->withToken($token)->postJson("/api/comments/{$comment->id}/like");

        $this->assertSuccessEnvelope($cycle);
        $this->assertSame(1, (int) $cycle->json('data'));
        $this->assertSame(1, CommentLike::count());
        $this->assertSame(1, (int) $comment->fresh()->like_count);
    }

    /**
     * 名作：重复点赞幂等成功，点赞行与计数保持一致
     */
    public function test_duplicate_masterpiece_like_is_idempotent(): void
    {
        ['token' => $token] = $this->registerAndLogin();
        $masterpiece = $this->seedMasterpiece();

        $first = $this->withToken($token)->postJson("/api/masterpieces/{$masterpiece->id}/like");
        $this->assertSuccessEnvelope($first);
        $this->assertSame(1, (int) $first->json('data'));

        $second = $this->withToken($token)->postJson("/api/masterpieces/{$masterpiece->id}/like");

        $this->assertSuccessEnvelope($second);
        $this->assertSame(1, (int) $second->json('data'));
        $this->assertSame(1, MasterpieceLike::count(), '点赞行不得重复');
        $this->assertSame(1, (int) $masterpiece->fresh()->like_count, '计数不得虚增');
    }

    /**
     * 名作：未点赞时取消幂等成功，计数不变负
     */
    public function test_unlike_masterpiece_without_like_is_idempotent(): void
    {
        ['token' => $token] = $this->registerAndLogin();
        $masterpiece = $this->seedMasterpiece();

        $response = $this->withToken($token)->deleteJson("/api/masterpieces/{$masterpiece->id}/like");

        $this->assertSuccessEnvelope($response);
        $this->assertSame(0, (int) $response->json('data'));
        $this->assertSame(0, MasterpieceLike::count());
        $this->assertSame(0, (int) $masterpiece->fresh()->like_count);
    }

    /**
     * 多用户点赞计数正确累计，单人取消后回减准确
     */
    public function test_multiple_users_like_counts_accumulate_correctly(): void
    {
        $userA = $this->registerAndLogin();
        $userB = $this->registerAndLogin();
        $masterpiece = $this->seedMasterpiece();

        $this->withToken($userA['token'])->postJson("/api/masterpieces/{$masterpiece->id}/like");
        $second = $this->withToken($userB['token'])->postJson("/api/masterpieces/{$masterpiece->id}/like");
        $this->assertSame(2, (int) $second->json('data'));

        $this->withToken($userA['token'])->deleteJson("/api/masterpieces/{$masterpiece->id}/like");

        $this->assertSame(1, MasterpieceLike::count());
        $this->assertSame(1, (int) $masterpiece->fresh()->like_count);
    }
}
