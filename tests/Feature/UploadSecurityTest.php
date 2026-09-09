<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 上传端点安全（票 #34）
 *
 * 验证：强制登录、扩展名白名单、服务端命名、按用户限流。
 */
class UploadSecurityTest extends TestCase
{
    use InteractsWithApi;

    /**
     * 最小合法 JPEG 内容（1x1 像素），供 finfo 内容嗅探识别
     */
    private const JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwcJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPDs0NDX/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function jpegFile(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::JPEG_BASE64));
    }

    /**
     * 未登录不能上传帖子图片，且不产生任何文件
     */
    public function test_guest_cannot_upload_post_image(): void
    {
        $response = $this->post('/api/upload/post-image', [
            'folder' => 'posts',
            'file' => $this->jpegFile('photo.jpg'),
        ]);

        $this->assertErrorEnvelope($response, ResponseCode::UNAUTHORIZED->value);
        $this->assertSame([], Storage::disk('public')->allFiles(), '未登录上传不应产生任何文件');
    }

    /**
     * 合法图片内容配恶意扩展名被拒绝，且不落盘
     */
    public function test_image_content_with_malicious_extension_is_rejected(): void
    {
        $token = $this->registerAndLogin()['token'];

        $response = $this->withToken($token)->post('/api/upload/post-image', [
            'folder' => 'posts',
            'file' => $this->jpegFile('shell.php'),
        ]);

        $this->assertErrorEnvelope($response, ResponseCode::FILE_FORMAT_ERROR->value);
        $this->assertSame([], Storage::disk('public')->allFiles(), '被拒绝的文件不应落盘');
    }

    /**
     * 合法图片上传成功，落盘文件名为服务端生成（不含客户端文件名任何部分）
     */
    public function test_legitimate_image_is_stored_with_server_generated_name(): void
    {
        $token = $this->registerAndLogin()['token'];

        $response = $this->withToken($token)->post('/api/upload/post-image', [
            'folder' => 'posts',
            'file' => $this->jpegFile('photo.jpg'),
        ]);

        $this->assertSuccessEnvelope($response);
        $this->assertNotEmpty($response->json('data.url'));

        $files = Storage::disk('public')->files('posts');
        $this->assertCount(1, $files);

        $filename = basename($files[0]);
        $this->assertStringEndsWith('.jpg', $filename);
        $this->assertStringNotContainsString('photo', $filename, '落盘文件名不应包含客户端文件名的任何部分');
    }

    /**
     * 头像上传回归正常（服务端命名同样生效）
     */
    public function test_avatar_upload_still_works_for_authenticated_user(): void
    {
        $token = $this->registerAndLogin()['token'];

        $response = $this->withToken($token)->post('/api/upload/avatar', [
            'file' => $this->jpegFile('me.jpg'),
        ]);

        $this->assertSuccessEnvelope($response);
        $this->assertNotEmpty($response->json('data.url'));

        $files = Storage::disk('public')->files('avatars');
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.jpg', basename($files[0]));
    }

    /**
     * 上传按用户限流
     */
    public function test_upload_is_rate_limited_per_user(): void
    {
        config(['throttle.upload.max' => 2, 'throttle.upload.decay' => 60]);
        $token = $this->registerAndLogin()['token'];

        $this->withToken($token)->post('/api/upload/post-image', [
            'folder' => 'posts',
            'file' => $this->jpegFile('a.jpg'),
        ]);
        $this->withToken($token)->post('/api/upload/post-image', [
            'folder' => 'posts',
            'file' => $this->jpegFile('b.jpg'),
        ]);

        $response = $this->withToken($token)->post('/api/upload/post-image', [
            'folder' => 'posts',
            'file' => $this->jpegFile('c.jpg'),
        ]);

        $response->assertStatus(429);
        $this->assertSame(ResponseCode::TOO_MANY_REQUESTS->value, $response->json('code'));
    }
}
