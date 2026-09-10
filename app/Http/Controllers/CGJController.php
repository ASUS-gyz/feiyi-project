<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\CGJRequest;
use App\Http\Requests\User\UpdatePasswordRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Services\CGJService;
use App\Support\Pagination;
use App\Support\Result;

class CGJController extends Controller
{
    public function __construct(
        protected CGJService $authService
    ) {}

    // ==================== 认证模块 ====================

    /**
     * 用户注册
     *
     * POST /api/auth/register
     */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();
        $user = $this->authService->register($validated);

        return Result::success('注册成功', $this->authService->formatUser($user));
    }

    /**
     * 用户登录
     *
     * POST /api/auth/login
     */
    public function login(LoginRequest $request)
    {
        $validated = $request->validated();
        $result = $this->authService->login($validated);

        return Result::success('登录成功', [
            'userId' => $result['user']->id,
            'token' => $result['token'],
        ]);
    }

    /**
     * 退出登录
     *
     * POST /api/auth/logout
     */
    public function logout()
    {
        // 无状态 JWT，客户端自行删除 token
        return Result::success('退出成功', [
            'loggedOut' => true,
            'authorization' => 'Bearer',
        ]);
    }

    /**
     * 获取当前登录用户信息
     *
     * GET /api/auth/me
     */
    public function me()
    {
        $user = request()->user();

        return Result::success('获取成功', $this->authService->formatUser($user));
    }

    // ==================== 用户资料模块 ====================

    /**
     * 修改个人资料
     *
     * PUT /api/users/me
     */
    public function updateProfile(UpdateProfileRequest $request)
    {
        return Result::success('修改成功', $this->authService->updateProfile($request->user(), $request->validated()));
    }

    /**
     * 修改密码
     *
     * POST /api/users/me/password
     */
    public function updatePassword(UpdatePasswordRequest $request)
    {
        return Result::success('密码修改成功', $this->authService->updatePassword($request->user(), $request->validated()));
    }

    // ==================== 文件上传模块 ====================

    /**
     * 上传头像
     *
     * POST /api/upload/avatar
     */
    public function uploadAvatar(CGJRequest $request)
    {
        return Result::success('上传成功', $this->authService->uploadAvatar($request->user(), $request->file('file')));
    }

    /**
     * 上传帖子/共创图片
     *
     * POST /api/upload/post-image
     */
    public function uploadPostImage(CGJRequest $request)
    {
        return Result::success(
            '上传成功',
            $this->authService->uploadPostImage($request->user(), $request->file('file'), $request->input('folder'))
        );
    }

    // ==================== 传承基地模块 ====================

    /**
     * 获取传承基地列表
     *
     * GET /api/bases
     */
    public function baseList(CGJRequest $request)
    {
        return Result::success('获取成功', $this->authService->listBases(
            $request->validated('status'),
            $request->validated('region')
        ));
    }

    /**
     * 获取基地详情
     *
     * GET /api/bases/{id}
     */
    public function baseDetail(int $id)
    {
        return Result::success('获取成功', $this->authService->baseDetail($id));
    }

    /**
     * 获取附近基地
     *
     * GET /api/bases/nearby
     */
    public function baseNearby(CGJRequest $request)
    {
        return Result::success('获取成功', $this->authService->listNearbyBases(
            (float) $request->validated('latitude'),
            (float) $request->validated('longitude'),
            (float) $request->validated('radius', 50)
        ));
    }

    // ==================== 展览活动模块 ====================

    /**
     * 获取展览列表
     *
     * GET /api/events
     */
    public function eventList(CGJRequest $request)
    {
        ['page' => $page, 'size' => $pageSize] = Pagination::resolve($request->all());

        return Result::success('获取成功', $this->authService->listEvents(
            $request->validated('status'),
            $request->validated('month'),
            $page,
            $pageSize
        ));
    }

    /**
     * 获取展览详情
     *
     * GET /api/events/{id}
     */
    public function eventDetail(int $id)
    {
        return Result::success('获取成功', $this->authService->eventDetail($id));
    }

    /**
     * 生成日历文件
     *
     * GET /api/events/{id}/calendar
     */
    public function eventCalendar(int $id)
    {
        $ics = $this->authService->eventCalendar($id);

        return response($ics['content'], 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $ics['filename'] . '"',
        ]);
    }

    // ==================== 捐赠支持模块 ====================

    /**
     * 获取捐赠项目列表
     *
     * GET /api/donations/projects
     */
    public function donationProjects()
    {
        return Result::success('获取成功', $this->authService->listDonationProjects());
    }

    /**
     * 发起捐赠
     *
     * POST /api/donations
     */
    public function createDonation(CGJRequest $request)
    {
        return Result::success(
            '捐赠成功',
            $this->authService->createDonation($request->user(), $request->validated())
        );
    }

    /**
     * 获取我的捐赠记录
     *
     * GET /api/donations/records
     */
    public function donationRecords(CGJRequest $request)
    {
        ['page' => $page, 'size' => $pageSize] = Pagination::resolve($request->all());

        return Result::success('获取成功', $this->authService->listDonationRecords($request->user(), $page, $pageSize));
    }

    /**
     * 下载电子捐赠证书
     *
     * GET /api/donations/{id}/certificate
     */
    public function donationCertificate(CGJRequest $request, int $id)
    {
        $pdf = $this->authService->donationCertificate($request->user(), $id);

        return response($pdf['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $pdf['filename'] . '"',
        ]);
    }
}