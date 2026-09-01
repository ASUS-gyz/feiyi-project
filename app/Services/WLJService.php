<?php

namespace App\Services;

use App\Enums\ResponseCode;
use App\Exceptions\BusinessException;
use App\Models\Comment;
use App\Models\CommentLike;
use App\Models\Cooperation;
use App\Models\CooperationSubmission;
use App\Models\Favorite;
use App\Models\Masterpiece;
use App\Models\MasterpieceLike;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\User;

class WLJService
{
    // ==================================================================
    //  互动帖子模块
    // ==================================================================

    /**
     * 分页查询帖子列表
     */
    public function listPosts(array $params, ?int $userId = null): array
    {
        $page     = (int) ($params['page'] ?? 1);
        $pageSize = min((int) ($params['page_size'] ?? 20), 100);

        $query = Post::active()->with('author');

        if (!empty($params['category'])) {
            $query->where('category', $params['category']);
        }

        if (!empty($params['keyword'])) {
            $keyword = $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")
                  ->orWhere('content', 'like', "%{$keyword}%");
            });
        }

        $sortMap = [
            'createdAt'    => 'created_at',
            'likeCount'    => 'like_count',
            'commentCount' => 'comment_count',
            'viewCount'    => 'view_count',
        ];
        $sortBy = $sortMap[$params['sort_by'] ?? ''] ?? 'created_at';
        $order  = strtolower($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy($sortBy, $order)->paginate($pageSize, ['*'], 'page', $page);

        $postIds     = collect($paginator->items())->pluck('id')->all();
        $likedIds    = $userId ? PostLike::where('user_id', $userId)->whereIn('post_id', $postIds)->pluck('post_id')->all() : [];
        $favoriteIds = $userId ? Favorite::where('user_id', $userId)->where('target_type', 'POST')->whereIn('target_id', $postIds)->pluck('target_id')->all() : [];

        $list = collect($paginator->items())->map(function (Post $post) use ($likedIds, $favoriteIds) {
            return $this->formatPost($post, in_array($post->id, $likedIds), in_array($post->id, $favoriteIds));
        })->values()->all();

        return [
            'list'     => $list,
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    /**
     * 获取帖子详情（自动累加阅读量）
     */
    public function postDetail(int $id, ?int $userId = null): array
    {
        $post = Post::active()->with('author')->find($id);

        if (!$post) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '帖子不存在');
        }

        $post->increment('view_count');

        $isLiked     = $userId ? PostLike::where('user_id', $userId)->where('post_id', $id)->exists() : false;
        $isFavorited = $userId ? Favorite::where('user_id', $userId)->where('target_type', 'POST')->where('target_id', $id)->exists() : false;

        return $this->formatPost($post, $isLiked, $isFavorited);
    }

    /**
     * 创建帖子
     */
    public function createPost(User $user, array $data): array
    {
        $post = Post::create([
            'user_id'  => $user->id,
            'title'    => $data['title'],
            'content'  => $data['content'],
            'category' => $data['category'],
            'images'   => $data['images'] ?? [],
            'status'   => 'POST_PUBLISHED',
        ]);

        $post->load('author');

        return $this->formatPost($post, false, false);
    }

    /**
     * 修改帖子（作者/管理员）
     */
    public function updatePost(int $id, User $user, array $data): array
    {
        $post = Post::active()->find($id);

        if (!$post) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '帖子不存在');
        }

        if ($post->user_id !== $user->id && $user->role !== 'ADMIN') {
            throw new BusinessException(ResponseCode::FORBIDDEN);
        }

        if (array_key_exists('title', $data)) {
            $post->title = $data['title'];
        }
        if (array_key_exists('content', $data)) {
            $post->content = $data['content'];
        }
        if (array_key_exists('category', $data)) {
            $post->category = $data['category'];
        }
        if (array_key_exists('images', $data)) {
            $post->images = $data['images'] ?? [];
        }

        $post->save();
        $post->load('author');

        $isLiked     = PostLike::where('user_id', $user->id)->where('post_id', $id)->exists();
        $isFavorited = Favorite::where('user_id', $user->id)->where('target_type', 'POST')->where('target_id', $id)->exists();

        return $this->formatPost($post, $isLiked, $isFavorited);
    }

    /**
     * 删除帖子（作者/管理员，软删除）
     */
    public function deletePost(int $id, User $user): void
    {
        $post = Post::active()->find($id);

        if (!$post) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '帖子不存在');
        }

        if ($post->user_id !== $user->id && $user->role !== 'ADMIN') {
            throw new BusinessException(ResponseCode::FORBIDDEN);
        }

        $post->is_deleted = true;
        $post->deleted_at = now();
        $post->save();
    }

    // ==================================================================
    //  评论与回复模块
    // ==================================================================

    /**
     * 获取帖子评论（含嵌套回复）
     */
    public function listPostComments(int $postId, bool $includeReplies): array
    {
        $query = Comment::active()
            ->where('post_id', $postId)
            ->whereNull('parent_id')
            ->with('user')
            ->orderBy('created_at', 'asc');

        if ($includeReplies) {
            $query->with(['replies' => function ($q) {
                $q->active()->with('user')->orderBy('created_at', 'asc');
            }]);
        }

        return $query->get()->map(function (Comment $comment) use ($includeReplies) {
            return $this->formatComment($comment, $includeReplies);
        })->values()->all();
    }

    /**
     * 获取全部评论列表（后台管理用）
     */
    public function listAllComments(array $params): array
    {
        $page     = (int) ($params['page'] ?? 1);
        $pageSize = min((int) ($params['page_size'] ?? 20), 100);

        $query = Comment::active()->with('user');

        if (!empty($params['post_id'])) {
            $query->where('post_id', $params['post_id']);
        }

        // parentId：0 或省略 = 顶级评论；传值 = 子回复
        $parentId = $params['parent_id'] ?? 0;
        if ((int) $parentId === 0) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', (int) $parentId);
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($pageSize, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(function (Comment $comment) {
            return $this->formatComment($comment, false);
        })->values()->all();

        return [
            'list'     => $list,
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    /**
     * 发布评论或回复
     */
    public function createComment(User $user, array $data): array
    {
        $postId   = (int) $data['post_id'];
        $parentId = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;

        $post = Post::active()->find($postId);
        if (!$post) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '帖子不存在');
        }

        if ($parentId) {
            $parent = Comment::active()->where('id', $parentId)->where('post_id', $postId)->first();
            if (!$parent) {
                throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '回复的评论不存在');
            }
        }

        $comment = Comment::create([
            'user_id'   => $user->id,
            'post_id'   => $postId,
            'parent_id' => $parentId,
            'content'   => $data['content'],
            'status'    => 'COMMENT_NORMAL',
        ]);

        // 维护计数
        $post->increment('comment_count');
        if ($parentId) {
            Comment::where('id', $parentId)->increment('reply_count');
        }

        $comment->load('user');

        return $this->formatComment($comment, false);
    }

    /**
     * 修改评论（作者本人）
     */
    public function updateComment(int $id, User $user, array $data): array
    {
        $comment = Comment::active()->find($id);

        if (!$comment) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '评论不存在');
        }

        if ($comment->user_id !== $user->id) {
            throw new BusinessException(ResponseCode::FORBIDDEN);
        }

        $comment->content = $data['content'];
        $comment->save();
        $comment->load('user');

        return $this->formatComment($comment, false);
    }

    /**
     * 删除评论（作者/管理员，软删除）
     */
    public function deleteComment(int $id, User $user): void
    {
        $comment = Comment::active()->find($id);

        if (!$comment) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '评论不存在');
        }

        if ($comment->user_id !== $user->id && $user->role !== 'ADMIN') {
            throw new BusinessException(ResponseCode::FORBIDDEN);
        }

        $comment->is_deleted = true;
        $comment->deleted_at = now();
        $comment->save();

        // 维护计数
        $post = Post::find($comment->post_id);
        if ($post && $post->comment_count > 0) {
            $post->decrement('comment_count');
        }
        if ($comment->parent_id) {
            Comment::where('id', $comment->parent_id)->where('reply_count', '>', 0)->decrement('reply_count');
        }
    }

    /**
     * 点赞评论，返回最新点赞总数
     */
    public function likeComment(int $commentId, User $user): int
    {
        $comment = Comment::active()->find($commentId);

        if (!$comment) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '评论不存在');
        }

        $exists = CommentLike::where('user_id', $user->id)->where('comment_id', $commentId)->exists();
        if ($exists) {
            throw new BusinessException(ResponseCode::BUSINESS_DUPLICATE);
        }

        CommentLike::create(['user_id' => $user->id, 'comment_id' => $commentId]);
        $comment->increment('like_count');

        return (int) $comment->fresh()->like_count;
    }

    /**
     * 取消点赞评论，返回最新点赞总数
     */
    public function unlikeComment(int $commentId, User $user): int
    {
        $comment = Comment::active()->find($commentId);

        if (!$comment) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '评论不存在');
        }

        $like = CommentLike::where('user_id', $user->id)->where('comment_id', $commentId)->first();
        if (!$like) {
            throw new BusinessException(ResponseCode::BUSINESS_INVALID_STATE);
        }

        $like->delete();
        if ($comment->like_count > 0) {
            $comment->decrement('like_count');
        }

        return (int) $comment->fresh()->like_count;
    }

    // ==================================================================
    //  传世名作模块
    // ==================================================================

    /**
     * 分页查询名作列表
     */
    public function listMasterpieces(array $params, ?int $userId = null): array
    {
        $page     = (int) ($params['page'] ?? 1);
        $pageSize = min((int) ($params['page_size'] ?? 10), 100);

        $query = Masterpiece::active();

        if (!empty($params['period'])) {
            $query->where('period', $params['period']);
        }
        if (!empty($params['school'])) {
            $query->where('school', $params['school']);
        }
        if (!empty($params['keyword'])) {
            $keyword = $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('description', 'like', "%{$keyword}%");
            });
        }

        $sortMap = [
            'createdAt' => 'created_at',
            'likeCount' => 'like_count',
            'viewCount' => 'view_count',
        ];
        $sortBy = $sortMap[$params['sort_by'] ?? ''] ?? 'created_at';
        $order  = strtolower($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy($sortBy, $order)->paginate($pageSize, ['*'], 'page', $page);

        $masterpieceIds = collect($paginator->items())->pluck('id')->all();
        $likedIds       = $userId ? MasterpieceLike::where('user_id', $userId)->whereIn('masterpiece_id', $masterpieceIds)->pluck('masterpiece_id')->all() : [];
        $favoriteIds    = $userId ? Favorite::where('user_id', $userId)->where('target_type', 'MASTERPIECE')->whereIn('target_id', $masterpieceIds)->pluck('target_id')->all() : [];

        $list = collect($paginator->items())->map(function (Masterpiece $masterpiece) use ($likedIds, $favoriteIds) {
            return $this->formatMasterpieceList($masterpiece, in_array($masterpiece->id, $likedIds), in_array($masterpiece->id, $favoriteIds));
        })->values()->all();

        return [
            'list'     => $list,
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    /**
     * 获取名作详情
     */
    public function masterpieceDetail(int $id, ?int $userId = null): array
    {
        $masterpiece = Masterpiece::active()->with(['steps' => function ($q) {
            $q->active()->orderBy('sort_order');
        }])->find($id);

        if (!$masterpiece) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '名作不存在');
        }

        $masterpiece->increment('view_count');

        $isLiked     = $userId ? MasterpieceLike::where('user_id', $userId)->where('masterpiece_id', $id)->exists() : false;
        $isFavorited = $userId ? Favorite::where('user_id', $userId)->where('target_type', 'MASTERPIECE')->where('target_id', $id)->exists() : false;

        return $this->formatMasterpieceDetail($masterpiece, $isLiked, $isFavorited);
    }

    /**
     * 点赞名作，返回最新点赞总数
     */
    public function likeMasterpiece(int $id, User $user): int
    {
        $masterpiece = Masterpiece::active()->find($id);

        if (!$masterpiece) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '名作不存在');
        }

        $exists = MasterpieceLike::where('user_id', $user->id)->where('masterpiece_id', $id)->exists();
        if ($exists) {
            throw new BusinessException(ResponseCode::BUSINESS_DUPLICATE);
        }

        MasterpieceLike::create(['user_id' => $user->id, 'masterpiece_id' => $id]);
        $masterpiece->increment('like_count');

        return (int) $masterpiece->fresh()->like_count;
    }

    /**
     * 取消点赞名作，返回最新点赞总数
     */
    public function unlikeMasterpiece(int $id, User $user): int
    {
        $masterpiece = Masterpiece::active()->find($id);

        if (!$masterpiece) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '名作不存在');
        }

        $like = MasterpieceLike::where('user_id', $user->id)->where('masterpiece_id', $id)->first();
        if (!$like) {
            throw new BusinessException(ResponseCode::BUSINESS_INVALID_STATE);
        }

        $like->delete();
        if ($masterpiece->like_count > 0) {
            $masterpiece->decrement('like_count');
        }

        return (int) $masterpiece->fresh()->like_count;
    }

    // ==================================================================
    //  收藏夹模块
    // ==================================================================

    /**
     * 我的收藏列表
     */
    public function listFavorites(User $user, array $params): array
    {
        $page     = (int) ($params['page'] ?? 1);
        $pageSize = min((int) ($params['page_size'] ?? 20), 100);

        $query = Favorite::where('user_id', $user->id)->orderBy('created_at', 'desc');

        if (!empty($params['target_type'])) {
            $query->where('target_type', $params['target_type']);
        }

        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);
        $items     = collect($paginator->items());

        $postIds        = $items->where('target_type', 'POST')->pluck('target_id')->all();
        $masterpieceIds = $items->where('target_type', 'MASTERPIECE')->pluck('target_id')->all();

        $posts        = Post::whereIn('id', $postIds)->get()->keyBy('id');
        $masterpieces = Masterpiece::whereIn('id', $masterpieceIds)->get()->keyBy('id');

        $list = $items->map(function (Favorite $favorite) use ($posts, $masterpieces) {
            $target = null;

            if ($favorite->target_type === 'POST' && isset($posts[$favorite->target_id])) {
                $post   = $posts[$favorite->target_id];
                $target = [
                    'id'           => $post->id,
                    'title'        => $post->title,
                    'coverImage'   => (!empty($post->images)) ? $post->images[0] : null,
                    'likeCount'    => $post->like_count,
                    'commentCount' => $post->comment_count,
                ];
            } elseif ($favorite->target_type === 'MASTERPIECE' && isset($masterpieces[$favorite->target_id])) {
                $masterpiece = $masterpieces[$favorite->target_id];
                $target = [
                    'id'         => $masterpiece->id,
                    'title'      => $masterpiece->name,
                    'coverImage' => $masterpiece->cover_image,
                    'likeCount'  => $masterpiece->like_count,
                ];
            }

            return [
                'id'         => $favorite->id,
                'targetId'   => $favorite->target_id,
                'targetType' => $favorite->target_type,
                'target'     => $target,
                'createdAt'  => $favorite->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'list'     => $list,
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    /**
     * 添加收藏
     */
    public function addFavorite(User $user, array $data): array
    {
        $targetId   = (int) $data['target_id'];
        $targetType = $data['target_type'];

        $this->verifyTargetExists($targetId, $targetType);

        $exists = Favorite::where('user_id', $user->id)
            ->where('target_id', $targetId)
            ->where('target_type', $targetType)
            ->exists();
        if ($exists) {
            throw new BusinessException(ResponseCode::BUSINESS_DUPLICATE);
        }

        $favorite = Favorite::create([
            'user_id'     => $user->id,
            'target_id'   => $targetId,
            'target_type' => $targetType,
        ]);

        return [
            'id'         => $favorite->id,
            'targetId'   => $favorite->target_id,
            'targetType' => $favorite->target_type,
            'createdAt'  => $favorite->created_at?->toIso8601String(),
        ];
    }

    /**
     * 检查收藏状态
     */
    public function checkFavorite(User $user, array $data): array
    {
        $targetId   = (int) $data['target_id'];
        $targetType = $data['target_type'];

        $favorite = Favorite::where('user_id', $user->id)
            ->where('target_id', $targetId)
            ->where('target_type', $targetType)
            ->first();

        return [
            'isFavorited' => (bool) $favorite,
            'favoriteId'  => $favorite?->id,
        ];
    }

    /**
     * 取消收藏（按记录 ID 或按目标）
     */
    public function removeFavorite(User $user, ?int $id, ?int $targetId, ?string $targetType): void
    {
        if ($id) {
            $favorite = Favorite::where('id', $id)->where('user_id', $user->id)->first();
            if (!$favorite) {
                throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '收藏记录不存在');
            }
            $favorite->delete();
            return;
        }

        if ($targetId && $targetType) {
            $favorite = Favorite::where('user_id', $user->id)
                ->where('target_id', $targetId)
                ->where('target_type', $targetType)
                ->first();
            if (!$favorite) {
                throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '收藏记录不存在');
            }
            $favorite->delete();
            return;
        }

        throw new BusinessException(ResponseCode::PARAM_MISSING, '请提供收藏记录 ID 或目标信息');
    }

    // ==================================================================
    //  共创计划模块
    // ==================================================================

    /**
     * 共创项目列表
     */
    public function listCooperations(array $params): array
    {
        $page     = (int) ($params['page'] ?? 1);
        $pageSize = min((int) ($params['page_size'] ?? 20), 100);

        $query = Cooperation::active();

        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($pageSize, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(function (Cooperation $cooperation) {
            return $this->formatCooperation($cooperation);
        })->values()->all();

        return [
            'list'     => $list,
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    /**
     * 共创项目详情
     */
    public function cooperationDetail(int $id): array
    {
        $cooperation = Cooperation::active()->with(['submissions' => function ($q) {
            $q->active()->orderBy('created_at', 'desc')->limit(5);
        }])->find($id);

        if (!$cooperation) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '共创项目不存在');
        }

        $data = $this->formatCooperation($cooperation);
        $data['recentSubmissions'] = $cooperation->submissions->map(function (CooperationSubmission $submission) {
            return [
                'submissionId' => $submission->id,
                'title'        => $submission->title,
                'authorName'   => $submission->author_name ?: $submission->user?->nickname,
                'images'       => $submission->images ?? [],
                'createdAt'    => $submission->created_at?->format('Y-m-d'),
            ];
        })->values()->all();

        return $data;
    }

    /**
     * 提交共创作品
     */
    public function submitCooperation(User $user, int $id, array $data): array
    {
        $cooperation = Cooperation::active()->find($id);

        if (!$cooperation) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '共创项目不存在');
        }

        // 仅征集中的项目可提交
        if ($cooperation->status !== 'COOP_COLLECTING' || $cooperation->deadline->endOfDay()->isPast()) {
            throw new BusinessException(ResponseCode::BUSINESS_LIMIT, '当前项目已截止或不在征集阶段');
        }

        $authorName = !empty($data['author_name']) ? $data['author_name'] : ($user->nickname ?: $user->name);

        $submission = CooperationSubmission::create([
            'user_id'        => $user->id,
            'cooperation_id' => $cooperation->id,
            'title'          => $data['title'],
            'description'    => $data['description'],
            'images'         => $data['images'] ?? [],
            'author_name'    => $authorName,
            'status'         => 'SUBMISSION_PENDING',
        ]);

        $cooperation->increment('submission_count');

        return [
            'submissionId' => $submission->id,
            'projectId'    => $cooperation->id,
            'title'        => $submission->title,
            'status'       => $submission->status,
            'createdAt'    => $submission->created_at?->toIso8601String(),
        ];
    }

    /**
     * 我的提交记录
     */
    public function mySubmissions(User $user, array $params): array
    {
        $page     = (int) ($params['page'] ?? 1);
        $pageSize = min((int) ($params['page_size'] ?? 20), 100);

        $paginator = CooperationSubmission::active()
            ->where('user_id', $user->id)
            ->with('cooperation')
            ->orderBy('created_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(function (CooperationSubmission $submission) {
            return [
                'submissionId' => $submission->id,
                'projectId'    => $submission->cooperation_id,
                'projectTitle' => $submission->cooperation?->title,
                'title'        => $submission->title,
                'description'  => $submission->description,
                'images'       => $submission->images ?? [],
                'authorName'   => $submission->author_name,
                'status'       => $submission->status,
                'feedback'     => $submission->feedback,
                'createdAt'    => $submission->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'list'     => $list,
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    // ==================================================================
    //  格式化辅助方法
    // ==================================================================

    /**
     * 格式化帖子
     */
    private function formatPost(Post $post, bool $isLiked, bool $isFavorited): array
    {
        return [
            'id'           => $post->id,
            'userId'       => $post->user_id,
            'author'       => $this->formatAuthor($post->author),
            'title'        => $post->title,
            'content'      => $post->content,
            'category'     => $post->category,
            'images'       => $post->images ?? [],
            'likeCount'    => $post->like_count,
            'commentCount' => $post->comment_count,
            'viewCount'    => $post->view_count,
            'isLiked'      => $isLiked,
            'isFavorited'  => $isFavorited,
            'status'       => $post->status,
            'createdAt'    => $post->created_at?->toIso8601String(),
            'updatedAt'    => $post->updated_at?->toIso8601String(),
        ];
    }

    /**
     * 格式化作者信息
     */
    private function formatAuthor(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'userId'   => $user->id,
            'username' => $user->name,
            'nickname' => $user->nickname,
            'avatar'   => $user->avatar,
        ];
    }

    /**
     * 格式化评论（含可选嵌套回复）
     */
    private function formatComment(Comment $comment, bool $includeReplies = false): array
    {
        $data = [
            'id'         => $comment->id,
            'userId'     => $comment->user_id,
            'content'    => $comment->content,
            'parentId'   => $comment->parent_id,
            'postId'     => $comment->post_id,
            'likeCount'  => $comment->like_count,
            'replyCount' => $comment->reply_count,
            'status'     => $comment->status,
            'username'   => $comment->user?->name,
            'nickname'   => $comment->user?->nickname,
            'avatar'     => $comment->user?->avatar,
            'replies'    => [],
            'createdAt'  => $comment->created_at?->format('Y-m-d H:i:s'),
            'updatedAt'  => $comment->updated_at?->format('Y-m-d H:i:s'),
        ];

        if ($includeReplies) {
            $data['replies'] = $comment->replies->map(function (Comment $reply) {
                return $this->formatComment($reply, false);
            })->values()->all();
        }

        return $data;
    }

    /**
     * 格式化名作列表项
     */
    private function formatMasterpieceList(Masterpiece $masterpiece, bool $isLiked, bool $isFavorited): array
    {
        return [
            'id'          => $masterpiece->id,
            'name'        => $masterpiece->name,
            'period'      => $masterpiece->period,
            'school'      => $masterpiece->school,
            'coverImage'  => $masterpiece->cover_image,
            'likeCount'   => $masterpiece->like_count,
            'viewCount'   => $masterpiece->view_count,
            'isLiked'     => $isLiked,
            'isFavorited' => $isFavorited,
            'createdAt'   => $masterpiece->created_at?->format('Y-m-d'),
        ];
    }

    /**
     * 格式化名作详情
     */
    private function formatMasterpieceDetail(Masterpiece $masterpiece, bool $isLiked, bool $isFavorited): array
    {
        return [
            'id'          => $masterpiece->id,
            'name'        => $masterpiece->name,
            'period'      => $masterpiece->period,
            'school'      => $masterpiece->school,
            'icon'        => $masterpiece->icon,
            'description' => $masterpiece->description,
            'timeMaking'  => $masterpiece->time_making,
            'foilUsed'    => $masterpiece->foil_used,
            'difficulty'  => $masterpiece->difficulty,
            'background'  => $masterpiece->background,
            'technique'   => $masterpiece->technique,
            'story'       => $masterpiece->story,
            'value'       => $masterpiece->value,
            'coverImage'  => $masterpiece->cover_image,
            'images'      => $masterpiece->images ?? [],
            'likeCount'   => $masterpiece->like_count,
            'viewCount'   => $masterpiece->view_count,
            'isLiked'     => $isLiked,
            'isFavorited' => $isFavorited,
            'steps'       => $masterpiece->steps->map(function ($step) {
                return [
                    'name'        => $step->name,
                    'description' => $step->description,
                    'difficulty'  => $step->difficulty ? str_repeat('★', $step->difficulty) : '',
                ];
            })->values()->all(),
            'createdAt'   => $masterpiece->created_at?->format('Y-m-d'),
        ];
    }

    /**
     * 格式化共创项目
     */
    private function formatCooperation(Cooperation $cooperation): array
    {
        return [
            'id'              => $cooperation->id,
            'title'           => $cooperation->title,
            'description'     => $cooperation->description,
            'deadline'        => $cooperation->deadline?->format('Y-m-d'),
            'status'          => $cooperation->status,
            'submissionCount' => $cooperation->submission_count,
            'rules'           => $cooperation->rules,
            'rewards'         => $cooperation->rewards,
            'images'          => $cooperation->images ?? [],
        ];
    }

    /**
     * 校验收藏目标是否存在
     */
    private function verifyTargetExists(int $targetId, string $targetType): void
    {
        if ($targetType === 'POST') {
            if (!Post::active()->where('id', $targetId)->exists()) {
                throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '收藏的帖子不存在');
            }
        } elseif ($targetType === 'MASTERPIECE') {
            if (!Masterpiece::active()->where('id', $targetId)->exists()) {
                throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '收藏的名作不存在');
            }
        }
        // ARTICLE：当前无文章表，仅存储不做存在性校验
    }
}
