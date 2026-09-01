<?php

namespace Database\Seeders;

use App\Models\ShopCategory;
use App\Models\ShopProduct;
use Illuminate\Database\Seeder;

class ShopProductSeeder extends Seeder
{
    public function run(): void
    {
        $categories = ShopCategory::pluck('id', 'name');

        $products = [
            [
                'category' => '收藏级',
                'name' => '《千手观音》复刻版',
                'price' => 2999.00,
                'original_price' => 3999.00,
                'stock' => 50,
                'sales_count' => 23,
                'images' => [
                    '/images/products/qianshou-1.jpg',
                    '/images/products/qianshou-2.jpg',
                ],
                'specs' => [
                    ['label' => '尺寸', 'value' => '50 × 80cm'],
                    ['label' => '材质', 'value' => '99.9%纯金箔 + 宣纸'],
                    ['label' => '装裱', 'value' => '含酸枝木画框'],
                    ['label' => '限量', 'value' => '100件（有编号）'],
                ],
                'description' => '《千手观音》原作复刻版，限量编号发行100件。采用99.9%纯金箔，经数十道工序手工烧制完成。观音衣袂采用「渐变烧法」，每一件都有细微差异，独一无二。',
            ],
            [
                'category' => '收藏级',
                'name' => '《龙凤呈祥》金箔版',
                'price' => 3599.00,
                'original_price' => 4599.00,
                'stock' => 30,
                'sales_count' => 15,
                'images' => [
                    '/images/products/longfeng-1.jpg',
                ],
                'specs' => [
                    ['label' => '尺寸', 'value' => '60 × 90cm'],
                    ['label' => '材质', 'value' => '99.9%纯金箔 + 绢'],
                ],
                'description' => '龙凤呈祥主题烧箔画，寓意吉祥。采用多层叠烧工艺，龙凤纹样立体感强，适合馈赠或收藏。',
            ],
            [
                'category' => '实用级',
                'name' => '《荷花图》铜箔版',
                'price' => 599.00,
                'original_price' => 799.00,
                'stock' => 120,
                'sales_count' => 87,
                'images' => [
                    '/images/products/hehua-1.jpg',
                ],
                'specs' => [
                    ['label' => '尺寸', 'value' => '30 × 40cm'],
                    ['label' => '材质', 'value' => '铜箔 + 卡纸'],
                ],
                'description' => '荷花主题铜箔装饰画，清新雅致。适合书房、客厅墙面装饰，性价比高。',
            ],
            [
                'category' => '实用级',
                'name' => '烧箔纹样茶杯',
                'price' => 199.00,
                'original_price' => 259.00,
                'stock' => 200,
                'sales_count' => 156,
                'images' => [
                    '/images/products/chabei-1.jpg',
                ],
                'specs' => [
                    ['label' => '材质', 'value' => '陶瓷 + 铜箔'],
                    ['label' => '容量', 'value' => '300ml'],
                ],
                'description' => '烧箔纹样陶瓷茶杯，将传统烧箔工艺融入日常茶具。',
            ],
            [
                'category' => '入门级',
                'name' => 'DIY入门材料包',
                'price' => 199.00,
                'original_price' => 299.00,
                'stock' => 500,
                'sales_count' => 320,
                'images' => [
                    '/images/products/diy-1.jpg',
                ],
                'specs' => [
                    ['label' => '内容', 'value' => '铜箔 + 工具 + 教程 + 线稿'],
                    ['label' => '难度', 'value' => '入门'],
                ],
                'description' => '新手DIY材料包，含铜箔、黏结剂、电烙铁、教程和线稿，零基础也能做出第一幅烧箔画。',
            ],
            [
                'category' => '入门级',
                'name' => '线稿图册（10款）',
                'price' => 49.00,
                'original_price' => null,
                'stock' => 1000,
                'sales_count' => 640,
                'images' => [
                    '/images/products/xiangao-1.jpg',
                ],
                'specs' => [
                    ['label' => '数量', 'value' => '10款纹样'],
                    ['label' => '规格', 'value' => 'A4 线稿'],
                ],
                'description' => '精选10款经典烧箔纹样线稿，可直接打印描摹练习。',
            ],
        ];

        foreach ($products as $p) {
            ShopProduct::create([
                'category_id'    => $categories[$p['category']] ?? null,
                'name'           => $p['name'],
                'price'          => $p['price'],
                'original_price' => $p['original_price'],
                'stock'          => $p['stock'],
                'sales_count'    => $p['sales_count'],
                'images'         => $p['images'],
                'specs'          => $p['specs'],
                'description'    => $p['description'],
                'status'         => 'PRODUCT_ON',
            ]);
        }
    }
}
