<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 限流基础设施（票 #33）
 *
 * 验证全站兜底限流：阈值内正常放行、超阈值 429 信封、阈值配置化生效。
 */
class RateLimitingTest extends TestCase
{
    use InteractsWithApi;

    /**
     * 阈值内的正常请求完全不受影响
     */
    public function test_requests_within_threshold_pass_with_normal_responses(): void
    {
        config(['throttle.global.max' => 5]);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->getJson('/api/auth/me');
            $this->assertErrorEnvelope($response, ResponseCode::UNAUTHORIZED->value);
        }
    }

    /**
     * 超过阈值返回 429 + 统一信封（code=6）+ Retry-After
     */
    public function test_exceeding_threshold_returns_429_envelope_with_retry_after(): void
    {
        config(['throttle.global.max' => 5, 'throttle.global.decay' => 60]);

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/auth/me');
        }

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(429);
        $this->assertSame(ResponseCode::TOO_MANY_REQUESTS->value, $response->json('code'));
        $this->assertFalse($response->json('success'));
        $this->assertNotEmpty($response->json('msg'));
        $this->assertNotEmpty($response->json('trace_id'));

        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(55, $retryAfter, 'Retry-After 应接近限流窗口剩余时间（秒）');
        $this->assertLessThanOrEqual(60, $retryAfter);
    }

    /**
     * 阈值配置化端到端生效：改配置即刻改变限流行为
     */
    public function test_threshold_is_configurable_and_takes_effect_immediately(): void
    {
        config(['throttle.global.max' => 1, 'throttle.global.decay' => 60]);

        $first = $this->getJson('/api/auth/me');
        $this->assertErrorEnvelope($first, ResponseCode::UNAUTHORIZED->value);

        $second = $this->getJson('/api/auth/me');
        $second->assertStatus(429);
        $this->assertSame(ResponseCode::TOO_MANY_REQUESTS->value, $second->json('code'));
    }
}
