<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 评论创建/删除事务化与孤儿回复清理（票 #46）
 *
 * 删除带回复的评论时其全部活跃子孙一并软删，帖子评论数按实际隐藏总数
 * 回减，删除回复时父楼 reply_count 回减；创建评论计数同事务维护。
 */
class CommentConsistencyTest extends TestCase
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

    private function createComment(string $token, int $postId, ?int $parentId = null, string $content = '评论内容'): int
    {
        $payload = array_filter([
            'postId' => $postId,
            'content' => $content,
            'parentId' => $parentId,
        ]);

        $response = $this->withToken($token)->postJson('/api/comments', $payload);
        $this->assertSuccessEnvelope($response);

        return (int) $response->json('data.id');
    }

    /**
     * 删除顶层楼：全部活跃子回复一并软删，帖子评论数按隐藏总数回减
     */
    public function test_deleting_top_level_comment_hides_all_replies_and_decrements_counts(): void
    {
        $author = $this->registerAndLogin();
        $replier = $this->registerAndLogin();
        $post = $this->seedPost();

        $topId = $this->createComment($author['token'], $post->id, content: '顶层楼');
        $this->createComment($replier['token'], $post->id, $topId, '回复一');
        $this->createComment($replier['token'], $post->id, $topId, '回复二');

        $this->assertSame(3, (int) $post->fresh()->comment_count);
        $this->assertSame(2, (int) Comment::find($topId)->reply_count);

        $delete = $this->withToken($author['token'])->deleteJson("/api/comments/{$topId}");
        $this->assertSuccessEnvelope($delete);

        $this->assertSame(0, Comment::active()->where('post_id', $post->id)->count(), '楼与其回复应全部隐藏');
        $this->assertSame(0, (int) $post->fresh()->comment_count, '帖子评论数应按隐藏总数回减');

        Comment::where('post_id', $post->id)->get()->each(
            fn (Comment $c) => $this->assertTrue((bool) $c->is_deleted, '每条评论都应被软删（不留孤儿）')
        );
    }

    /**
     * 删除楼中楼回复：父楼 reply_count 回减、帖子评论数回减
     */
    public function test_deleting_a_reply_decrements_parent_reply_count(): void
    {
        $author = $this->registerAndLogin();
        $replier = $this->registerAndLogin();
        $post = $this->seedPost();

        $topId = $this->createComment($author['token'], $post->id, content: '顶层楼');
        $replyId = $this->createComment($replier['token'], $post->id, $topId, '楼中楼');

        $delete = $this->withToken($replier['token'])->deleteJson("/api/comments/{$replyId}");
        $this->assertSuccessEnvelope($delete);

        $this->assertTrue((bool) Comment::find($replyId)->is_deleted);
        $this->assertSame(0, (int) Comment::find($topId)->reply_count, '父楼回复数应回减');
        $this->assertFalse((bool) Comment::find($topId)->is_deleted, '父楼应保持活跃');
        $this->assertSame(1, (int) $post->fresh()->comment_count);
    }

    /**
     * 多层嵌套：删除祖先楼时全部后代（含孙回复）一并隐藏
     */
    public function test_deleting_ancestor_hides_all_descendants(): void
    {
        $author = $this->registerAndLogin();
        $post = $this->seedPost();

        $topId = $this->createComment($author['token'], $post->id, content: '顶层楼');
        $replyId = $this->createComment($author['token'], $post->id, $topId, '子回复');
        $grandchildId = $this->createComment($author['token'], $post->id, $replyId, '孙回复');

        $delete = $this->withToken($author['token'])->deleteJson("/api/comments/{$topId}");
        $this->assertSuccessEnvelope($delete);

        foreach ([$topId, $replyId, $grandchildId] as $id) {
            $this->assertTrue((bool) Comment::find($id)->is_deleted, "后代评论 {$id} 应被软删");
        }
        $this->assertSame(0, (int) $post->fresh()->comment_count);
    }

    /**
     * 无回复的评论删除行为不变（回归）：仅自身隐藏、计数回减 1
     */
    public function test_deleting_leaf_comment_keeps_previous_behavior(): void
    {
        $author = $this->registerAndLogin();
        $post = $this->seedPost();

        $commentId = $this->createComment($author['token'], $post->id, content: '独立评论');
        $this->assertSame(1, (int) $post->fresh()->comment_count);

        $delete = $this->withToken($author['token'])->deleteJson("/api/comments/{$commentId}");
        $this->assertSuccessEnvelope($delete);

        $this->assertTrue((bool) Comment::find($commentId)->is_deleted);
        $this->assertSame(0, (int) $post->fresh()->comment_count);
    }

    /**
     * 回复创建后父楼 reply_count、帖子 comment_count 立即一致
     */
    public function test_creating_reply_keeps_counters_consistent(): void
    {
        $author = $this->registerAndLogin();
        $post = $this->seedPost();

        $topId = $this->createComment($author['token'], $post->id, content: '顶层楼');
        $this->assertSame(1, (int) $post->fresh()->comment_count);
        $this->assertSame(0, (int) Comment::find($topId)->reply_count, '顶层评论不产生回复数');

        $this->createComment($author['token'], $post->id, $topId, '回复');

        $this->assertSame(2, (int) $post->fresh()->comment_count);
        $this->assertSame(1, (int) Comment::find($topId)->reply_count);
    }
}
