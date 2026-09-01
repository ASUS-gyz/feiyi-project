<?php

namespace Database\Seeders;

use App\Models\ShopCategory;
use Illuminate\Database\Seeder;

class ShopCategorySeeder extends Seeder
{
    public function run(): void
    {
        ShopCategory::insert([
            ['name' => '收藏级', 'description' => '原作复刻（限量编号）', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => '实用级', 'description' => '箔画装饰茶杯、笔记本、屏风', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => '入门级', 'description' => 'DIY材料包（含工具+教程+线稿）', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
