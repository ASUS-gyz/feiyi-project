<?php

namespace App\Http\Controllers;

use App\Enums\ResponseCode;
use App\Exceptions\BusinessException;
use App\Http\Requests\WLJRequest;
use App\Models\User;
use App\Services\WLJService;
use App\Support\JWT;
use App\Support\Result;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WLJController extends Controller
{
    public function __construct(
        private WLJService $service
    ) {}

    // ==================================================================
    //  互动帖子模块
    // ==================================================================

    /** GET /api/posts */
    public function postList(WLJRequest $request): JsonResponse
    {
        $data = $this->service->listPosts($request->validated(), $this->optionalUserId());

        return Result::success('获取成功', $data);
    }

    /** GET /api/posts/{id} */
    public function postDetail(int $id): JsonResponse
    {
        return Result::success('获取成功', $this->service->postDetail($id, $this->optionalUserId()));
    }

    /** POST /api/posts */
    public function postCreate(WLJRequest $request): JsonResponse
    {
        return Result::success('发布成功', $this->service->createPost($this->authUser(), $request->validated()));
    }

    /** PUT /api/posts/{id} */
    public function postUpdate(int $id, WLJRequest $request): JsonResponse
    {
        return Result::success('修改成功', $this->service->updatePost($id, $this->authUser(), $request->validated()));
    }

    /** DELETE /api/posts/{id} */
    public function postDelete(int $id): JsonResponse
    {
        $this->service->deletePost($id, $this->authUser());

        return Result::success('删除成功');
    }

    // ==================================================================
    //  评论与回复模块
    // ==================================================================

    /** GET /api/comments/post/{postId} */
    public function commentByPost(int $postId, Request $request): JsonResponse
    {
        $data = $this->service->listPostComments($postId, $request->boolean('includeReplies'));

        return Result::success('获取成功', $data);
    }

    /** GET /api/comments */
    public function commentList(WLJRequest $request): JsonResponse
    {
        return Result::success('获取成功', $this->service->listAllComments($request->validated()));
    }

    /** POST /api/comments */
    public function commentCreate(WLJRequest $request): JsonResponse
    {
        return Result::success('评论成功', $this->service->createComment($this->commentUser(), $request->validated()));
    }

    /** PUT /api/comments/{id} */
    public function commentUpdate(int $id, WLJRequest $request): JsonResponse
    {
        return Result::success('修改成功', $this->service->updateComment($id, $this->authUser(), $request->validated()));
    }

    /** DELETE /api/comments/{id} */
    public function commentDelete(int $id): JsonResponse
    {
        $this->service->deleteComment($id, $this->authUser());

        return Result::success('删除成功');
    }

    /** POST /api/comments/{id}/like */
    public function commentLike(int $id): JsonResponse
    {
        $count = $this->service->likeComment($id, $this->commentUser());

        return Result::success('点赞成功', $count);
    }

    /** DELETE /api/comments/{id}/like */
    public function commentUnlike(int $id): JsonResponse
    {
        $count = $this->service->unlikeComment($id, $this->commentUser());

        return Result::success('取消点赞成功', $count);
    }

    // ==================================================================
    //  传世名作模块
    // ==================================================================

    /** GET /api/masterpieces */
    public function masterpieceList(WLJRequest $request): JsonResponse
    {
        return Result::success('获取成功', $this->service->listMasterpieces($request->validated(), $this->optionalUserId()));
    }

    /** GET /api/masterpieces/{id} */
    public function masterpieceDetail(int $id): JsonResponse
    {
        return Result::success('获取成功', $this->service->masterpieceDetail($id, $this->optionalUserId()));
    }

    /** POST /api/masterpieces/{id}/like */
    public function masterpieceLike(int $id): JsonResponse
    {
        $count = $this->service->likeMasterpiece($id, $this->authUser());

        return Result::success('点赞成功', $count);
    }

    /** DELETE /api/masterpieces/{id}/like */
    public function masterpieceUnlike(int $id): JsonResponse
    {
        $count = $this->service->unlikeMasterpiece($id, $this->authUser());

        return Result::success('取消点赞成功', $count);
    }

    // ==================================================================
    //  收藏夹模块
    // ==================================================================

    /** GET /api/favorites */
    public function favoriteList(WLJRequest $request): JsonResponse
    {
        return Result::success('获取成功', $this->service->listFavorites($this->authUser(), $request->validated()));
    }

    /** GET /api/favorites/check */
    public function favoriteCheck(WLJRequest $request): JsonResponse
    {
        return Result::success('获取成功', $this->service->checkFavorite($this->authUser(), $request->validated()));
    }

    /** POST /api/favorites */
    public function favoriteAdd(WLJRequest $request): JsonResponse
    {
        return Result::success('收藏成功', $this->service->addFavorite($this->authUser(), $request->validated()));
    }

    /** DELETE /api/favorites/{id} 或 DELETE /api/favorites?targetId=&targetType= */
    public function favoriteDelete(Request $request): JsonResponse
    {
        $id = $request->route('id');

        $this->service->removeFavorite(
            $this->authUser(),
            $id ? (int) $id : null,
            $request->integer('targetId') ?: null,
            $request->string('targetType')->toString() ?: null
        );

        return Result::success('取消收藏成功');
    }

    // ==================================================================
    //  共创计划模块
    // ==================================================================

    /** GET /api/cooperations */
    public function cooperationList(WLJRequest $request): JsonResponse
    {
        return Result::success('获取成功', $this->service->listCooperations($request->validated()));
    }

    /** GET /api/cooperations/{id} */
    public function cooperationDetail(int $id): JsonResponse
    {
        return Result::success('获取成功', $this->service->cooperationDetail($id));
    }

    /** POST /api/cooperations/{id}/submissions */
    public function cooperationSubmit(int $id, WLJRequest $request): JsonResponse
    {
        return Result::success('提交成功', $this->service->submitCooperation($this->authUser(), $id, $request->validated()));
    }

    /** GET /api/cooperations/submissions/my */
    public function cooperationMySubmissions(WLJRequest $request): JsonResponse
    {
        return Result::success('获取成功', $this->service->mySubmissions($this->authUser(), $request->validated()));
    }

    // ==================================================================
    //  鉴权辅助方法
    // ==================================================================

    /**
     * 获取当前已登录用户（jwt.auth 中间件已绑定）
     */
    private function authUser(): User
    {
        $user = request()->user();
        if (!$user) {
            throw new BusinessException(ResponseCode::UNAUTHORIZED);
        }

        return $user;
    }

    /**
     * 解析评论模块用户（User-ID 头 / 查询参数，无需 JWT）
     */
    private function commentUser(): User
    {
        $userId = request()->header('User-ID') ?? request()->query('User-ID');
        if (!$userId) {
            throw new BusinessException(ResponseCode::UNAUTHORIZED, '缺少 User-ID');
        }

        $user = User::find($userId);
        if (!$user || $user->is_deleted) {
            throw new BusinessException(ResponseCode::UNAUTHORIZED, '用户不存在或已被禁用');
        }

        return $user;
    }

    /**
     * 可选用户 ID：公开接口若携带合法 JWT 则用于计算 isLiked / isFavorited
     */
    private function optionalUserId(): ?int
    {
        $user = request()->user();
        if ($user) {
            return (int) $user->id;
        }

        $token = request()->bearerToken();
        if (!$token) {
            return null;
        }

        try {
            $payload = JWT::decode($token);

            return isset($payload['sub']) ? (int) $payload['sub'] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
