<?php

namespace App\Http\Controllers;

use App\Enums\ResponseCode;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\User\UpdatePasswordRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Services\AuthService;
use App\Support\Result;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        /** @var \App\Models\User|null $user */
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
        /** @var \App\Models\User|null $user */
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

    // ==================== 文件上传模块 ====================

    /**
     * 允许的图片 MIME 类型
     */
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * 上传头像
     *
     * POST /api/upload/avatar
     */
    public function uploadAvatar(Request $request)
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user) {
            return Result::error(ResponseCode::UNAUTHORIZED);
        }

        if (!$request->hasFile('file')) {
            return Result::error(ResponseCode::PARAM_MISSING, '请选择要上传的文件');
        }

        /** @var \Illuminate\Http\UploadedFile|null $file */
        $file = $request->file('file');

        // 验证文件类型
        if (!in_array($file->getMimeType(), self::ALLOWED_MIMES)) {
            return Result::error(ResponseCode::PARAM_INVALID, '仅支持 jpg/png/webp 格式的图片');
        }

        // 验证文件大小 (≤2MB)
        $maxSize = 2 * 1024 * 1024; // 2MB
        if ($file->getSize() > $maxSize) {
            return Result::error(ResponseCode::FILE_TOO_LARGE, '头像大小不能超过 2MB');
        }

        // 生成唯一文件名
        $extension = $file->getClientOriginalExtension();
        $filename = time() . '_' . Str::random(10) . '.' . $extension;

        // 存储到 storage/app/public/avatars/
        $path = $file->storeAs('avatars', $filename, 'public');

        if (!$path) {
            return Result::error(ResponseCode::SYSTEM_ERROR, '文件上传失败');
        }

        // 生成访问 URL
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        $url = $disk->url($path);

        // 更新用户头像
        $user->avatar = $url;
        $user->save();

        return Result::success('上传成功', [
            'url' => $url,
        ]);
    }

    /**
     * 上传帖子/共创图片
     *
     * POST /api/upload/post-image
     */
    public function uploadPostImage(Request $request)
    {
        if (!$request->hasFile('file')) {
            return Result::error(ResponseCode::PARAM_MISSING, '请选择要上传的文件');
        }

        // 验证 folder 参数
        $folder = $request->input('folder');
        if ($folder !== 'posts') {
            return Result::error(ResponseCode::PARAM_INVALID, 'folder 参数必须为 posts');
        }

        /** @var \Illuminate\Http\UploadedFile|null $file */
        $file = $request->file('file');

        // 验证文件类型
        if (!in_array($file->getMimeType(), self::ALLOWED_MIMES)) {
            return Result::error(ResponseCode::PARAM_INVALID, '仅支持 jpg/png/webp 格式的图片');
        }

        // 验证文件大小 (≤5MB)
        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file->getSize() > $maxSize) {
            return Result::error(ResponseCode::FILE_TOO_LARGE, '图片大小不能超过 5MB');
        }

        // 生成唯一文件名
        $extension = $file->getClientOriginalExtension();
        $filename = time() . '_' . Str::random(10) . '.' . $extension;

        // 存储到 storage/app/public/posts/
        $path = $file->storeAs('posts', $filename, 'public');

        if (!$path) {
            return Result::error(ResponseCode::SYSTEM_ERROR, '文件上传失败');
        }

        // 生成访问 URL
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        $url = $disk->url($path);

        return Result::success('上传成功', [
            'url' => $url,
            'filename' => $filename,
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
        ]);
    }
}