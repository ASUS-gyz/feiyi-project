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

    /**
     * 校验前归一化入参：查询参数的布尔值以字符串传入（如 isRead=true），
     * 需先转成真正的布尔，否则 boolean 校验规则无法识别。
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('isRead')) {
            $this->merge([
                'isRead' => filter_var($this->input('isRead'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
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

        // 通过 method + path 推断 action（去掉可能的 api/ 前缀）
        $method = $this->method();
        $path   = ltrim((string) $this->path(), '/');
        $path   = str_starts_with($path, 'api/') ? substr($path, 4) : $path;

        return match (true) {
            $method === 'GET' && $path === 'shop/categories' => 'shop.categories',
            $method === 'GET' && preg_match('#^shop/products/\d+$#', $path) === 1 => 'shop.products.detail',
            $method === 'GET' && $path === 'shop/products' => 'shop.products',
            $method === 'POST' && $path === 'shop/orders' => 'shop.orders.create',
            $method === 'GET' && $path === 'shop/orders' => 'shop.orders.my',
            // notifications
            $method === 'GET' && $path === 'notifications' => 'notifications.list',
            $method === 'GET' && $path === 'notifications/unread-count' => 'notifications.unreadCount',
            $method === 'POST' && preg_match('#^notifications/\d+/read$#', $path) === 1 => 'notifications.read',
            $method === 'DELETE' && preg_match('#^notifications/\d+$#', $path) === 1 => 'notifications.delete',
            // chat
            $method === 'POST' && $path === 'chat/message' => 'chat.message',
            $method === 'GET' && $path === 'chat/test' => 'chat.test',
            $method === 'GET' && $path === 'chat/sessions' => 'chat.sessions',
            $method === 'GET' && preg_match('#^chat/sessions/[^/]+/messages$#', $path) === 1 => 'chat.messages',
            $method === 'DELETE' && preg_match('#^chat/sessions/[^/]+$#', $path) === 1 => 'chat.deleteSession',
            // games
            $method === 'GET' && $path === 'games' => 'games.list',
            $method === 'GET' && preg_match('#^games/(?!drawing/|coloring/|scores)[^/]+/levels/(\d+)/best$#', $path) === 1 => 'games.scores.best',
            $method === 'GET' && preg_match('#^games/(?!scores)[^/]+/levels$#', $path) === 1 => 'games.levels',
            $method === 'GET' && preg_match('#^games/drawing/levels/\d+/pattern$#', $path) === 1 => 'games.pattern',
            $method === 'GET' && preg_match('#^games/coloring/templates/\d+$#', $path) === 1 => 'games.template',
            $method === 'GET' && preg_match('#^games/[^/]+/leaderboard$#', $path) === 1 => 'games.leaderboard',
            $method === 'GET' && preg_match('#^games/[^/]+$#', $path) === 1 => 'games.detail',
            $method === 'POST' && $path === 'games/scores' => 'games.scores.submit',
            $method === 'GET' && $path === 'games/scores/my' => 'games.scores.my',
            $method === 'GET' && preg_match('#^games/scores/\d+/certificate$#', $path) === 1 => 'games.certificate',
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
