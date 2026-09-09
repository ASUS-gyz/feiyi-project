<?php

namespace Tests\Feature;

use App\Models\Cooperation;
use App\Models\DonationProject;
use App\Models\Game;
use App\Models\GameLevel;
use App\Models\GameScore;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 自由文本写点净化（票 #55）
 *
 * 注册/资料、捐赠留言、共创报名、游戏成绩元数据全部接入共享净化管道：
 * 标记载荷经「写入 → 读回」不再含可执行标记，纯文本比较符与数字/布尔值不误伤。
 */
class FreeTextWriteSanitizationTest extends TestCase
{
    use InteractsWithApi;

    /**
     * 注册昵称与资料简介/地区：读回无标记，正文保留
     */
    public function test_register_and_profile_markup_is_stripped(): void
    {
        $user = $this->registerUser([
            'nickname' => '<script>alert(1)</script>烧箔学徒<img src=x onerror=alert(2)>',
        ]);
        $this->assertStringNotContainsString('<script', $user['nickname'], '注册响应昵称不得存留脚本标记');
        $this->assertStringNotContainsString('onerror', $user['nickname'], '注册响应昵称不得存留事件属性');
        $this->assertStringContainsString('烧箔学徒', $user['nickname']);

        $token = $this->loginUser($user['username'], $user['password']);
        $update = $this->withToken($token)->putJson('/api/users/me', [
            'bio' => '<b>爱烧箔</b><script>alert(2)</script>十年',
            'region' => '1 < 2 > 3 苏州',
        ]);
        $this->assertSuccessEnvelope($update);

        $me = $this->withToken($token)->getJson('/api/auth/me');
        $this->assertSuccessEnvelope($me);
        $bio = (string) $me->json('data.bio');
        $region = (string) $me->json('data.region');
        $this->assertStringNotContainsString('<b>', $bio, '简介标记不得存留');
        $this->assertStringNotContainsString('<script', $bio, '简介脚本不得存留');
        $this->assertStringContainsString('爱烧箔', $bio);
        $this->assertStringContainsString('十年', $bio);
        $this->assertSame('1 < 2 > 3 苏州', $region, '纯文本比较符不误伤');
    }

    /**
     * 捐赠留言注入被剥离，留言读回无标记
     */
    public function test_donation_message_markup_is_stripped(): void
    {
        $project = DonationProject::create([
            'title' => '烧箔画抢救性记录',
            'description' => '为濒危烧箔技艺建立数字档案',
            'target_amount' => 100000,
        ]);
        ['token' => $token] = $this->registerAndLogin();

        $create = $this->withToken($token)->postJson('/api/donations', [
            'projectId' => $project->id,
            'amount' => 100,
            'message' => '<script>alert(1)</script>为传承尽绵薄之力<img src=x onerror=alert(2)>',
        ]);
        $this->assertSuccessEnvelope($create);

        $records = $this->withToken($token)->getJson('/api/donations/records');
        $this->assertSuccessEnvelope($records);
        $message = (string) $records->json('data.list.0.message');
        $this->assertStringNotContainsString('<script', $message, '留言标记不得存留');
        $this->assertStringNotContainsString('onerror', $message, '留言事件属性不得存留');
        $this->assertStringContainsString('为传承尽绵薄之力', $message);
    }

    /**
     * 捐赠留言纯文本比较符原样保留，不被误伤
     */
    public function test_donation_message_plain_comparison_operators_pass_through(): void
    {
        $project = DonationProject::create([
            'title' => '烧箔画抢救性记录',
            'description' => '为濒危烧箔技艺建立数字档案',
            'target_amount' => 100000,
        ]);
        ['token' => $token] = $this->registerAndLogin();

        $raw = '1 < 2 > 3 一点心意';
        $this->assertSuccessEnvelope($this->withToken($token)->postJson('/api/donations', [
            'projectId' => $project->id,
            'amount' => 100,
            'message' => $raw,
        ]));

        $records = $this->withToken($token)->getJson('/api/donations/records');
        $this->assertSame($raw, (string) $records->json('data.list.0.message'));
    }

