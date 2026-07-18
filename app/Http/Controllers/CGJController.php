<?php

namespace App\Http\Controllers;

use App\Enums\ResponseCode;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\User\UpdatePasswordRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Services\AuthService;
use App\Support\Result;
use Illuminate\Support\Facades\Hash;

class CGJController extends Controller
{
    public function __construct(
        protected AuthService $authService
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
        $user = $request->user();

        if (!$user) {
            return Result::error(ResponseCode::UNAUTHORIZED);
        }

        $validated = $request->validated();

        $fields = ['nickname', 'email', 'bio', 'region'];
        $changed = false;

        foreach ($fields as $field) {
            if (array_key_exists($field, $validated)) {
                $user->$field = $validated[$field];
                $changed = true;
            }
        }

        if ($changed) {
            $user->save();
        }

        return Result::success('修改成功', $this->authService->formatUser($user->fresh()));
    }

    /**
     * 修改密码
     *
     * POST /api/users/me/password
     */
    public function updatePassword(UpdatePasswordRequest $request)
    {
        $user = $request->user();

        if (!$user) {
            return Result::error(ResponseCode::UNAUTHORIZED);
        }

        $validated = $request->validated();

        // 验证旧密码
        if (!Hash::check($validated['oldPassword'], $user->password)) {
            return Result::error(ResponseCode::PASSWORD_ERROR);
        }

        // 更新密码（User 模型的 casts 已配置 password => hashed，自动哈希）
        $user->password = $validated['newPassword'];
        $user->save();

        return Result::success('密码修改成功');
    }
}