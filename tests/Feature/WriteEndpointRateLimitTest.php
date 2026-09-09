<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use App\Models\DonationProject;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 高价值写入端点限流（票 #35）
 *
 * 登录仅计失败尝试（账号+IP 双维度）、注册按 IP、捐赠按用户；
 * 超阈值一律 429 + code 6 信封 + Retry-After。
 */
class WriteEndpointRateLimitTest extends TestCase
{
    use InteractsWithApi;

    /**
     * 同一账号连续登录失败超过阈值返回 429 + code 6
     */
    public function test_failed_logins_beyond_threshold_are_rate_limited(): void
    {
        config(['throttle.login.max' => 3, 'throttle.login.decay' => 60, 'throttle.login.ip_max' => 100]);
        $username = $this->uniqueUsername();

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'username' => $username,
                'password' => 'wrong-password',
            ]);
            $this->assertErrorEnvelope($response, ResponseCode::PASSWORD_ERROR->value);
        }

        $response = $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
        $this->assertSame(ResponseCode::TOO_MANY_REQUESTS->value, $response->json('code'));
        $this->assertNotEmpty($response->headers->get('Retry-After'));
    }

    /**
     * 换目标账号轮询仍受 IP 维度约束（撞库兜底）
     */
    public function test_rotating_target_accounts_is_still_ip_constrained(): void
    {
        config(['throttle.login.max' => 100, 'throttle.login.decay' => 60, 'throttle.login.ip_max' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'username' => $this->uniqueUsername('victim'),
                'password' => 'wrong-password',
            ]);
            $this->assertErrorEnvelope($response, ResponseCode::PASSWORD_ERROR->value);
        }

        // 单账号均未超阈值，但 IP 维度已耗尽
        $response = $this->postJson('/api/auth/login', [
            'username' => $this->uniqueUsername('victim'),
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
        $this->assertSame(ResponseCode::TOO_MANY_REQUESTS->value, $response->json('code'));
    }

    /**
     * 登录成功不消耗配额：阈值设为 1，连续成功登录不触发限流
     */
    public function test_successful_login_does_not_consume_quota(): void
    {
        config(['throttle.login.max' => 1, 'throttle.login.decay' => 60, 'throttle.login.ip_max' => 1]);
        ['username' => $username, 'password' => $password] = $this->registerUser();

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'username' => $username,
                'password' => $password,
            ]);
            $this->assertSuccessEnvelope($response);
        }
    }

    /**
     * 同一 IP 注册超过阈值返回 429 + code 6
     */
    public function test_register_beyond_threshold_is_rate_limited(): void
    {
        config(['throttle.auth-register.max' => 2, 'throttle.auth-register.decay' => 3600]);

        $this->registerUser();
        $this->registerUser();

        $response = $this->postJson('/api/auth/register', [
            'username' => $this->uniqueUsername(),
            'password' => 'password123',
            'nickname' => '测试用户',
        ]);

        $response->assertStatus(429);
        $this->assertSame(ResponseCode::TOO_MANY_REQUESTS->value, $response->json('code'));
    }

    /**
     * 同一用户捐赠创建超过阈值返回 429 + code 6，成功路径业务逻辑回归正常
     */
    public function test_donation_creation_beyond_threshold_is_rate_limited(): void
    {
        config(['throttle.donation.max' => 2, 'throttle.donation.decay' => 60]);
        $projectId = DonationProject::create([
            'title' => '测试捐赠项目',
            'description' => '用于限流测试的项目',
            'target_amount' => 10000,
        ])->id;
        ['token' => $token] = $this->registerAndLogin();

        foreach ([1, 2] as $i) {
            $response = $this->withToken($token)->postJson('/api/donations', [
                'projectId' => $projectId,
                'amount' => 100,
                'message' => "第 {$i} 次支持",
            ]);
            $this->assertSuccessEnvelope($response);
        }

        $response = $this->withToken($token)->postJson('/api/donations', [
            'projectId' => $projectId,
            'amount' => 100,
        ]);

        $response->assertStatus(429);
        $this->assertSame(ResponseCode::TOO_MANY_REQUESTS->value, $response->json('code'));

        // 回归：既有捐赠业务不受限流改造影响，前两笔捐赠可正常查询
        $records = $this->withToken($token)->getJson('/api/donations/records');
        $this->assertSuccessEnvelope($records);
        $this->assertSame(2, $records->json('data.total'));
        $this->assertCount(2, $records->json('data.list'));
    }
}