    /**
     * 共创报名标题/描述/署名注入被剥离，署名纯文本比较符不误伤
     */
    public function test_cooperation_submission_markup_is_stripped(): void
    {
        $cooperation = Cooperation::create([
            'title' => '共创烧箔新纹样',
            'description' => '面向公众征集烧箔纹样设计',
            'deadline' => now()->addMonth()->format('Y-m-d'),
            'status' => 'COOP_COLLECTING',
            'images' => [],
            'submission_count' => 0,
        ]);
        ['token' => $token] = $this->registerAndLogin();

        $create = $this->withToken($token)->postJson("/api/cooperations/{$cooperation->id}/submissions", [
            'title' => '<svg onload=alert(1)>金箔虎',
            'description' => '<script>alert(2)</script>以金箔塑造虎形，取辟邪纳福之意',
            'images' => ['https://example.com/design-a.png'],
            'authorName' => '1 < 2 > 3 匠人',
        ]);
        $this->assertSuccessEnvelope($create);

        $mine = $this->withToken($token)->getJson('/api/cooperations/submissions/my');
        $this->assertSuccessEnvelope($mine);
        $first = $mine->json('data.list.0');
        $this->assertStringNotContainsString('<svg', (string) $first['title'], '报名标题标记不得存留');
        $this->assertStringContainsString('金箔虎', (string) $first['title']);
        $this->assertStringNotContainsString('<script', (string) $first['description'], '报名描述标记不得存留');
        $this->assertStringContainsString('以金箔塑造虎形', (string) $first['description']);
        $this->assertSame('1 < 2 > 3 匠人', (string) $first['authorName'], '署名纯文本比较符不误伤');
    }

    /**
     * 游戏成绩元数据 JSON 内字符串值递归净化，非字符串值与结构原样保留
     */
    public function test_game_score_metadata_is_sanitized_recursively(): void
    {
        $game = Game::create([
            'type' => 'GAME_FIRE',
            'title' => '守艺挑战',
            'description' => '火候控制小游戏',
            'is_enabled' => true,
        ]);
        $level = GameLevel::create(['game_id' => $game->id, 'name' => '第一关', 'sort_order' => 1]);
        ['token' => $token] = $this->registerAndLogin();

        $submit = $this->withToken($token)->postJson('/api/games/scores', [
            'gameType' => 'GAME_FIRE',
            'levelId' => $level->id,
            'score' => 88.5,
            'duration' => 65,
            'difficulty' => 'DIFFICULTY_EASY',
            'metadata' => [
                'strokes' => 12,
                'note' => '<script>alert(1)</script>火候正好',
                'nested' => [
                    'author' => '<img src=x onerror=alert(2)>匿名',
                    'flags' => [true, null, 3],
                    'deep' => ['text' => '<b>垫长</b>尾巴'],
                ],
                'plain' => '1 < 2 > 3 攻略',
            ],
        ]);
        $this->assertSuccessEnvelope($submit);

        /** @var GameScore $score */
        $score = GameScore::query()->latest('id')->first();
        $metadata = $score->metadata;
        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        $this->assertIsArray($metadata);
        $this->assertStringNotContainsString('<script', $encoded, '元数据脚本标记不得存留');
        $this->assertStringNotContainsString('onerror', $encoded, '元数据事件属性不得存留');
        $this->assertStringNotContainsString('<b>', $encoded, '深层嵌套标记不得存留');
        $this->assertStringContainsString('火候正好', $encoded);
        $this->assertStringContainsString('匿名', $encoded);
        $this->assertStringContainsString('尾巴', $encoded);
        $this->assertSame(12, $metadata['strokes'], '数字值原样保留');
        $this->assertTrue($metadata['nested']['flags'][0], '布尔值原样保留');
        $this->assertNull($metadata['nested']['flags'][1], 'null 值原样保留');
        $this->assertSame(3, $metadata['nested']['flags'][2]);
        $this->assertSame('1 < 2 > 3 攻略', $metadata['plain'], '纯文本比较符不误伤');
    }
}
