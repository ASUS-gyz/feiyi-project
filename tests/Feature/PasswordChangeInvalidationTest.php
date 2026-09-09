<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use App\Models\User;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 改密失效（票 #44）
 *
 * 修改密码后此前签发的 token 全部按"登录已过期"（20004）拒绝；
 * 改密响应携带新 token，当前设备无缝续期；改密失败不影响任何 token 有效性。
 */
class PasswordChangeInvalidationTest extends TestCase
{
    use InteractsWithApi;

    /**
     * 改密后旧 token 按 20004 拒绝，响应中的新 token 立即可用
     */
    public function test_old_token_rejected_and_new_token_works_after_password_change(): void
    {
        ['token' => $oldToken, 'userId' => $userId] = $this->registerAndLogin();

        $change = $this->withToken($oldToken)->postJson('/api/users/me/password', [
            'oldPassword' => 'password123',
            'newPassword' => 'newpassword456',
        ]);

        $this->assertSuccessEnvelope($change);
        $newToken = $change->json('data.token');
        $this->assertNotEmpty($newToken, '改密响应应携带新 token');

        // 旧 token 失效 → 20004
        $oldAccess = $this->withToken($oldToken)->getJson('/api/auth/me');
        $this->assertErrorEnvelope($oldAccess, ResponseCode::TOKEN_EXPIRED->value);

        // 新 token 立即可用
        $newAccess = $this->withToken($newToken)->getJson('/api/auth/me');
        $this->assertSuccessEnvelope($newAccess);

        $this->assertNotNull(User::firstWhere('id', $userId)->pwd_changed_at);
    }

    /**
     * 旧密码错误：改密失败（20008），pwd_changed_at 不变，token 仍有效
     */
    public function test_wrong_old_password_keeps_tokens_valid(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->registerAndLogin();

        $change = $this->withToken($token)->postJson('/api/users/me/password', [
            'oldPassword' => 'wrong-password',
            'newPassword' => 'newpassword456',
        ]);

        $this->assertErrorEnvelope($change, ResponseCode::PASSWORD_ERROR->value);

        $this->assertNull(User::firstWhere('id', $userId)->pwd_changed_at, '改密失败不应写入改密时间');

        $access = $this->withToken($token)->getJson('/api/auth/me');
        $this->assertSuccessEnvelope($access);
    }

    /**
     * 用户 A 改密只影响 A 自己的 token，不影响用户 B
     */
    public function test_password_change_does_not_affect_other_users(): void
    {
        $userA = $this->registerAndLogin();
        $userB = $this->registerAndLogin();

        $this->withToken($userA['token'])->postJson('/api/users/me/password', [
            'oldPassword' => 'password123',
            'newPassword' => 'newpassword456',
        ]);

        $bAccess = $this->withToken($userB['token'])->getJson('/api/auth/me');
        $this->assertSuccessEnvelope($bAccess);

        $aOldAccess = $this->withToken($userA['token'])->getJson('/api/auth/me');
        $this->assertErrorEnvelope($aOldAccess, ResponseCode::TOKEN_EXPIRED->value);
    }

    /**
     * 回归：从未改密的用户 token 正常使用（pwd_changed_at 为 null，平滑过渡）
     */
    public function test_never_changed_password_user_token_valid(): void
    {
        ['token' => $token] = $this->registerAndLogin();

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $this->assertSuccessEnvelope($response);
    }
}
