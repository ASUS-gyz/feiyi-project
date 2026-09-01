<?php

namespace Database\Seeders;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->value('id');

        // 没有用户时创建一个演示账号
        if (! $userId) {
            $userId = User::create([
                'name'     => 'demo',
                'password' => Hash::make('123456'),
                'nickname' => '演示用户',
            ])->id;
        }

        $now = now();

        $notifications = [
            ['type' => 'NOTIFY_COMMENT_REPLY', 'title' => '有人回复了你的评论', 'message' => '用户「烧箔爱好者」回复了你的评论：非常详细的介绍，学到了很多！', 'is_read' => 0, 'related_id' => 5,  'hours' => 1],
            ['type' => 'NOTIFY_LIKE',          'title' => '有人赞了你的帖子',   'message' => '用户「金属艺术家」赞了你的帖子《我的第一幅烧箔作品》', 'is_read' => 0, 'related_id' => 12, 'hours' => 3],
            ['type' => 'NOTIFY_SYSTEM',        'title' => '作品审核通过',       'message' => '你的作品已通过审核，现在可以在「传世名作」中查看', 'is_read' => 0, 'related_id' => null, 'hours' => 5],
            ['type' => 'NOTIFY_NEWS',          'title' => '烧箔技艺专题上新',   'message' => '《烧箔画的历史与流派》专题已上线，点击查看完整内容', 'is_read' => 0, 'related_id' => 3,  'hours' => 8],
            ['type' => 'NOTIFY_COMMENT_REPLY', 'title' => '有人回复了你的评论', 'message' => '用户「非遗传承人」回复了你的评论：这个火候控制技巧很实用，收藏了', 'is_read' => 1, 'related_id' => 5,  'hours' => 24],
            ['type' => 'NOTIFY_LIKE',          'title' => '有人赞了你的评论',   'message' => '用户「手艺人小王」赞了你的评论', 'is_read' => 0, 'related_id' => 8,  'hours' => 26],
            ['type' => 'NOTIFY_SYSTEM',        'title' => '欢迎加入灼箔凝艺',   'message' => '欢迎加入非遗烧箔传承社区，完善资料可获得新手礼包', 'is_read' => 1, 'related_id' => null, 'hours' => 48],
            ['type' => 'NOTIFY_NEWS',          'title' => '非遗活动预告',       'message' => '本周六线下烧箔体验活动开放报名，名额有限，速来参与', 'is_read' => 0, 'related_id' => 6,  'hours' => 50],
            ['type' => 'NOTIFY_LIKE',          'title' => '有人赞了你的作品',   'message' => '用户「金箔世家」赞了你的作品《荷塘月色》', 'is_read' => 1, 'related_id' => 20, 'hours' => 72],
            ['type' => 'NOTIFY_NEWS',          'title' => '烧箔大师课上线',     'message' => '特邀省级传承人录制的烧箔大师课已上线，登录即可观看', 'is_read' => 1, 'related_id' => 4,  'hours' => 96],
        ];

        foreach ($notifications as $n) {
            Notification::insert([
                'user_id'    => $userId,
                'type'       => $n['type'],
                'title'      => $n['title'],
                'message'    => $n['message'],
                'is_read'    => $n['is_read'],
                'related_id' => $n['related_id'],
                'is_deleted' => 0,
                'created_at' => $now->copy()->subHours($n['hours']),
                'updated_at' => $now,
            ]);
        }
    }
}
