<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API 请求日志中间件（票 #89）
 *
 * 任意请求写 api 通道结构化条目（url/method/耗时/状态码/trace_id）；
 * password 类敏感字段脱敏；上传文件只记元数据不落二进制。
 */
class ApiLogMiddlewareTest extends TestCase
{
    use InteractsWithApi;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        // 将 api 通道指向测试专用日志文件，避免污染真实日志
        $this->logPath = storage_path('logs/testing-api.log');
        @unlink($this->logPath);

        config(['logging.channels.api' => [
            'driver' => 'single',
            'path' => $this->logPath,
            'level' => 'info',
        ]]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);

        parent::tearDown();
    }

    private function logContent(): string
    {
        return (string) @file_get_contents($this->logPath);
    }

    /**
     * 任意请求（含未登录被拒的）都产生结构化 API 日志
     */
    public function test_every_request_is_logged_to_api_channel(): void
    {
        $response = $this->getJson('/api/auth/me');

        // 统一信封：未登录也是 HTTP 200（code=20001），中间件照常记录
        $response->assertStatus(200);

        $log = $this->logContent();
        $this->assertStringContainsString('API请求记录', $log);
        $this->assertStringContainsString('"method":"GET"', $log);
        $this->assertStringContainsString('duration_ms', $log);
        $this->assertStringContainsString('trace_id', $log);
        $this->assertStringContainsString('"response_status":200', $log);
    }

    /**
     * 登录请求的密码不入日志（脱敏为 ***）
     */
    public function test_password_is_never_logged(): void
    {
        $this->postJson('/api/auth/login', [
            'username' => 'log_probe_user',
            'password' => 'super_secret_123',
        ]);

        $log = $this->logContent();
        $this->assertStringContainsString('log_probe_user', $log, '普通字段应照常记录');
        $this->assertStringNotContainsString('super_secret_123', $log, '密码绝不能落日志');
        $this->assertStringContainsString('***', $log);
    }

    /**
     * 文件上传只记元数据（文件名/大小），二进制内容不落日志
     */
    public function test_uploaded_file_is_logged_as_metadata_only(): void
    {
        $this->post('/api/upload/post-image', [
            'folder' => 'posts',
            'file' => UploadedFile::fake()->createWithContent('probe.jpg', 'BINARYJPEGCONTENT'),
        ]);

        $log = $this->logContent();
        $this->assertStringContainsString('probe.jpg', $log, '文件名元数据应记录');
        $this->assertStringNotContainsString('BINARYJPEGCONTENT', $log, '二进制内容不能落日志');
    }
}
