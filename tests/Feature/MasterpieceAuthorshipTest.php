<?php

namespace Tests\Feature;

use App\Models\Masterpiece;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 名作作者归属（票 #75）
 *
 * 有归属的名作在列表/详情透出作者标识与昵称；无归属（存量策展名作）
 * 作者字段为 null，列表/详情/点赞行为与升级前完全一致。
 */
class MasterpieceAuthorshipTest extends TestCase
{
    use InteractsWithApi;

    private function seedMasterpiece(?int $authorId = null): Masterpiece
    {
        return Masterpiece::create([
            'name' => '归属测试名作',
            'period' => '宋代',
            'school' => '测试流派',
            'cover_image' => 'covers/test.jpg',
            'user_id' => $authorId,
        ]);
    }

    /**
     * 有归属名作：列表与详情透出作者标识与昵称
     */
    public function test_owned_masterpiece_exposes_author_on_list_and_detail(): void
    {
        ['userId' => $authorId] = $this->registerAndLogin(['nickname' => '匠人甲']);
        $masterpiece = $this->seedMasterpiece($authorId);

        $list = $this->getJson('/api/masterpieces');
        $this->assertSuccessEnvelope($list);
        $row = collect($list->json('data.list'))->firstWhere('id', $masterpiece->id);
        $this->assertNotNull($row, '有归属名作应出现在列表中');
        $this->assertSame($authorId, $row['authorId'], '列表应透出作者标识');
        $this->assertSame('匠人甲', $row['authorNickname'], '列表应透出作者昵称');

        $detail = $this->getJson("/api/masterpieces/{$masterpiece->id}");
        $this->assertSuccessEnvelope($detail);
        $this->assertSame($authorId, $detail->json('data.authorId'), '详情应透出作者标识');
        $this->assertSame('匠人甲', $detail->json('data.authorNickname'), '详情应透出作者昵称');
    }

    /**
     * 无归属名作：作者字段为 null，其余字段照常
     */
    public function test_unowned_masterpiece_has_null_author_fields(): void
    {
        $masterpiece = $this->seedMasterpiece();

        $detail = $this->getJson("/api/masterpieces/{$masterpiece->id}");
        $this->assertSuccessEnvelope($detail);
        $this->assertNull($detail->json('data.authorId'), '无归属名作作者标识应为 null');
        $this->assertNull($detail->json('data.authorNickname'), '无归属名作作者昵称应为 null');
        $this->assertSame('归属测试名作', $detail->json('data.name'), '其余字段照常返回');
    }

    /**
     * 回归：无归属名作被点赞，行为与升级前一致（计数正确、响应结构不变）
     */
    public function test_unowned_masterpiece_like_behaviour_unchanged(): void
    {
        ['token' => $token] = $this->registerAndLogin();
        $masterpiece = $this->seedMasterpiece();

        $response = $this->withToken($token)->postJson("/api/masterpieces/{$masterpiece->id}/like");
        $this->assertSuccessEnvelope($response);
        $this->assertSame(1, (int) $response->json('data'));

        $detail = $this->getJson("/api/masterpieces/{$masterpiece->id}");
        $this->assertSame(1, $detail->json('data.likeCount'));
        $this->assertNull($detail->json('data.authorId'));
    }
}
