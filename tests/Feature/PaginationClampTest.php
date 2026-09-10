<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use App\Models\Event;
use App\Models\GameScore;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 分页解析器统一钳制（票 #60）
 *
 * 活动列表/捐赠记录两个裸端点首次获得钳制防护（超大 pageSize 裁 100、page<1 回落 1）；
 * 服务层列表端点迁移后默认值（名作 10、通用 20）与信封形状不变；
 * 排行榜切片分页迁移后排名计算不受影响。
 * 表单请求类端点的分页规则本票未动（非法参数仍 10001，语义翻转在下一票）。
 */
class PaginationClampTest extends TestCase
{
    use InteractsWithApi;

    private function seedEvents(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Event::create([
                'title' => "烧箔体验活动{$i}",
                'location' => '苏州',
                'description' => '非遗烧箔画体验',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'EVENT_ONGOING',
            ]);
        }
    }

    /**
     * 活动列表（裸端点）：超大 pageSize 裁到 100，数据总量不受影响
     */
    public function test_event_list_clamps_oversized_page_size(): void
    {
        $this->seedEvents(3);

        $response = $this->getJson('/api/events?pageSize=999999');
        $this->assertSuccessEnvelope($response);
        $this->assertSame(100, $response->json('data.pageSize'), '超大 pageSize 应被裁到 100');
        $this->assertSame(3, $response->json('data.total'));
    }

    /**
     * 捐赠记录（裸端点）：page=0 回落 1，超大 pageSize 裁到 100
     */
    public function test_donation_records_clamp_page_and_size(): void
    {
        ['token' => $token] = $this->registerAndLogin();

        $response = $this->withToken($token)->getJson('/api/donations/records?page=0&pageSize=5000');
        $this->assertSuccessEnvelope($response);
        $this->assertSame(1, $response->json('data.page'), 'page<1 应回落 1');
        $this->assertSame(100, $response->json('data.pageSize'));
    }

    /**
     * 非数字分页参数回落端点默认值（默认 20），不报错
     */
    public function test_non_numeric_pagination_falls_back_to_defaults(): void
    {
        ['token' => $token] = $this->registerAndLogin();

        $response = $this->withToken($token)->getJson('/api/donations/records?page=abc&pageSize=xyz');
        $this->assertSuccessEnvelope($response);
        $this->assertSame(1, $response->json('data.page'));
        $this->assertSame(20, $response->json('data.pageSize'));
    }

    /**
     * 端点默认页大小保留既有差异：名作列表 10，通用列表 20
     */
    public function test_endpoint_default_page_sizes_are_preserved(): void
    {
        $masterpieces = $this->getJson('/api/masterpieces');
        $this->assertSuccessEnvelope($masterpieces);
        $this->assertSame(10, $masterpieces->json('data.pageSize'), '名作列表默认页大小应为 10');

        $posts = $this->getJson('/api/posts');
        $this->assertSuccessEnvelope($posts);
        $this->assertSame(20, $posts->json('data.pageSize'), '通用列表默认页大小应为 20');
    }

    /**
     * 排行榜切片迁移后排名不受影响：按最佳成绩降序，跨页 rank 连续
     */
    public function test_leaderboard_rank_math_is_unchanged_after_migration(): void
    {
        $u1 = $this->registerUser()['userId'];
        $u2 = $this->registerUser()['userId'];
        GameScore::create(['user_id' => $u1, 'game_type' => 'GAME_FIRE', 'level_id' => 1, 'score' => 70, 'duration' => 30]);
        GameScore::create(['user_id' => $u2, 'game_type' => 'GAME_FIRE', 'level_id' => 1, 'score' => 90, 'duration' => 25]);

        $page1 = $this->getJson('/api/games/GAME_FIRE/leaderboard?pageSize=1&page=1');
        $this->assertSuccessEnvelope($page1);
        $this->assertSame(1, $page1->json('data.list.0.rank'), '榜首排名应为 1');
        $this->assertSame(90, $page1->json('data.list.0.score'), '榜首应为最高分');
        $this->assertSame(1, $page1->json('data.pageSize'), '合法 pageSize 不受钳制影响');

        $page2 = $this->getJson('/api/games/GAME_FIRE/leaderboard?pageSize=1&page=2');
        $this->assertSuccessEnvelope($page2);
        $this->assertSame(2, $page2->json('data.list.0.rank'), '第二页排名应连续为 2');
        $this->assertSame(70, $page2->json('data.list.0.score'));
    }

    /**
     * 本票边界：表单请求类端点的分页规则未动，非法参数仍 10001（语义翻转在下一票）
     */
    public function test_form_request_endpoints_still_reject_invalid_pagination(): void
    {
        $response = $this->getJson('/api/posts?page=0');
        $this->assertErrorEnvelope($response, ResponseCode::PARAM_ERROR->value);
    }
}
