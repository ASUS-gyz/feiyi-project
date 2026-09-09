<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use App\Models\Comment;
use App\Models\Post;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 存储型 XSS 写侧净化（票 #54）
 *
 * 帖子与评论写点接入共享净化管道：标记载荷经「写入 → 读回」不再含可执行标记，
 * 纯文本比较符不误伤，纯标签内容按净化后长度被参数校验拦截。
 */
class StoredXssSanitizationTest extends TestCase
{
    use InteractsWithApi;

    /**
     * 评论注入脚本载荷：读回文本不含标记，正文其余部分保留
     */
    public function test_comment_script_payload_is_stripped_on_round_trip(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->registerAndLogin();
        $post = Post::create([
            'user_id' => $userId,
            'title' => '测试帖子',
            'content' => '正常内容',
            'category' => 'QA',
        ]);

        $create = $this->withToken($token)->postJson('/api/comments', [
            'postId' => $post->id,
            'content' => '<script>alert(1)</script>正常发言<img src=x onerror=alert(2)>尾随文字',
        ]);
        $this->assertSuccessEnvelope($create);

        $read = $this->getJson("/api/comments/post/{$post->id}");
        $this->assertSuccessEnvelope($read);

        $contents = collect($read->json('data'))->pluck('content')->implode("\n");
        $this->assertStringNotContainsString('<script', $contents, '脚本标签不得存留');
        $this->assertStringNotContainsString('onerror', $contents, '事件属性载体不得存留');
        $this->assertStringContainsString('正常发言', $contents, '正文其余部分应保留');
        $this->assertStringContainsString('尾随文字', $contents, '标签后的文字应保留');
    }

    /**
     * 评论含比较符的纯文本：往返后原样保留，不被误伤
     */
    public function test_comment_with_comparison_operators_is_preserved(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->registerAndLogin();
        $post = Post::create([
            'user_id' => $userId,
            'title' => '测试帖子',
            'content' => '正常内容',
            'category' => 'QA',
        ]);

        $raw = '1 < 2 > 3 是对的吗？';
        $create = $this->withToken($token)->postJson('/api/comments', [
            'postId' => $post->id,
            'content' => $raw,
        ]);
        $this->assertSuccessEnvelope($create);

        $read = $this->getJson("/api/comments/post/{$post->id}");
        $contents = collect($read->json('data'))->pluck('content')->implode("\n");
        $this->assertSame($raw, $contents, '纯文本比较符应原样保留');
    }

    /**
     * 帖子创建与修改路径均净化标题与正文
     */
    public function test_post_create_and_update_strip_markup(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->registerAndLogin();

        $create = $this->withToken($token)->postJson('/api/posts', [
            'title' => '<svg onload=alert(1)>安全提问',
            'content' => '<script>alert(1)</script>帖子正文',
            'category' => 'QA',
        ]);
        $this->assertSuccessEnvelope($create);
        $postId = (int) $create->json('data.id') ?: Post::where('user_id', $userId)->latest('id')->first()->id;

        $detail = $this->getJson("/api/posts/{$postId}");
        $this->assertSuccessEnvelope($detail);
        $this->assertStringNotContainsString('<svg', (string) $detail->json('data.title'), '标题标记不得存留');
        $this->assertStringNotContainsString('<script', (string) $detail->json('data.content'), '正文标记不得存留');
        $this->assertStringContainsString('安全提问', (string) $detail->json('data.title'));
        $this->assertStringContainsString('帖子正文', (string) $detail->json('data.content'));

        // 修改路径
        $update = $this->withToken($token)->putJson("/api/posts/{$postId}", [
            'content' => '<b>加粗</b>更新后的正文',
        ]);
        $this->assertSuccessEnvelope($update);

        $detailAfter = $this->getJson("/api/posts/{$postId}");
        $this->assertStringNotContainsString('<b>', (string) $detailAfter->json('data.content'), '修改路径同样不得存留标记');
        $this->assertStringContainsString('更新后的正文', (string) $detailAfter->json('data.content'));
    }

    /**
     * 纯标签内容（净化后为空）被参数校验拦截，不产生任何写入
     */
    public function test_tag_only_content_is_rejected_by_post_sanitization_length(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->registerAndLogin();
        $post = Post::create([
            'user_id' => $userId,
            'title' => '测试帖子',
            'content' => '正常内容',
            'category' => 'QA',
        ]);

        $response = $this->withToken($token)->postJson('/api/comments', [
            'postId' => $post->id,
            'content' => '<b></b><script></script><!-- -->',
        ]);

        $this->assertErrorEnvelope($response, ResponseCode::PARAM_ERROR->value);
        $this->assertSame(0, Comment::where('post_id', $post->id)->count(), '净化后为空的内容不得入库');
    }
}
