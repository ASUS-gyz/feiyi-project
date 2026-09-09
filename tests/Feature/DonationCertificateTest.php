<?php

namespace Tests\Feature;

use App\Enums\ResponseCode;
use App\Models\Donation;
use App\Models\DonationProject;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * 捐赠证书 PDF 中文渲染（票 #52）
 *
 * dompdf 重写生成器后：本人下载得到中文可读的 PDF（中文字体以子集嵌入），
 * 下载契约（Content-Type/文件名）与权限矩阵（游客/他人/本人）保持不变。
 */
class DonationCertificateTest extends TestCase
{
    use InteractsWithApi;

    /**
     * 种一条已完成的捐赠（含项目快照）
     */
    private function seedDonation(int $userId, bool $anonymous = false): Donation
    {
        $project = DonationProject::create([
            'title' => '烧箔画抢救性记录',
            'description' => '为濒危烧箔技艺建立数字档案',
            'target_amount' => 100000,
        ]);

        return Donation::create([
            'donation_no' => 'DON'.str_pad((string) mt_rand(1, 99999999), 8, '0', STR_PAD_LEFT),
            'user_id' => $userId,
            'project_id' => $project->id,
            'project_title' => $project->title,
            'amount' => 66.6,
            'is_anonymous' => $anonymous,
            'status' => 'DONATION_COMPLETED',
        ]);
    }

    /**
     * 本人下载：application/pdf 附件、%PDF 魔数、中文字体已嵌入、体积非平凡
     */
    public function test_owner_downloads_pdf_with_embedded_cjk_font(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->registerAndLogin();
        $donation = $this->seedDonation((int) $userId);

        $response = $this->withToken($token)->get("/api/donations/{$donation->id}/certificate");

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition') ?? '');
        $this->assertStringContainsString('捐赠证书_', $response->headers->get('Content-Disposition') ?? '');
        $this->assertStringStartsWith('%PDF', $content, '响应体应是 PDF 文档');
        $this->assertGreaterThan(4000, strlen($content), '嵌入字体子集后体积应明显大于纯文本 PDF');
        $this->assertStringContainsString('FontFile2', $content, '中文 TrueType 字体程序应已嵌入');
        $this->assertStringContainsString('/BaseFont /SUB', $content, '嵌入字体应为子集化字体');
        $this->assertStringNotContainsString('/Helvetica', $content, '不应再退回无法编码 CJK 的 Type1 基础字体');
    }

    /**
     * 游客下载 → 20001 信封
     */
    public function test_guest_gets_unauthorized_envelope(): void
    {
        $donation = $this->seedDonation($this->registerUser()['userId']);

        $response = $this->getJson("/api/donations/{$donation->id}/certificate");

        $this->assertErrorEnvelope($response, ResponseCode::UNAUTHORIZED->value);
    }

    /**
     * 下载他人证书 → 20005 信封
     */
    public function test_non_owner_gets_forbidden_envelope(): void
    {
        $owner = $this->registerUser()['userId'];
        $donation = $this->seedDonation((int) $owner);

        ['token' => $strangerToken] = $this->registerAndLogin();

        $response = $this->withToken($strangerToken)->getJson("/api/donations/{$donation->id}/certificate");

        $this->assertErrorEnvelope($response, ResponseCode::FORBIDDEN->value);
    }

    /**
     * 匿名捐赠的证书同样生成 PDF（匿名称呼替代昵称）
     */
    public function test_anonymous_donation_still_renders_pdf(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->registerAndLogin();
        $donation = $this->seedDonation((int) $userId, anonymous: true);

        $response = $this->withToken($token)->get("/api/donations/{$donation->id}/certificate");

        $response->assertStatus(200);
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
