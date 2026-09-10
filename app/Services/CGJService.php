<?php

namespace App\Services;

use App\Enums\ResponseCode;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Support\JWT;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * 用户注册
     *
     * @param array $data
     * @return User
     */
    public function register(array $data): User
    {
        $userData = [
            'name' => $data['username'],
            'password' => Hash::make($data['password']),
            'nickname' => $data['nickname'] ?? null,
        ];

        // email 字段在数据库中是 NOT NULL，仅在传入时才设置
        if (!empty($data['email'])) {
            $userData['email'] = $data['email'];
        }

        $user = User::create($userData);

        return $user;
    }

    /**
     * 用户登录
     *
     * @param array $data
     * @return array{user: User, token: string}
     *
     * @throws BusinessException
     */
    public function login(array $data): array
    {
        $user = User::where('name', $data['username'])->first();

        if (!$user) {
            throw new BusinessException(ResponseCode::PASSWORD_ERROR, '账号或密码错误');
        }

        if ($user->is_deleted) {
            throw new BusinessException(ResponseCode::UNAUTHORIZED, '账号已被禁用');
        }

        if (!Hash::check($data['password'], $user->password)) {
            throw new BusinessException(ResponseCode::PASSWORD_ERROR, '账号或密码错误');
        }

        $token = JWT::encode([
            'sub' => $user->id,
            'username' => $user->name,
        ]);

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    /**
     * 格式化用户数据（API 响应格式）
     *
     * @param User $user
     * @return array
     */
    public function formatUser(User $user): array
    {
        return [
            'userId' => $user->id,
            'username' => $user->name,
            'nickname' => $user->nickname,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'role' => $user->role ?? 'USER',
            'bio' => $user->bio,
            'region' => $user->region,
            'createdAt' => $user->created_at?->toIso8601String(),
            'updatedAt' => $user->updated_at?->toIso8601String(),
        ];
    }
}
