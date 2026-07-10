<?php

namespace App\Http\Controllers;

use App\Http\Requests\GYZRequest;
use App\Services\GYZService;
use App\Support\Result;
use Illuminate\Http\JsonResponse;

class GYZController extends Controller
{
    public function __construct(
        private GYZService $service
    ) {}

    // ==================================================================
    //  文创商城
    // ==================================================================

    /** GET /api/shop/categories */
    public function shopCategories(): JsonResponse
    {
        $data = $this->service->getCategories();
        return Result::success('获取成功', $data);
    }

    /** GET /api/shop/products */
    public function shopProducts(GYZRequest $request): JsonResponse
    {
        $data = $this->service->getProducts($request->validated());
        return Result::success('获取成功', $data);
    }

    /** GET /api/shop/products/{id} */
    public function shopProductDetail(int $id): JsonResponse
    {
        $data = $this->service->getProductDetail($id);
        return Result::success('获取成功', $data);
    }

    /** POST /api/shop/orders */
    public function shopOrderCreate(GYZRequest $request): JsonResponse
    {
        $userId = auth()->id();
        $data = $this->service->createOrder($userId, $request->validated());
        return Result::success('下单成功', $data);
    }

    /** GET /api/shop/orders */
    public function shopOrders(GYZRequest $request): JsonResponse
    {
        $userId = auth()->id();
        $data = $this->service->getOrders($userId, $request->validated());
        return Result::success('获取成功', $data);
    }

    // ==================================================================
    //  消息通知
    // ==================================================================

    /** GET /api/notifications */
    public function notificationList(GYZRequest $request): JsonResponse
    {
        $userId = auth()->id();
        $data = $this->service->getNotifications($userId, $request->validated());
        return Result::success('获取成功', $data);
    }

    /** GET /api/notifications/unread-count */
    public function notificationUnreadCount(GYZRequest $request): JsonResponse
    {
        $userId = auth()->id();
        $type = $request->validated('type');
        $data = $this->service->getUnreadCount($userId, $type);
        return Result::success('获取成功', $data);
    }

    /** POST /api/notifications/{id}/read */
    public function notificationRead(int $id): JsonResponse
    {
        $userId = auth()->id();
        $this->service->markAsRead($userId, $id);
        return Result::success('标记成功');
    }

    /** POST /api/notifications/read-all */
    public function notificationReadAll(): JsonResponse
    {
        $userId = auth()->id();
        $updatedCount = $this->service->markAllAsRead($userId);
        return Result::success('全部标记已读', ['updatedCount' => $updatedCount]);
    }

    /** DELETE /api/notifications/{id} */
    public function notificationDelete(int $id): JsonResponse
    {
        $userId = auth()->id();
        $this->service->deleteNotification($userId, $id);
        return Result::success('删除成功');
    }

    /** DELETE /api/notifications/read */
    public function notificationClearRead(): JsonResponse
    {
        $userId = auth()->id();
        $deletedCount = $this->service->clearReadNotifications($userId);
        return Result::success('清空成功', ['deletedCount' => $deletedCount]);
    }

    // ==================================================================
    //  线上轻互动（小游戏）
    // ==================================================================

    /** GET /api/games */
    public function gameList(GYZRequest $request): JsonResponse
    {
        $type = $request->validated('type');
        $data = $this->service->getGames($type);
        return Result::success('获取成功', $data);
    }

    /** GET /api/games/{type} */
    public function gameDetail(string $type, GYZRequest $request): JsonResponse
    {
        $data = $this->service->getGameDetail($type);
        return Result::success('获取成功', $data);
    }

    /** GET /api/games/{type}/levels */
    public function gameLevels(string $type): JsonResponse
    {
        $data = $this->service->getLevels($type);
        return Result::success('获取成功', $data);
    }

    /** GET /api/games/drawing/levels/{id}/pattern */
    public function gamePattern(int $id): JsonResponse
    {
        $data = $this->service->getPattern($id);
        return Result::success('获取成功', $data);
    }

    /** GET /api/games/coloring/templates/{id} */
    public function gameTemplate(int $id): JsonResponse
    {
        $data = $this->service->getTemplate($id);
        return Result::success('获取成功', $data);
    }

    /** POST /api/games/scores */
    public function gameScoreSubmit(GYZRequest $request): JsonResponse
    {
        $userId = auth()->id();
        $data = $this->service->submitScore($userId, $request->validated());
        return Result::success('提交成功', $data);
    }

    /** GET /api/games/scores/my */
    public function gameScoresMy(GYZRequest $request): JsonResponse
    {
        $userId = auth()->id();
        $data = $this->service->getMyScores($userId, $request->validated());
        return Result::success('获取成功', $data);
    }

    /** GET /api/games/{type}/leaderboard */
    public function gameLeaderboard(string $type, GYZRequest $request): JsonResponse
    {
        $data = $this->service->getLeaderboard($type, $request->validated());
        return Result::success('获取成功', $data);
    }

    /** GET /api/games/{type}/levels/{id}/best */
    public function gameBestScore(string $type, int $id): JsonResponse
    {
        $userId = auth()->id();
        $data = $this->service->getBestScore($userId, $type, $id);
        return Result::success('获取成功', $data);
    }

    /** GET /api/games/scores/{id}/certificate */
    public function gameCertificate(int $id): JsonResponse
    {
        $this->service->getCertificate($id);
        return Result::success('获取成功');
    }

    // ==================================================================
    //  AI 智能问答
    // ==================================================================

    /** POST /api/chat/message */
    public function chatMessage(GYZRequest $request): JsonResponse
    {
        $userId = auth()->id();
        $data = $this->service->sendChatMessage($request->validated(), $userId);
        return response()->json($data);
    }

    /** GET /api/chat/test */
    public function chatTest(GYZRequest $request): JsonResponse
    {
        $data = $this->service->chatTest($request->validated('message'));
        return response()->json($data);
    }

    /** GET /api/chat/health */
    public function chatHealth(): string
    {
        return '非遗烧箔AI服务运行正常';
    }

    /** GET /api/chat/welcome */
    public function chatWelcome(): string
    {
        return '欢迎使用非遗烧箔AI问答系统！';
    }

    /** GET /api/chat/sessions */
    public function chatSessions(GYZRequest $request): JsonResponse
    {
        $userId = auth()->id();
        $data = $this->service->getChatSessions($userId, $request->validated());
        return Result::success('获取成功', $data);
    }

    /** GET /api/chat/sessions/{sessionId}/messages */
    public function chatSessionMessages(string $sessionId): JsonResponse
    {
        $userId = auth()->id();
        $data = $this->service->getChatMessages($userId, $sessionId);
        return Result::success('获取成功', $data);
    }

    /** DELETE /api/chat/sessions/{sessionId} */
    public function chatDeleteSession(string $sessionId): JsonResponse
    {
        $userId = auth()->id();
        $this->service->deleteChatSession($userId, $sessionId);
        return Result::success('删除成功');
    }

    /** DELETE /api/chat/sessions */
    public function chatClearSessions(): JsonResponse
    {
        $userId = auth()->id();
        $deletedCount = $this->service->clearChatSessions($userId);
        return Result::success('清空成功', ['deletedCount' => $deletedCount]);
    }
}
