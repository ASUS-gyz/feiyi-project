<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Enums\ResponseCode;
use App\Models\Game;
use App\Models\GameLevel;
use App\Models\GameScore;
use App\Models\GameTemplate;
use App\Models\Notification;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\ShopCategory;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GYZService
{
    // ==================================================================
    //  文创商城
    // ==================================================================

    /**
     * 获取商品分类列表
     */
    public function getCategories(): array
    {
        return ShopCategory::active()
            ->orderBy('sort_order')
            ->select(['id', 'name', 'description'])
            ->get()
            ->toArray();
    }

    /**
     * 商品列表（分页+筛选+排序）
     */
    public function getProducts(array $params): array
    {
        $page       = (int) ($params['page'] ?? 1);
        $page_size  = min((int) ($params['page_size'] ?? 20), 100);
        $category_id= $params['category_id'] ?? null;
        $keyword    = $params['keyword'] ?? null;
        $min_price  = $params['min_price'] ?? null;
        $max_price  = $params['max_price'] ?? null;
        $sort_by    = $params['sort_by'] ?? 'created_at';
        $order      = $params['order'] ?? 'desc';

        $query = ShopProduct::active()
            ->onSale()
            ->with('category:id,name');

        if ($category_id) {
            $query->where('category_id', $category_id);
        }
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('description', 'like', "%{$keyword}%");
            });
        }
        if ($min_price !== null) {
            $query->where('price', '>=', $min_price);
        }
        if ($max_price !== null) {
            $query->where('price', '<=', $max_price);
        }

        $allowedSorts = ['created_at', 'price', 'sales_count'];
        $sort_by = in_array($sort_by, $allowedSorts) ? $sort_by : 'created_at';
        $order = strtolower($order) === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy($sort_by, $order)->paginate($page_size, [
            'id', 'category_id', 'name', 'price', 'original_price',
            'stock', 'sales_count', 'images', 'specs', 'created_at',
        ], 'page', $page);

        $list = collect($paginator->items())->map(function ($item) {
            return [
                'id'            => $item->id,
                'name'          => $item->name,
                'category'      => $item->category?->name,
                'categoryId'    => $item->category_id,
                'price'         => (float) $item->price,
                'originalPrice' => $item->original_price ? (float) $item->original_price : null,
                'stock'         => $item->stock,
                'salesCount'    => $item->sales_count,
                'images'        => $item->images,
                'specs'         => $item->specs,
            ];
        })->toArray();

        return [
            'list'     => $list,
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    /**
     * 商品详情
     */
    public function getProductDetail(int $id): array
    {
        $product = ShopProduct::active()->onSale()->find($id);

        if (! $product) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '商品不存在');
        }

        return [
            'id'            => $product->id,
            'name'          => $product->name,
            'category'      => $product->category?->name,
            'categoryId'    => $product->category_id,
            'price'         => (float) $product->price,
            'originalPrice' => $product->original_price ? (float) $product->original_price : null,
            'stock'         => $product->stock,
            'images'        => $product->images,
            'description'   => $product->description,
            'specs'         => $product->specs,
        ];
    }

    /**
     * 下单（事务：扣库存 + 创建订单 + 更新销量）
     */
    public function createOrder(int $userId, array $data): array
    {
        return DB::transaction(function () use ($userId, $data) {
            $product = ShopProduct::where('id', $data['product_id'])
                ->lockForUpdate()
                ->first();

            if (! $product || $product->is_deleted) {
                throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '商品不存在');
            }
            if ($product->status !== 'PRODUCT_ON') {
                throw new BusinessException(ResponseCode::BUSINESS_ERROR, '商品已下架');
            }
            if ($product->stock < $data['quantity']) {
                throw new BusinessException(ResponseCode::BUSINESS_ERROR, '库存不足');
            }

            $orderNo = ShopOrder::generateOrderNo();
            $totalAmount = bcmul((string) $product->price, (string) $data['quantity'], 2);

            $order = ShopOrder::create([
                'order_no'      => $orderNo,
                'user_id'       => $userId,
                'product_id'    => $product->id,
                'product_name'  => $product->name,
                'product_image'  => is_array($product->images) ? ($product->images[0] ?? null) : null,
                'quantity'      => $data['quantity'],
                'price'         => $product->price,
                'total_amount'  => $totalAmount,
                'status'        => 'ORDER_PENDING',
                'address'       => $data['address'],
                'contact_name'  => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
                'remark'        => $data['remark'] ?? null,
            ]);

            // 扣库存 + 增销量
            $product->decrement('stock', $data['quantity']);
            $product->increment('sales_count', $data['quantity']);

            Log::channel('business')->info('订单创建成功', [
                'order_no' => $orderNo,
                'user_id'  => $userId,
                'amount'   => $totalAmount,
            ]);

            return [
                'orderNo'      => $order->order_no,
                'productId'    => $order->product_id,
                'productName'  => $order->product_name,
                'productImage' => $order->product_image,
                'quantity'     => $order->quantity,
                'price'        => (float) $order->price,
                'totalAmount'  => (float) $order->total_amount,
                'status'       => $order->status,
                'createdAt'    => $order->created_at?->toIso8601String(),
            ];
        });
    }

    /**
     * 我的订单列表
     */
    public function getOrders(int $userId, array $params): array
    {
        $page      = (int) ($params['page'] ?? 1);
        $page_size = min((int) ($params['page_size'] ?? 20), 100);
        $status    = $params['status'] ?? null;

        $query = ShopOrder::where('user_id', $userId);

        if ($status) {
            $query->where('status', $status);
        }

        $paginator = $query->orderBy('created_at', 'desc')
            ->paginate($page_size, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(function ($order) {
            return [
                'orderNo'      => $order->order_no,
                'productId'    => $order->product_id,
                'productName'  => $order->product_name,
                'productImage' => $order->product_image,
                'quantity'     => $order->quantity,
                'price'        => (float) $order->price,
                'totalAmount'  => (float) $order->total_amount,
                'status'       => $order->status,
                'address'      => $order->address,
                'contactName'  => $order->contact_name,
                'contactPhone' => $order->contact_phone,
                'remark'       => $order->remark,
                'paidAt'       => $order->paid_at?->toIso8601String(),
                'shippedAt'    => $order->shipped_at?->toIso8601String(),
                'completedAt'  => $order->completed_at?->toIso8601String(),
                'cancelledAt'  => $order->cancelled_at?->toIso8601String(),
                'createdAt'    => $order->created_at?->toIso8601String(),
            ];
        })->toArray();

        return [
            'list'     => $list,
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    // ==================================================================
    //  消息通知
    // ==================================================================

    /**
     * 通知列表
     */
    public function getNotifications(int $userId, array $params): array
    {
        $page      = (int) ($params['page'] ?? 1);
        $page_size = min((int) ($params['page_size'] ?? 20), 100);
        $is_read   = $params['is_read'] ?? null;
        $type      = $params['type'] ?? null;

        $query = Notification::active()->where('user_id', $userId);

        if ($is_read !== null) {
            $query->where('is_read', filter_var($is_read, FILTER_VALIDATE_BOOLEAN));
        }
        if ($type) {
            $query->where('type', $type);
        }

        $paginator = $query->orderBy('created_at', 'desc')
            ->paginate($page_size, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(function ($n) {
            return [
                'id'        => $n->id,
                'type'      => $n->type,
                'title'     => $n->title,
                'message'   => $n->message,
                'isRead'    => $n->is_read,
                'relatedId' => $n->related_id,
                'createdAt' => $n->created_at?->toIso8601String(),
            ];
        })->toArray();

        return [
            'list'     => $list,
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    /**
     * 未读消息数量
     */
    public function getUnreadCount(int $userId, ?string $type = null): array
    {
        $total = Notification::active()
            ->where('user_id', $userId)
            ->unread()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->count();

        $byType = Notification::active()
            ->where('user_id', $userId)
            ->unread()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        // 未传 type 时补齐所有类型（含 0）；传了 type 则只返回该类型
        if (! $type) {
            $allTypes = ['NOTIFY_COMMENT_REPLY', 'NOTIFY_LIKE', 'NOTIFY_SYSTEM', 'NOTIFY_NEWS'];
            foreach ($allTypes as $t) {
                $byType[$t] = $byType[$t] ?? 0;
            }
        }

        return [
            'total'  => $total,
            'byType' => $byType,
        ];
    }

    /**
     * 标记单条已读
     */
    public function markAsRead(int $userId, int $id): void
    {
        $notification = Notification::active()
            ->where('user_id', $userId)
            ->find($id);

        if (! $notification) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '消息不存在');
        }

        $notification->update(['is_read' => true]);
    }

    /**
     * 全部标记已读
     */
    public function markAllAsRead(int $userId): int
    {
        return Notification::active()
            ->where('user_id', $userId)
            ->unread()
            ->update(['is_read' => true]);
    }

    /**
     * 删除单条通知
     */
    public function deleteNotification(int $userId, int $id): void
    {
        $notification = Notification::active()
            ->where('user_id', $userId)
            ->find($id);

        if (! $notification) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '消息不存在');
        }

        $notification->update([
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        Log::channel('business')->info('用户删除通知', [
            'user_id'         => $userId,
            'notification_id' => $id,
        ]);
    }

    /**
     * 清空已读消息
     */
    public function clearReadNotifications(int $userId): int
    {
        $count = Notification::active()
            ->where('user_id', $userId)
            ->where('is_read', true)
            ->count();

        Notification::active()
            ->where('user_id', $userId)
            ->where('is_read', true)
            ->update([
                'is_deleted' => true,
                'deleted_at' => now(),
            ]);

        Log::channel('business')->info('用户清空已读通知', [
            'user_id' => $userId,
            'count'   => $count,
        ]);

        return $count;
    }

    // ==================================================================
    //  线上轻互动（小游戏）
    // ==================================================================

    /**
     * 游戏列表
     */
    public function getGames(?string $type = null): array
    {
        $query = Game::active()->enabled();

        if ($type) {
            $query->where('type', $type);
        }

        return $query->select([
            'id', 'type', 'title', 'description', 'icon', 'cover_image',
            'default_difficulty', 'difficulty_options', 'features', 'is_enabled',
        ])->get()->map(function ($game) {
            return [
                'id'                => $game->id,
                'type'              => $game->type,
                'title'             => $game->title,
                'description'       => $game->description,
                'icon'              => $game->icon,
                'coverImage'        => $game->cover_image,
                'defaultDifficulty' => $game->default_difficulty,
                'difficultyOptions' => $game->difficulty_options,
                'features'          => $game->features,
                'isEnabled'         => $game->is_enabled,
            ];
        })->toArray();
    }

    /**
     * 游戏详情（含关卡/模板）
     */
    public function getGameDetail(string $type): array
    {
        $game = Game::active()->enabled()->where('type', $type)->first();

        if (! $game) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '游戏不存在');
        }

        $data = [
            'id'                => $game->id,
            'type'              => $game->type,
            'title'             => $game->title,
            'description'       => $game->description,
            'icon'              => $game->icon,
            'coverImage'        => $game->cover_image,
            'defaultDifficulty' => $game->default_difficulty,
            'difficultyOptions' => $game->difficulty_options,
            'rules'             => $game->rules,
            'features'          => $game->features,
            'isEnabled'         => $game->is_enabled,
        ];

        if ($type === 'GAME_COLORING') {
            $data['templates'] = GameTemplate::active()
                ->where('game_id', $game->id)
                ->orderBy('sort_order')
                ->select(['id', 'name', 'skeleton_url', 'foil_colors', 'difficulty'])
                ->get()
                ->map(function ($t) {
                    return [
                        'id'          => $t->id,
                        'name'        => $t->name,
                        'skeletonUrl'  => $t->skeleton_url,
                        'foilColors'  => $t->foil_colors,
                        'difficulty'  => $t->difficulty,
                    ];
                })->toArray();
        } else {
            $data['levels'] = GameLevel::active()
                ->where('game_id', $game->id)
                ->orderBy('sort_order')
                ->select(['id', 'name', 'pattern_url', 'stroke_count', 'time_limit', 'thumbnail', 'difficulty', 'description'])
                ->get()
                ->map(function ($l) {
                    return [
                        'id'          => $l->id,
                        'name'        => $l->name,
                        'patternUrl'  => $l->pattern_url,
                        'strokeCount' => $l->stroke_count,
                        'timeLimit'   => $l->time_limit,
                        'thumbnail'   => $l->thumbnail,
                        'difficulty'  => $l->difficulty,
                        'description' => $l->description,
                    ];
                })->toArray();
        }

        return $data;
    }

    /**
     * 关卡列表
     */
    public function getLevels(string $type): array
    {
        $game = Game::active()->enabled()->where('type', $type)->first();
        if (! $game) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '游戏不存在');
        }

        return GameLevel::active()
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->select(['id', 'name', 'pattern_url', 'stroke_count', 'time_limit', 'thumbnail', 'difficulty'])
            ->get()
            ->map(function ($l) {
                return [
                    'id'          => $l->id,
                    'name'        => $l->name,
                    'patternUrl'  => $l->pattern_url,
                    'strokeCount' => $l->stroke_count,
                    'timeLimit'   => $l->time_limit,
                    'thumbnail'   => $l->thumbnail,
                    'difficulty'  => $l->difficulty,
                ];
            })
            ->toArray();
    }

    /**
     * 获取描稿线稿 SVG
     */
    public function getPattern(int $id): array
    {
        $level = GameLevel::active()->find($id);
        if (! $level) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '关卡不存在');
        }

        $svg = '';
        $patternUrl = $level->pattern_url;
        if ($patternUrl) {
            // 本地路径（如 /images/patterns/xxx.svg）转成 public 目录下的实际文件读取内容
            $path = public_path(ltrim($patternUrl, '/'));
            if (is_file($path)) {
                $svg = (string) file_get_contents($path);
            }
        }

        return [
            'id'          => $level->id,
            'name'        => $level->name,
            'svg'         => $svg,
            'strokeWidth'  => 2,
            'strokeColor' => '#333333',
        ];
    }

    /**
     * 获取填色模板详情
     */
    public function getTemplate(int $id): array
    {
        $template = GameTemplate::active()->find($id);
        if (! $template) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '模板不存在');
        }

        return [
            'id'         => $template->id,
            'name'       => $template->name,
            'skeletonUrl' => $template->skeleton_url,
            'foilColors' => $template->foil_colors,
        ];
    }

    /**
     * 提交游戏成绩
     */
    public function submitScore(int $userId, array $data): array
    {
        $game = Game::active()->enabled()->where('type', $data['game_type'])->first();
        if (! $game) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '游戏不存在');
        }

        $levelName = null;
        if ($data['game_type'] === 'GAME_COLORING') {
            $template = GameTemplate::active()->find($data['level_id']);
            $levelName = $template?->name;
        } else {
            $level = GameLevel::active()->find($data['level_id']);
            $levelName = $level?->name;
        }

        // 判断是否刷新个人最佳
        $previousBest = GameScore::active()
            ->where('user_id', $userId)
            ->where('game_type', $data['game_type'])
            ->where('level_id', $data['level_id'])
            ->max('score');

        $isNewRecord = $previousBest === null || $data['score'] > $previousBest;

        $score = GameScore::create([
            'user_id'         => $userId,
            'game_type'       => $data['game_type'],
            'level_id'        => $data['level_id'],
            'level_name'      => $levelName,
            'score'           => $data['score'],
            'duration'        => $data['duration'],
            'difficulty'      => $data['difficulty'] ?? null,
            'metadata'        => $data['metadata'] ?? null,
            'certificate_url' => null,
        ]);

        // 计算排名
        $rank = GameScore::active()
            ->where('game_type', $data['game_type'])
            ->where('level_id', $data['level_id'])
            ->where('score', '>', $data['score'])
            ->distinct('user_id')
            ->count('user_id') + 1;

        $totalPlayers = GameScore::active()
            ->where('game_type', $data['game_type'])
            ->where('level_id', $data['level_id'])
            ->distinct('user_id')
            ->count('user_id');

        Log::channel('business')->info('游戏成绩提交', [
            'user_id'  => $userId,
            'game_type'=> $data['game_type'],
            'level_id' => $data['level_id'],
            'score'    => $data['score'],
        ]);

        return [
            'scoreId'        => $score->id,
            'gameType'       => $data['game_type'],
            'levelId'        => $data['level_id'],
            'levelName'      => $levelName,
            'score'          => $data['score'],
            'duration'       => $data['duration'],
            'difficulty'     => $data['difficulty'] ?? null,
            'rank'           => $rank,
            'totalPlayers'   => $totalPlayers,
            'isNewRecord'    => $isNewRecord,
            'certificateUrl' => null,
            'createdAt'      => $score->created_at?->toIso8601String(),
        ];
    }

    /**
     * 我的游戏记录
     */
    public function getMyScores(int $userId, array $params): array
    {
        $page      = (int) ($params['page'] ?? 1);
        $page_size = min((int) ($params['page_size'] ?? 20), 100);
        $game_type = $params['game_type'] ?? null;
        $level_id  = $params['level_id'] ?? null;

        $query = GameScore::active()->where('user_id', $userId);

        if ($game_type) {
            $query->where('game_type', $game_type);
        }
        if ($level_id) {
            $query->where('level_id', $level_id);
        }

        $paginator = $query->orderBy('created_at', 'desc')
            ->paginate($page_size, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(function ($s) use ($userId) {
            // 判断是否个人最佳
            $bestScore = GameScore::active()
                ->where('user_id', $userId)
                ->where('game_type', $s->game_type)
                ->where('level_id', $s->level_id)
                ->max('score');

            // 排名
            $rank = GameScore::active()
                ->where('game_type', $s->game_type)
                ->where('level_id', $s->level_id)
                ->where('score', '>', $s->score)
                ->distinct('user_id')
                ->count('user_id') + 1;

            return [
                'scoreId'        => $s->id,
                'gameType'       => $s->game_type,
                'levelId'        => $s->level_id,
                'levelName'      => $s->level_name,
                'score'          => $s->score,
                'duration'       => $s->duration,
                'difficulty'     => $s->difficulty,
                'rank'           => $rank,
                'certificateUrl' => $s->certificate_url,
                'isBestScore'    => $s->score >= $bestScore,
                'createdAt'      => $s->created_at?->toIso8601String(),
            ];
        })->toArray();

        return [
            'list'     => $list,
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    /**
     * 排行榜
     */
    public function getLeaderboard(string $type, array $params): array
    {
        $page       = (int) ($params['page'] ?? 1);
        $page_size  = min((int) ($params['page_size'] ?? 20), 100);
        $level_id   = $params['level_id'] ?? null;
        $difficulty = $params['difficulty'] ?? null;
        $period     = $params['period'] ?? 'all';

        $subQuery = GameScore::active()
            ->selectRaw('MAX(score) as best_score, user_id')
            ->where('game_type', $type);

        if ($level_id) {
            $subQuery->where('level_id', $level_id);
        }
        if ($difficulty) {
            $subQuery->where('difficulty', $difficulty);
        }

        if ($period === 'month') {
            $subQuery->where('created_at', '>=', now()->subMonth());
        } elseif ($period === 'week') {
            $subQuery->where('created_at', '>=', now()->subWeek());
        }

        $subQuery->groupBy('user_id');

        // 子查询排名
        $ranked = DB::query()
            ->fromSub($subQuery, 't')
            ->join('users', 'users.id', '=', 't.user_id')
            ->orderByDesc('t.best_score')
            ->select('t.user_id', 'users.nickname', 'users.avatar', 't.best_score');

        $total = $ranked->count();

        $list = $ranked->forPage($page, $page_size)->get()
            ->values()
            ->map(function ($item, $index) use ($page, $page_size) {
                return [
                    'rank'     => ($page - 1) * $page_size + $index + 1,
                    'userId'   => $item->user_id,
                    'nickname' => $item->nickname,
                    'avatar'   => $item->avatar,
                    'score'    => $item->best_score,
                ];
            })
            ->toArray();

        return [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $page_size,
        ];
    }

    /**
     * 关卡个人最佳成绩
     */
    public function getBestScore(int $userId, string $type, int $levelId): array
    {
        $bestScore = GameScore::active()
            ->where('user_id', $userId)
            ->where('game_type', $type)
            ->where('level_id', $levelId)
            ->orderByDesc('score')
            ->first();

        if (! $bestScore) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '暂无成绩记录');
        }

        $rank = GameScore::active()
            ->where('game_type', $type)
            ->where('level_id', $levelId)
            ->where('score', '>', $bestScore->score)
            ->distinct('user_id')
            ->count('user_id') + 1;

        $totalPlayers = GameScore::active()
            ->where('game_type', $type)
            ->where('level_id', $levelId)
            ->distinct('user_id')
            ->count('user_id');

        return [
            'scoreId'      => $bestScore->id,
            'gameType'     => $type,
            'levelId'      => $levelId,
            'levelName'    => $bestScore->level_name,
            'score'        => $bestScore->score,
            'duration'     => $bestScore->duration,
            'difficulty'   => $bestScore->difficulty,
            'rank'         => $rank,
            'totalPlayers' => $totalPlayers,
            'createdAt'    => $bestScore->created_at?->toIso8601String(),
        ];
    }

    /**
     * 生成电子证书（PNG）
     *
     * @return string PNG 文件二进制内容
     */
    public function getCertificate(int $userId, int $scoreId): string
    {
        $score = GameScore::active()
            ->where('user_id', $userId)
            ->with('user')
            ->find($scoreId);

        if (! $score) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '成绩记录不存在');
        }

        $gameTypeMap = [
            'GAME_DRAWING'  => '模拟描稿',
            'GAME_FIRE'     => '火候控制',
            'GAME_COLORING' => '纹样填色',
        ];
        $difficultyMap = [
            'DIFFICULTY_EASY'   => '简单',
            'DIFFICULTY_MEDIUM' => '中等',
            'DIFFICULTY_HARD'   => '困难',
        ];

        $png = $this->drawCertificate([
            'userName'   => $score->user?->nickname ?: ($score->user?->name ?: '玩家'),
            'gameType'   => $gameTypeMap[$score->game_type] ?? $score->game_type,
            'levelName'  => $score->level_name,
            'score'      => (string) $score->score,
            'duration'   => (string) $score->duration,
            'difficulty' => $difficultyMap[$score->difficulty] ?? ($score->difficulty ?: '—'),
            'date'       => $score->created_at?->format('Y年m月d日') ?? '',
        ]);

        // 保存到 public 磁盘并回填证书地址
        $filename = "certificates/{$scoreId}.png";
        Storage::disk('public')->put($filename, $png);
        $score->update([
            'certificate_url' => Storage::disk('public')->url($filename),
        ]);

        return $png;
    }

    /**
     * 用 GD 绘制电子证书。
     * 中文走 FreeType 渲染，避免 DomPDF 对部分中文字体 cmap 的兼容问题。
     */
    private function drawCertificate(array $d): string
    {
        $w = 1600;
        $h = 1000;
        $img = imagecreatetruecolor($w, $h);

        $bg    = imagecolorallocate($img, 253, 248, 238);
        $gold  = imagecolorallocate($img, 184, 134, 11);
        $brown = imagecolorallocate($img, 160, 82, 45);
        $body  = imagecolorallocate($img, 90, 70, 48);
        $muted = imagecolorallocate($img, 138, 122, 96);
        $red   = imagecolorallocate($img, 192, 57, 43);
        $cream = imagecolorallocate($img, 255, 250, 240);

        $font = storage_path('fonts/simhei.ttf');

        imagefilledrectangle($img, 0, 0, $w, $h, $bg);

        // 双边框
        imagerectangle($img, 50, 50, $w - 50, $h - 50, $gold);
        imagesetthickness($img, 3);
        imagerectangle($img, 66, 66, $w - 66, $h - 66, $gold);
        imagesetthickness($img, 1);

        // 标题 / 副标题
        $this->drawCentered($img, 72, 195, $brown, $font, '电子证书');
        $this->drawCentered($img, 26, 265, $gold, $font, '非遗烧箔 · 线上轻互动');

        // 姓名
        $this->drawCentered($img, 34, 380, $body, $font, '兹证明 ' . $d['userName']);

        // 信息
        $info = [
            '参与项目：' . $d['gameType'],
            '挑战关卡：' . $d['levelName'],
            '获得成绩：' . $d['score'] . ' 分',
            '完成用时：' . $d['duration'] . ' 秒',
            '挑战难度：' . $d['difficulty'],
        ];
        $y = 480;
        foreach ($info as $line) {
            $this->drawCentered($img, 28, $y, $body, $font, $line);
            $y += 58;
        }

        // 日期
        $this->drawCentered($img, 22, 820, $muted, $font, '颁发日期：' . $d['date']);

        // 印章
        $cx = 1290;
        $cy = 740;
        $r  = 90;
        imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $red);
        $this->drawCentered($img, 30, $cy - 12, $cream, $font, '灼箔');
        $this->drawCentered($img, 30, $cy + 30, $cream, $font, '凝艺');

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /**
     * 水平居中绘制文字
     */
    private function drawCentered($img, int $size, int $y, int $color, string $font, string $text): void
    {
        $bbox  = imagettfbbox($size, 0, $font, $text);
        $width = $bbox[2] - $bbox[0];
        $x     = (int) round((imagesx($img) - $width) / 2);
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    }

    // ==================================================================
    //  AI 智能问答
    // ==================================================================

    /**
     * 发送 AI 聊天消息
     */
    public function sendChatMessage(array $data, ?int $userId = null): array
    {
        $message   = $data['message'];
        $sessionId = $data['session_id'] ?? '';
        $maxTokens = (int) ($data['max_tokens'] ?? 512);
        $temperature = (float) ($data['temperature'] ?? 0.6);

        // 获取或创建会话
        if (empty($sessionId)) {
            $sessionId = ChatSession::generateSessionId();
            ChatSession::create([
                'session_id'   => $sessionId,
                'user_id'      => $userId,
                'title'        => Str::limit($message, 50),
                'last_message' => Str::limit($message, 100),
            ]);
        } else {
            $session = ChatSession::active()->where('session_id', $sessionId)->first();
            if (! $session) {
                $sessionId = ChatSession::generateSessionId();
                ChatSession::create([
                    'session_id'   => $sessionId,
                    'user_id'      => $userId,
                    'title'        => Str::limit($message, 50),
                    'last_message' => Str::limit($message, 100),
                ]);
            } else {
                $session->update(['last_message' => Str::limit($message, 100), 'updated_at' => now()]);
            }
        }

        // 获取历史消息
        $history = ChatMessage::active()
            ->where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get(['role', 'content'])
            ->toArray();

        // 保存用户消息
        ChatMessage::create([
            'session_id' => $sessionId,
            'role'       => 'ROLE_USER',
            'content'    => $message,
        ]);

        // 调用 AI API
        try {
            $aiResponse = $this->callAI($message, $history, $maxTokens, $temperature);
        } catch (\Throwable $e) {
            Log::channel('exception')->error('AI 接口调用失败', [
                'message' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);
            $aiResponse = '抱歉，AI 服务暂时不可用，请稍后再试。';
        }

        // 保存 AI 回复
        ChatMessage::create([
            'session_id' => $sessionId,
            'role'       => 'ROLE_ASSISTANT',
            'content'    => $aiResponse,
        ]);

        // 更新会话最后消息
        ChatSession::where('session_id', $sessionId)->update([
            'last_message' => Str::limit($aiResponse, 100),
            'updated_at'   => now(),
        ]);

        Log::channel('business')->info('AI 对话', [
            'session_id' => $sessionId,
            'user_id'    => $userId,
            'message'    => Str::limit($message, 100),
        ]);

        return [
            'success'    => true,
            'message'    => 'success',
            'aiResponse' => $aiResponse,
            'sessionId'  => $sessionId,
            'timestamp'  => now()->toIso8601String(),
        ];
    }

    /**
     * 调用 DeepSeek AI API
     */
    private function callAI(string $message, array $history, int $maxTokens, float $temperature): string
    {
        $apiKey = config('services.deepseek.api_key');
        $apiUrl = config('services.deepseek.api_url', 'https://api.deepseek.com/v1/chat/completions');
        $model  = config('services.deepseek.model', 'deepseek-chat');

        if (empty($apiKey)) {
            // 没有 API key 时返回预设回复
            return $this->getFallbackResponse($message);
        }

        $messages = [['role' => 'system', 'content' => $this->getSystemPrompt()]];

        foreach ($history as $msg) {
            $role = $msg['role'] === 'ROLE_USER' ? 'user' : 'assistant';
            $messages[] = ['role' => $role, 'content' => $msg['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->withOptions(['verify' => storage_path('cacert.pem')])
            ->post($apiUrl, [
                'model'       => $model,
                'messages'    => $messages,
                'max_tokens'  => $maxTokens,
                'temperature' => $temperature,
            ]);

        if ($response->failed()) {
            Log::channel('exception')->error('DeepSeek API 错误', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('AI 服务返回错误: ' . $response->status());
        }

        return $response->json('choices.0.message.content', '抱歉，未能获取到回复。');
    }

    /**
     * 系统提示词
     */
    private function getSystemPrompt(): string
    {
        return <<<'PROMPT'
你是一位国家级非遗烧箔画技艺传承人，精通烧箔画的历史、技法、鉴赏和传承。
你的回答应：
1. 使用专业准确的术语
2. 语气亲切、循循善诱，如师傅教导徒弟
3. 适当引用历史典故和名作举例
4. 回答长度适中，避免过长的冗述
PROMPT;
    }

    /**
     * 兜底回复（无 API key 时使用）
     */
    private function getFallbackResponse(string $message): string
    {
        $replies = [
            '烧箔'  => '烧箔技艺是中国传统金属工艺瑰宝。制作烧箔画需要：① 准备金/银/铜箔 ② 将箔片固定在底板上 ③ 用特制黏结剂描稿 ④ 高温烧制使箔片与底板熔合 ⑤ 冷却固色。其中火候控制最为关键——温度过高箔片会熔化变形，过低则无法附着。建议初学者从铜箔开始练习。',
            '工具'  => '烧箔画的基本工具有：毛笔（用于涂抹黏结剂）、软刷（清理箔面）、电烙铁或专用烧箔笔（核心工具）、熨斗（大面积烫平）、金属刮片（修整边缘）、镊子（夹取箔片）。此外还需要底板（木板/宣纸/绢）、金箔/银箔/铜箔等材料。',
            '历史'  => '烧箔技艺历史可追溯至唐代金银器工艺，宋代成熟为独立装饰技法，明清时期达到鼎盛。其中苏州派以精细工笔见长，潮汕派以豪放写意著称，文人派则融合诗书画印。2024年，烧箔画正式被列入中国非物质文化遗产名录。',
        ];

        foreach ($replies as $keyword => $reply) {
            if (mb_strpos($message, $keyword) !== false) {
                return $reply;
            }
        }

        return '您好！我是烧箔画非遗传承助手。您可以向我提问关于烧箔技艺的历史、工具、技法、鉴赏等任何问题。我会尽力为您解答！';
    }

    /**
     * 聊天测试
     */
    public function chatTest(string $message, ?int $userId = null): array
    {
        return $this->sendChatMessage(['message' => $message], $userId);
    }

    /**
     * 会话列表
     */
    public function getChatSessions(int $userId, array $params): array
    {
        $page      = (int) ($params['page'] ?? 1);
        $page_size = min((int) ($params['page_size'] ?? 20), 100);

        $paginator = ChatSession::active()
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->paginate($page_size, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(function ($s) {
            return [
                'sessionId'   => $s->session_id,
                'title'       => $s->title,
                'lastMessage' => $s->last_message,
                'createdAt'   => $s->created_at?->toIso8601String(),
                'updatedAt'   => $s->updated_at?->toIso8601String(),
            ];
        })->toArray();

        return [
            'list'     => $list,
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    /**
     * 会话消息历史
     */
    public function getChatMessages(int $userId, string $sessionId): array
    {
        $session = ChatSession::active()
            ->where('session_id', $sessionId)
            ->where('user_id', $userId)
            ->first();

        if (! $session) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '会话不存在');
        }

        $messages = ChatMessage::active()
            ->where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get(['id', 'role', 'content', 'created_at']);

        return [
            'list'  => $messages->map(fn($m) => [
                'id'        => $m->id,
                'role'      => $m->role,
                'content'   => $m->content,
                'createdAt' => $m->created_at?->toIso8601String(),
            ])->toArray(),
            'total' => $messages->count(),
        ];
    }

    /**
     * 删除指定会话
     */
    public function deleteChatSession(int $userId, string $sessionId): void
    {
        $session = ChatSession::active()
            ->where('session_id', $sessionId)
            ->where('user_id', $userId)
            ->first();

        if (! $session) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '会话不存在');
        }

        // 软删除会话和消息
        $session->update(['is_deleted' => true, 'deleted_at' => now()]);
        ChatMessage::where('session_id', $sessionId)->update([
            'is_deleted' => true, 'deleted_at' => now(),
        ]);

        Log::channel('business')->info('用户删除 AI 会话', [
            'user_id'    => $userId,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * 清空所有会话
     */
    public function clearChatSessions(int $userId): int
    {
        $sessionIds = ChatSession::active()
            ->where('user_id', $userId)
            ->pluck('session_id');

        $count = $sessionIds->count();

        ChatSession::active()
            ->where('user_id', $userId)
            ->update(['is_deleted' => true, 'deleted_at' => now()]);

        ChatMessage::whereIn('session_id', $sessionIds->toArray())
            ->update(['is_deleted' => true, 'deleted_at' => now()]);

        Log::channel('business')->info('用户清空 AI 会话', [
            'user_id' => $userId,
            'count'   => $count,
        ]);

        return $count;
    }
}
