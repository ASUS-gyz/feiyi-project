<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 评论管理列表管理员门槛（票 #29）
 *
 * 全量评论列表为后台管理用途：未认证 20001、普通用户 20005、管理员正常分页访问。
 */
class CommentAdminAccessTest extends TestCase
{
    use InteractsWithApi;

    /**
     * 种子用户直接建模型，不占用注册接口的限流配额
     */
    private function seedUser(): User
    {
        return User::create([
            'name' => $this->uniqueUsername(),
            'password' => bcrypt('password123'),
        ]);
    }

    private function seedComments(int $count): void
    {
        $post = Post::create([
            'user_id' => $this->seedUser()->id,
            'title' => '测试帖子',
            'content' => '测试内容',
            'category' => 'QA',
        ]);

        for ($i = 1; $i <= $count; $i++) {
            Comment::create([
                'user_id' => $this->seedUser()->id,
                'post_id' => $post->id,
                'content' => "评论 {$i}",
            ]);
        }
    }

    private function promoteToAdmin(string $username): void
    {
        $user = User::where('name', $username)->firstOrFail();
        $user->role = 'ADMIN';
        $user->save();
    }

    /**
     * 未认证访问返回 20001
     */
    public function test_guest_gets_unauthorized(): void
    {
        $response = $this->getJson('/api/comments');

        $this->assertErrorEnvelope($response, ResponseCode::UNAUTHORIZED->value);
    }

    /**
     * 已认证普通用户访问返回 20005
     */
    public function test_normal_user_gets_forbidden(): void
    {
        ['token' => $token] = $this->registerAndLogin();

        $response = $this->withToken($token)->getJson('/api/comments');

        $this->assertErrorEnvelope($response, ResponseCode::FORBIDDEN->value);
    }

    /**
     * 管理员正常访问，分页行为与响应结构与既有约定一致
     */
    public function test_admin_gets_full_list_with_unchanged_pagination(): void
    {
        ['username' => $adminName] = $this->registerUser();
        $this->promoteToAdmin($adminName);
        $token = $this->loginUser($adminName, 'password123');
        $this->seedComments(3);

        $response = $this->withToken($token)->getJson('/api/comments?page=1&pageSize=2');

        $this->assertSuccessEnvelope($response);
        $data = $response->json('data');
        $this->assertSame(3, $data['total']);
        $this->assertCount(2, $data['list']);
        $this->assertSame(1, $data['page']);
        $this->assertSame(2, $data['pageSize']);

        // 翻页取剩余一条
        $page2 = $this->withToken($token)->getJson('/api/comments?page=2&pageSize=2');
        $this->assertSuccessEnvelope($page2);
        $this->assertCount(1, $page2->json('data.list'));
    }
}
