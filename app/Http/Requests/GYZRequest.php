<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class GYZRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 公开接口无需鉴权，已登录接口由中间件处理
        return true;
    }

    public function rules(): array
    {
        $action = $this->getRouteAction();

        return match ($action) {
            // === 文创商城 ===
            'shop.products' => [
                'page'      => 'integer|min:1',
                'pageSize'  => 'integer|min:1|max:100',
                'categoryId'=> 'integer|exists:shop_categories,id',
                'keyword'   => 'string|max:200',
                'minPrice'  => 'numeric|min:0',
                'maxPrice'  => 'numeric|min:0',
                'sortBy'    => 'string|in:created_at,price,sales_count',
                'order'     => 'string|in:asc,desc',
            ],
            'shop.products.detail' => [
                'id' => 'required|integer|min:1',
            ],
            'shop.orders.create' => [
                'productId'   => 'required|integer|exists:shop_products,id',
                'quantity'    => 'required|integer|min:1',
                'address'     => 'required|string|max:300',
                'contactName' => 'required|string|max:50',
                'contactPhone'=> 'required|string|max:20',
                'remark'      => 'nullable|string|max:500',
            ],
            'shop.orders.my' => [
                'page'     => 'integer|min:1',
                'pageSize' => 'integer|min:1|max:100',
                'status'   => 'string|in:ORDER_PENDING,ORDER_PAID,ORDER_SHIPPED,ORDER_COMPLETED,ORDER_CANCELLED',
            ],

            // === 消息通知 ===
            'notifications.list' => [
                'page'     => 'integer|min:1',
                'pageSize' => 'integer|min:1|max:100',
                'isRead'   => 'boolean',
                'type'     => 'string|in:NOTIFY_COMMENT_REPLY,NOTIFY_LIKE,NOTIFY_SYSTEM,NOTIFY_NEWS',
            ],
            'notifications.unreadCount' => [
                'type' => 'string|in:NOTIFY_COMMENT_REPLY,NOTIFY_LIKE,NOTIFY_SYSTEM,NOTIFY_NEWS',
            ],
            'notifications.read' => [
                'id' => 'required|integer|min:1',
            ],
            'notifications.delete' => [
                'id' => 'required|integer|min:1',
            ],

            // === 线上轻互动 ===
            'games.list' => [
                'type' => 'string|in:GAME_DRAWING,GAME_FIRE,GAME_COLORING',
            ],
            'games.detail' => [
                'type' => 'required|string|in:GAME_DRAWING,GAME_FIRE,GAME_COLORING',
            ],
            'games.levels' => [
                'type' => 'required|string|in:GAME_DRAWING,GAME_FIRE,GAME_COLORING',
            ],
            'games.pattern' => [
                'id' => 'required|integer|min:1',
            ],
            'games.template' => [
                'id' => 'required|integer|min:1',
            ],
            'games.scores.submit' => [
                'gameType'  => 'required|string|in:GAME_DRAWING,GAME_FIRE,GAME_COLORING',
                'levelId'   => 'required|integer|min:1',
                'score'     => 'required|numeric|min:0|max:100',
                'duration'  => 'required|integer|min:0',
                'difficulty'=> 'string|in:DIFFICULTY_EASY,DIFFICULTY_MEDIUM,DIFFICULTY_HARD',
                'metadata'  => 'nullable|array',
            ],
            'games.scores.my' => [
                'page'     => 'integer|min:1',
                'pageSize' => 'integer|min:1|max:100',
                'gameType' => 'string|in:GAME_DRAWING,GAME_FIRE,GAME_COLORING',
                'levelId'  => 'integer|min:1',
            ],
            'games.leaderboard' => [
                'type'       => 'required|string|in:GAME_DRAWING,GAME_FIRE,GAME_COLORING',
                'levelId'    => 'integer|min:1',
                'difficulty' => 'string|in:DIFFICULTY_EASY,DIFFICULTY_MEDIUM,DIFFICULTY_HARD',
                'period'     => 'string|in:all,month,week',
                'page'       => 'integer|min:1',
                'pageSize'   => 'integer|min:1|max:100',
            ],
            'games.scores.best' => [
                'type'    => 'required|string|in:GAME_DRAWING,GAME_FIRE,GAME_COLORING',
                'id'      => 'required|integer|min:1',
            ],
            'games.certificate' => [
                'id' => 'required|integer|min:1',
            ],

            // === AI 智能问答 ===
            'chat.message' => [
                'message'     => 'required|string|max:1000',
                'sessionId'   => 'nullable|string|max:50',
                'maxTokens'   => 'integer|min:128|max:2048',
                'temperature' => 'numeric|min:0.1|max:1.5',
            ],
            'chat.test' => [
                'message' => 'required|string',
            ],
            'chat.sessions' => [
                'page'     => 'integer|min:1',
                'pageSize' => 'integer|min:1|max:100',
            ],
            'chat.messages' => [
                'id' => 'required|string|max:50',
            ],
            'chat.deleteSession' => [
                'id' => 'required|string|max:50',
            ],

            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'productId.required'     => '商品ID不能为空',
            'productId.exists'       => '商品不存在',
            'quantity.required'      => '购买数量不能为空',
            'quantity.min'           => '购买数量至少为1',
            'address.required'       => '收货地址不能为空',
            'contactName.required'   => '收货人姓名不能为空',
            'contactPhone.required'  => '收货人电话不能为空',
            'gameType.required'      => '游戏类型不能为空',
            'gameType.in'            => '游戏类型不合法',
            'levelId.required'       => '关卡ID不能为空',
            'score.required'         => '得分不能为空',
            'score.max'              => '得分不能超过100',
            'duration.required'      => '完成时长不能为空',
            'type.required'          => '类型参数不能为空',
        ];
    }

    private function getRouteAction(): ?string
    {
        $name = $this->route()?->getName();
        if ($name) {
            return $name;
        }

        // 通过 method + path 推断 action
        $method = $this->method();
        $path   = $this->path();

        return match (true) {
            $method === 'GET' && $path === 'api/shop/categories' => 'shop.categories',
            $method === 'GET' && preg_match('#^api/shop/products/\d+$#', $path) === 1 => 'shop.products.detail',
            $method === 'GET' && $path === 'api/shop/products' => 'shop.products',
            $method === 'POST' && $path === 'api/shop/orders' => 'shop.orders.create',
            $method === 'GET' && $path === 'api/shop/orders' => 'shop.orders.my',
            // notifications
            $method === 'GET' && $path === 'api/notifications' => 'notifications.list',
            $method === 'GET' && $path === 'api/notifications/unread-count' => 'notifications.unreadCount',
            $method === 'POST' && preg_match('#^api/notifications/\d+/read$#', $path) === 1 => 'notifications.read',
            $method === 'DELETE' && preg_match('#^api/notifications/\d+$#', $path) === 1 => 'notifications.delete',
            // chat
            $method === 'POST' && $path === 'api/chat/message' => 'chat.message',
            $method === 'GET' && $path === 'api/chat/test' => 'chat.test',
            $method === 'GET' && $path === 'api/chat/sessions' => 'chat.sessions',
            $method === 'GET' && preg_match('#^api/chat/sessions/[^/]+/messages$#', $path) === 1 => 'chat.messages',
            $method === 'DELETE' && preg_match('#^api/chat/sessions/[^/]+$#', $path) === 1 => 'chat.deleteSession',
            // games
            $method === 'GET' && $path === 'api/games' => 'games.list',
            $method === 'GET' && preg_match('#^api/games/(?!drawing/|coloring/|scores)[^/]+/levels/(\d+)/best$#', $path) === 1 => 'games.scores.best',
            $method === 'GET' && preg_match('#^api/games/(?!scores)[^/]+/levels$#', $path) === 1 => 'games.levels',
            $method === 'GET' && preg_match('#^api/games/drawing/levels/\d+/pattern$#', $path) === 1 => 'games.pattern',
            $method === 'GET' && preg_match('#^api/games/coloring/templates/\d+$#', $path) === 1 => 'games.template',
            $method === 'GET' && preg_match('#^api/games/[^/]+/leaderboard$#', $path) === 1 => 'games.leaderboard',
            $method === 'GET' && preg_match('#^api/games/[^/]+$#', $path) === 1 => 'games.detail',
            $method === 'POST' && $path === 'api/games/scores' => 'games.scores.submit',
            $method === 'GET' && $path === 'api/games/scores/my' => 'games.scores.my',
            $method === 'GET' && preg_match('#^api/games/scores/\d+/certificate$#', $path) === 1 => 'games.certificate',
            default => null,
        };
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        // 将 camelCase 转为 snake_case 便于 Service 使用
        if (is_array($validated)) {
            $mapped = [];
            foreach ($validated as $k => $v) {
                $mapped[Str::snake($k)] = $v;
            }
            return $mapped;
        }

        return $validated;
    }
}
