<?php

namespace App\Services;

use App\Enums\ResponseCode;
use App\Exceptions\BusinessException;
use App\Models\Donation;
use App\Models\DonationProject;
use App\Models\User;
use App\Support\JWT;
use App\Support\TextSanitizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CGJService
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

    // ==================== 捐赠模块 ====================

    /**
     * 捐赠项目列表
     */
    public function listDonationProjects(): array
    {
        return DonationProject::active()->orderBy('id')->get()
            ->map(fn (DonationProject $project) => [
                'id' => $project->id,
                'title' => $project->title,
                'description' => $project->description,
                'targetAmount' => (float) $project->target_amount,
                'currentAmount' => (float) $project->current_amount,
                'supporterCount' => (int) $project->supporter_count,
                'image' => $project->image,
                'status' => $project->status,
            ])
            ->values()->toArray();
    }

    /**
     * 发起捐赠
     *
     * 事务内同时写捐赠记录与项目计数缓存，保证二者一致。
     *
     * @param array $input projectId / amount / isAnonymous / message
     * @return array
     *
     * @throws BusinessException
     */
    public function createDonation(User $user, array $input): array
    {
        $projectId = $input['projectId'] ?? null;
        $amount = $input['amount'] ?? null;

        if (!$projectId) {
            throw new BusinessException(ResponseCode::PARAM_MISSING, '请选择捐赠项目');
        }

        if (!$amount || !is_numeric($amount) || $amount < 10) {
            throw new BusinessException(ResponseCode::PARAM_INVALID, '捐赠金额不能低于 10 元');
        }

        /** @var DonationProject|null $project */
        $project = DonationProject::available()->find($projectId);

        if (!$project) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '捐赠项目不存在或已关闭');
        }

        // 金额上限检查（不超过目标金额的 10 倍）
        if ($amount > $project->target_amount * 10) {
            throw new BusinessException(ResponseCode::AMOUNT_LIMIT, '单次捐赠金额不能超过项目目标金额的 10 倍');
        }

        // 生成捐赠编号；捐赠留言净化（存储型 XSS 写侧防御）
        $donationNo = 'DON' . date('YmdHis') . strtoupper(Str::random(6));
        $message = TextSanitizer::clean($input['message'] ?? null);
        $isAnonymous = (bool) ($input['isAnonymous'] ?? false);

        try {
            $donation = DB::transaction(function () use ($user, $project, $donationNo, $amount, $isAnonymous, $message) {
                // 创建捐赠记录
                $donation = Donation::create([
                    'donation_no' => $donationNo,
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'project_title' => $project->title,
                    'amount' => $amount,
                    'is_anonymous' => $isAnonymous,
                    'message' => $message,
                    'status' => 'DONATION_COMPLETED',
                ]);

                // 更新项目计数缓存
                $project->increment('current_amount', $amount);
                $project->increment('supporter_count');

                return $donation;
            });
        } catch (\Throwable $e) {
            Log::channel('exception')->error('捐赠处理失败', [
                'trace_id' => request()->attributes->get('trace_id'),
                'user_id' => $user->id,
                'project_id' => $project->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw new BusinessException(ResponseCode::SYSTEM_ERROR, '捐赠处理失败，请稍后重试');
        }

        /** @var Donation $donation */
        return [
            'donationNo' => $donation->donation_no,
            'amount' => (float) $donation->amount,
            'status' => $donation->status,
            'certificateUrl' => $donation->certificate_url,
            'createdAt' => $donation->created_at->toIso8601String(),
        ];
    }

    /**
     * 我的捐赠记录（分页）
     */
    public function listDonationRecords(User $user, int $page, int $pageSize): array
    {
        $paginator = Donation::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(fn (Donation $donation) => [
            'donationNo' => $donation->donation_no,
            'projectId' => $donation->project_id,
            'projectTitle' => $donation->project_title,
            'amount' => (float) $donation->amount,
            'isAnonymous' => (bool) $donation->is_anonymous,
            'message' => $donation->message,
            'status' => $donation->status,
            'certificateUrl' => $donation->certificate_url,
            'createdAt' => $donation->created_at->toIso8601String(),
        ]);

        return [
            'list' => $list->values()->toArray(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    /**
     * 下载电子捐赠证书
     *
     * 权限：仅捐赠人本人（含匿名捐赠）。
     *
     * @return array{content: string, filename: string}
     *
     * @throws BusinessException
     */
    public function donationCertificate(User $user, int $donationId): array
    {
        /** @var Donation|null $donation */
        $donation = Donation::find($donationId);

        if (!$donation) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND);
        }

        // 检查权限：只能下载自己的证书
        if ($donation->user_id !== $user->id) {
            throw new BusinessException(ResponseCode::FORBIDDEN);
        }

        return [
            'content' => $this->generateDonationCertificatePdf($donation),
            'filename' => '捐赠证书_' . $donation->donation_no . '.pdf',
        ];
    }

    /**
     * 生成捐赠证书 PDF
     *
     * dompdf 渲染 HTML 模板；dompdf 不自动扫描字体目录，运行时显式登记
     * 库内中文字体（storage/fonts/simhei.ttf），开启子集化后仅嵌入用到的字形。
     */
    private function generateDonationCertificatePdf(Donation $donation): string
    {
        $nickname = $donation->is_anonymous ? '匿名爱心人士' : ($donation->user->nickname ?: $donation->user->username);

        $pdf = Pdf::setOption('enable_font_subsetting', true)->loadView('certificates.donation', [
            'donationNo'   => $donation->donation_no,
            'nickname'     => $nickname,
            'amount'       => number_format($donation->amount, 2),
            'projectTitle' => $donation->project_title,
            'date'         => $donation->created_at->format('Y年m月d日'),
        ]);

        $pdf->getDomPDF()->getFontMetrics()->registerFont(
            ['family' => 'simhei', 'style' => 'normal', 'weight' => 'normal'],
            storage_path('fonts/simhei.ttf')
        );

        return $pdf->output();
    }
}
