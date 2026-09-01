<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\GameLevel;
use App\Models\GameTemplate;
use Illuminate\Database\Seeder;

class GameSeeder extends Seeder
{
    public function run(): void
    {
        // 模拟描稿
        $drawing = Game::create([
            'type'               => 'GAME_DRAWING',
            'title'              => '模拟描稿',
            'description'        => '用鼠标/触控笔勾勒经典烧箔纹样，超出线条触发「箔纸破损」动画提示，完成后根据准确度、速度和流畅度进行评分',
            'icon'               => 'https://cdn.example.com/icons/drawing.svg',
            'cover_image'        => 'https://cdn.example.com/games/drawing-cover.jpg',
            'default_difficulty' => 'DIFFICULTY_MEDIUM',
            'difficulty_options' => json_encode(['DIFFICULTY_EASY', 'DIFFICULTY_MEDIUM', 'DIFFICULTY_HARD']),
            'features'           => json_encode(['经典纹样库', '实时评分', '三种难度', '成就徽章']),
            'rules'              => json_encode([
                'objective'    => '跟随参考线描摹纹样，尽量保持笔触在线条范围内',
                'scoring'      => '准确度 60% + 完成速度 20% + 笔触流畅度 20%',
                'penalty'      => '超出参考线 3 次以上，每超出一次扣 5 分',
                'perfectBonus' => '无偏差完成额外 +10 分',
            ]),
        ]);

        GameLevel::insert([
            ['game_id' => $drawing->id, 'name' => '祥云纹', 'pattern_url' => 'https://cdn.example.com/patterns/xiangyun.svg', 'stroke_count' => 48, 'time_limit' => 120, 'thumbnail' => 'https://cdn.example.com/levels/drawing-1-thumb.svg', 'difficulty' => 'DIFFICULTY_EASY', 'description' => '简单的祥云纹样，适合初学者练习基本笔法', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['game_id' => $drawing->id, 'name' => '莲花纹', 'pattern_url' => 'https://cdn.example.com/patterns/lianhua.svg', 'stroke_count' => 72, 'time_limit' => 180, 'thumbnail' => 'https://cdn.example.com/levels/drawing-2-thumb.svg', 'difficulty' => 'DIFFICULTY_MEDIUM', 'description' => '中等难度的莲花纹样，需要较好的控笔能力', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['game_id' => $drawing->id, 'name' => '龙凤呈祥', 'pattern_url' => 'https://cdn.example.com/patterns/longfeng.svg', 'stroke_count' => 156, 'time_limit' => 300, 'thumbnail' => 'https://cdn.example.com/levels/drawing-3-thumb.svg', 'difficulty' => 'DIFFICULTY_HARD', 'description' => '高难度龙凤纹样，挑战你的极限控笔能力', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 火候控制
        Game::create([
            'type'               => 'GAME_FIRE',
            'title'              => '火候控制',
            'description'        => '模拟烧箔的火候控制过程，通过调节温度和速度来完成箔面着色',
            'icon'               => 'https://cdn.example.com/icons/fire.svg',
            'cover_image'        => 'https://cdn.example.com/games/fire-cover.jpg',
            'default_difficulty' => 'DIFFICULTY_MEDIUM',
            'difficulty_options' => json_encode(['DIFFICULTY_EASY', 'DIFFICULTY_MEDIUM', 'DIFFICULTY_HARD']),
            'features'           => json_encode(['真实温控模拟', '色泽渐变', '时间挑战']),
            'rules'              => json_encode([
                'objective'        => '调节温度滑杆控制箔面烧制温度，达到目标颜色',
                'scoring'          => '温度准确度 40% + 完成速度 30% + 颜色匹配度 30%',
                'tempTolerance'    => '温度偏差 ±5°C 以内不扣分',
                'colorMatchWeight' => '最终颜色越接近目标颜色分数越高',
            ]),
        ]);

        // 纹样填色
        $coloring = Game::create([
            'type'               => 'GAME_COLORING',
            'title'              => '纹样填色',
            'description'        => '从预设的箔色调色板中选择颜色为经典纹样线稿上色，支持金/银/铜三种箔色',
            'icon'               => 'https://cdn.example.com/icons/coloring.svg',
            'cover_image'        => 'https://cdn.example.com/games/coloring-cover.jpg',
            'default_difficulty' => 'DIFFICULTY_MEDIUM',
            'difficulty_options' => json_encode(['DIFFICULTY_EASY', 'DIFFICULTY_MEDIUM', 'DIFFICULTY_HARD']),
            'features'           => json_encode(['真实箔色', '一键导出', '社交分享', '无限制创作']),
            'rules'              => json_encode([
                'objective'     => '从预设调色板选择箔色，为线稿填色创作',
                'scoring'       => '色彩搭配 50% + 细节处理 30% + 完成速度 20%',
                'colorPalette'  => ['gold', 'silver', 'copper'],
                'exportFormats' => ['PNG', 'SVG'],
            ]),
        ]);

        GameTemplate::insert([
            ['game_id' => $coloring->id, 'name' => '祥云纹', 'skeleton_url' => 'https://cdn.example.com/templates/xiangyun-skeleton.png', 'foil_colors' => json_encode([['id'=>'gold','name'=>'金箔','hex'=>'#FFD700'],['id'=>'silver','name'=>'银箔','hex'=>'#C0C0C0'],['id'=>'copper','name'=>'铜箔','hex'=>'#B87333']]), 'difficulty' => 'DIFFICULTY_EASY', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['game_id' => $coloring->id, 'name' => '莲花纹', 'skeleton_url' => 'https://cdn.example.com/templates/lianhua-skeleton.png', 'foil_colors' => json_encode([['id'=>'gold','name'=>'金箔','hex'=>'#FFD700'],['id'=>'silver','name'=>'银箔','hex'=>'#C0C0C0'],['id'=>'copper','name'=>'铜箔','hex'=>'#B87333']]), 'difficulty' => 'DIFFICULTY_MEDIUM', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['game_id' => $coloring->id, 'name' => '龙凤呈祥', 'skeleton_url' => 'https://cdn.example.com/templates/longfeng-skeleton.png', 'foil_colors' => json_encode([['id'=>'gold','name'=>'金箔','hex'=>'#FFD700'],['id'=>'silver','name'=>'银箔','hex'=>'#C0C0C0'],['id'=>'copper','name'=>'铜箔','hex'=>'#B87333']]), 'difficulty' => 'DIFFICULTY_HARD', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
