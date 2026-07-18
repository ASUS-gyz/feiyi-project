<?php

namespace App\Http\Controllers;

use App\Enums\ResponseCode;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\User\UpdatePasswordRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Models\Base;
use App\Models\Donation;
use App\Models\DonationProject;
use App\Models\Event;
use App\Models\EventSchedule;
use App\Services\AuthService;
use App\Support\Result;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    // ==================== 传承基地模块 ====================

    /**
     * 格式化基地数据（列表/详情通用）
     */
    private function formatBase(Base $base, ?float $distance = null): array
    {
        $data = [
            'id' => $base->id,
            'name' => $base->name,
            'location' => $base->location,
            'latitude' => (float) $base->latitude,
            'longitude' => (float) $base->longitude,
            'status' => $base->status,
            'bookingType' => $base->booking_type,
            'bookingValue' => $base->booking_value,
            'courses' => $base->courses,
            'images' => $base->images ?? [],
        ];

        if ($distance !== null) {
            $data['distance'] = round($distance, 1);
        }

        return $data;
    }

    /**
     * 格式化基地详情
     */
    private function formatBaseDetail(Base $base): array
    {
        return [
            'id' => $base->id,
            'name' => $base->name,
            'location' => $base->location,
            'latitude' => (float) $base->latitude,
            'longitude' => (float) $base->longitude,
            'status' => $base->status,
            'bookingType' => $base->booking_type,
            'bookingValue' => $base->booking_value,
            'courses' => $base->courses,
            'images' => $base->images ?? [],
            'description' => $base->description,
            'contact' => $base->contact,
            'phone' => $base->phone,
            'openingHours' => $base->opening_hours,
            'createdAt' => $base->created_at?->toDateString(),
            'updatedAt' => $base->updated_at?->toDateString(),
        ];
    }

    /**
     * 获取传承基地列表
     *
     * GET /api/bases
     */
    public function baseList(Request $request)
    {
        $query = Base::active();

        // 按状态筛选
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // 按地区筛选（location 字段模糊匹配）
        if ($request->filled('region')) {
            $query->where('location', 'like', '%' . $request->input('region') . '%');
        }

        $bases = $query->get();

        return Result::success('获取成功', $bases->map(function ($base) {
            return $this->formatBase($base);
        })->values()->toArray());
    }

    /**
     * 获取基地详情
     *
     * GET /api/bases/{id}
     */
    public function baseDetail(int $id)
    {
        /** @var Base|null $base */
        $base = Base::active()->find($id);

        if (!$base) {
            return Result::error(ResponseCode::DATA_NOT_FOUND);
        }

        return Result::success('获取成功', $this->formatBaseDetail($base));
    }

    /**
     * 获取附近基地
     *
     * GET /api/bases/nearby
     */
    public function baseNearby(Request $request)
    {
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $radius = (float) ($request->input('radius', 50));

        if (!$latitude || !$longitude) {
            return Result::error(ResponseCode::PARAM_MISSING, '请提供经纬度参数');
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        // 使用 Haversine 公式计算距离并筛选
        $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))";

        $bases = Base::active()
            ->select('*')
            ->selectRaw("{$haversine} AS distance", [$latitude, $longitude, $latitude])
            ->having('distance', '<=', $radius)
            ->orderBy('distance')
            ->get();

        return Result::success('获取成功', $bases->map(function ($base) {
            return $this->formatBase($base, (float) $base->distance);
        })->values()->toArray());
    }

    // ==================== 展览活动模块 ====================

    /**
     * 获取展览列表
     *
     * GET /api/events
     */
    public function eventList(Request $request)
    {
        $query = Event::active();

        // 按状态筛选
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // 按月份筛选
        if ($request->filled('month')) {
            $month = $request->input('month');
            $query->where(function ($q) use ($month) {
                $q->where('start_date', 'like', $month . '%')
                  ->orWhere('end_date', 'like', $month . '%');
            });
        }

        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('pageSize', 20);

        $paginator = $query->orderBy('start_date')->paginate($pageSize, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(function ($event) {
            /** @var Event $event */
            return [
                'id' => $event->id,
                'title' => $event->title,
                'location' => $event->location,
                'description' => $event->description,
                'startDate' => $event->start_date->toDateString(),
                'endDate' => $event->end_date->toDateString(),
                'status' => $event->status,
            ];
        });

        return Result::success('获取成功', [
            'list' => $list->values()->toArray(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ]);
    }

    /**
     * 获取展览详情
     *
     * GET /api/events/{id}
     */
    public function eventDetail(int $id)
    {
        /** @var Event|null $event */
        $event = Event::active()->with(['schedules' => function ($q) {
            $q->active()->orderBy('date');
        }])->find($id);

        if (!$event) {
            return Result::error(ResponseCode::DATA_NOT_FOUND);
        }

        return Result::success('获取成功', [
            'id' => $event->id,
            'title' => $event->title,
            'location' => $event->location,
            'description' => $event->description,
            'startDate' => $event->start_date->toDateString(),
            'endDate' => $event->end_date->toDateString(),
            'status' => $event->status,
            'schedule' => $event->schedules->map(function ($schedule) {
                return [
                    'date' => $schedule->date->toDateString(),
                    'event' => $schedule->event,
                ];
            })->values()->toArray(),
        ]);
    }

    /**
     * 生成日历文件
     *
     * GET /api/events/{id}/calendar
     */
    public function eventCalendar(int $id)
    {
        /** @var Event|null $event */
        $event = Event::active()->find($id);

        if (!$event) {
            return Result::error(ResponseCode::DATA_NOT_FOUND);
        }

        // 生成 .ics 内容
        $uid = 'event-' . $event->id . '@feiyi';
        $dtStart = $event->start_date->format('Ymd');
        // iCalendar 的 DTEND 为独占结束日期，所以 +1 天
        $dtEnd = $event->end_date->copy()->addDay()->format('Ymd');
        $dtStamp = now()->format('Ymd\THis\Z');
        $summary = $this->escapeIcsText($event->title);
        $description = $this->escapeIcsText($event->description ?? '');
        $location = $this->escapeIcsText($event->location);

        $ics = "BEGIN:VCALENDAR\r\n"
              ."VERSION:2.0\r\n"
              ."PRODID:-//Feiyi//Events//CN\r\n"
              ."BEGIN:VEVENT\r\n"
              ."UID:{$uid}\r\n"
              ."DTSTAMP:{$dtStamp}\r\n"
              ."DTSTART;VALUE=DATE:{$dtStart}\r\n"
              ."DTEND;VALUE=DATE:{$dtEnd}\r\n"
              ."SUMMARY:{$summary}\r\n"
              ."DESCRIPTION:{$description}\r\n"
              ."LOCATION:{$location}\r\n"
              ."END:VEVENT\r\n"
              ."END:VCALENDAR\r\n";

        $filename = $event->title . '.ics';

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * 转义 .ics 文本中的特殊字符
     */
    private function escapeIcsText(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\\;', '\\,', '\\n'],
            $text
        );
    }

    // ==================== 捐赠支持模块 ====================

    /**
     * 获取捐赠项目列表
     *
     * GET /api/donations/projects
     */
    public function donationProjects()
    {
        $projects = DonationProject::active()->orderBy('id')->get();

        return Result::success('获取成功', $projects->map(function ($project) {
            return [
                'id' => $project->id,
                'title' => $project->title,
                'description' => $project->description,
                'targetAmount' => (float) $project->target_amount,
                'currentAmount' => (float) $project->current_amount,
                'supporterCount' => (int) $project->supporter_count,
                'image' => $project->image,
                'status' => $project->status,
            ];
        })->values()->toArray());
    }

    /**
     * 发起捐赠
     *
     * POST /api/donations
     */
    public function createDonation(Request $request)
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user) {
            return Result::error(ResponseCode::UNAUTHORIZED);
        }

        $projectId = $request->input('projectId');
        $amount = $request->input('amount');
        $isAnonymous = (bool) $request->input('isAnonymous', false);
        $message = $request->input('message');

        // 验证参数
        if (!$projectId) {
            return Result::error(ResponseCode::PARAM_MISSING, '请选择捐赠项目');
        }

        if (!$amount || !is_numeric($amount) || $amount < 10) {
            return Result::error(ResponseCode::PARAM_INVALID, '捐赠金额不能低于 10 元');
        }

        // 检查项目
        /** @var DonationProject|null $project */
        $project = DonationProject::available()->find($projectId);

        if (!$project) {
            return Result::error(ResponseCode::DATA_NOT_FOUND, '捐赠项目不存在或已关闭');
        }

        // 金额上限检查（不超过目标金额的 10 倍）
        if ($amount > $project->target_amount * 10) {
            return Result::error(ResponseCode::AMOUNT_LIMIT, '单次捐赠金额不能超过项目目标金额的 10 倍');
        }

        // 生成捐赠编号
        $donationNo = 'DON' . date('YmdHis') . strtoupper(Str::random(6));

        // 使用事务写入
        try {
            DB::transaction(function () use ($user, $project, $donationNo, $amount, $isAnonymous, $message, &$donation) {
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
            });
        } catch (\Exception $e) {
            return Result::error(ResponseCode::SYSTEM_ERROR, '捐赠处理失败，请稍后重试');
        }

        return Result::success('捐赠成功', [
            'donationNo' => $donation->donation_no,
            'amount' => (float) $donation->amount,
            'status' => $donation->status,
            'certificateUrl' => $donation->certificate_url,
            'createdAt' => $donation->created_at->toIso8601String(),
        ]);
    }

    /**
     * 获取我的捐赠记录
     *
     * GET /api/donations/records
     */
    public function donationRecords(Request $request)
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user) {
            return Result::error(ResponseCode::UNAUTHORIZED);
        }

        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('pageSize', 20);

        $paginator = Donation::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(function ($donation) {
            /** @var Donation $donation */
            return [
                'donationNo' => $donation->donation_no,
                'projectId' => $donation->project_id,
                'projectTitle' => $donation->project_title,
                'amount' => (float) $donation->amount,
                'isAnonymous' => (bool) $donation->is_anonymous,
                'message' => $donation->message,
                'status' => $donation->status,
                'certificateUrl' => $donation->certificate_url,
                'createdAt' => $donation->created_at->toIso8601String(),
            ];
        });

        return Result::success('获取成功', [
            'list' => $list->values()->toArray(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ]);
    }

    /**
     * 下载电子捐赠证书
     *
     * GET /api/donations/{id}/certificate
     */
    public function donationCertificate(int $id)
    {
        /** @var \App\Models\User|null $user */
        $user = request()->user();

        if (!$user) {
            return Result::error(ResponseCode::UNAUTHORIZED);
        }

        /** @var Donation|null $donation */
        $donation = Donation::find($id);

        if (!$donation) {
            return Result::error(ResponseCode::DATA_NOT_FOUND);
        }

        // 检查权限：只能下载自己的证书
        if ($donation->user_id !== $user->id) {
            return Result::error(ResponseCode::FORBIDDEN);
        }

        // 生成 PDF 证书
        $pdfContent = $this->generateDonationCertificatePdf($donation);

        $filename = '捐赠证书_' . $donation->donation_no . '.pdf';

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * 生成捐赠证书 PDF
     */
    private function generateDonationCertificatePdf(Donation $donation): string
    {
        $donationNo = $donation->donation_no;
        $nickname = $donation->is_anonymous ? '匿名爱心人士' : ($donation->user->nickname ?: $donation->user->username);
        $amount = number_format($donation->amount, 2);
        $projectTitle = $donation->project_title;
        $date = $donation->created_at->format('Y年m月d日');

        // 使用 PDF 内容块构建
        $objects = [];
        $objectCount = 0;

        // 1. Catalog
        $objects[++$objectCount] = "<< /Type /Catalog /Pages 2 0 R >>";
        // 2. Pages
        $objects[++$objectCount] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        // 3. Page
        $objects[++$objectCount] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>";

        // 4. Content stream
        $content = $this->buildCertificateContent($donationNo, $nickname, $amount, $projectTitle, $date);
        $contentLen = strlen($content);
        $objects[++$objectCount] = "<< /Length {$contentLen} >>\nstream\n{$content}\nendstream";

        // 5. Font - Helvetica-Bold for title
        $objects[++$objectCount] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
        // 6. Font - Helvetica for body
        $objects[++$objectCount] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

        $totalObjects = $objectCount;
        $offsets = [];
        $pdf = "%PDF-1.4\n";

        // Write objects
        for ($i = 1; $i <= $totalObjects; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "{$i} 0 obj\n{$objects[$i]}\nendobj\n";
        }

        // Cross-reference table
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . ($totalObjects + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $totalObjects; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        // Trailer
        $pdf .= "trailer\n<< /Size " . ($totalObjects + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    /**
     * 构建证书 PDF 页面内容
     */
    private function buildCertificateContent(
        string $donationNo,
        string $nickname,
        string $amount,
        string $projectTitle,
        string $date
    ): string {
        $lines = [];

        // 标题
        $lines[] = "BT /F1 36 Tf 50 750 Td (\xE6\x8D\x90\xE8\xB5\xA0\xE8\xAF\x81\xE4\xB9\xA6) Tj ET";
        // 装饰线
        $lines[] = "BT 0.8 w 50 720 495 0 re S ET";
        // 证书编号
        $lines[] = "BT /F2 10 Tf 50 690 Td (\xE8\xAF\x81\xE4\xB9\xA6\xE7\xBC\x96\xE5\x8F\xB7\xEF\xBC\x9A{$donationNo}) Tj ET";
        // 空行
        // 正文
        $lines[] = "BT /F2 16 Tf 50 640 Td (\xE6\x81\xAD\xE5\x96\x9C\xEF\xBC\x9A{$nickname}) Tj ET";
        $lines[] = "BT /F2 14 Tf 50 600 Td (\xE6\x82\xA8\xE5\x90\x91\xE3\x80\x8C{$projectTitle}\xE3\x80\x8D\xE9\xA1\xB9\xE7\x9B\xAE\xE6\x8D\x90\xE8\xB5\xA0\xE4\xBA\x86) Tj ET";
        $lines[] = "BT /F1 24 Tf 50 560 Td ({$amount}\xE5\x85\x83) Tj ET";
        $lines[] = "BT /F2 14 Tf 50 520 Td (\xE6\x88\x90\xE4\xB8\xBA\xE4\xBA\x86\xE9\x9D\x9E\xE9\x81\x97\xE4\xBF\x9D\xE6\x8A\xA4\xE4\xB8\x8E\xE4\xBC\xA0\xE6\x89\xBF\xE7\x9A\x84\xE6\x94\xAF\xE6\x8C\x81\xE8\x80\x85\xE3\x80\x82) Tj ET";
        $lines[] = "BT /F2 14 Tf 50 480 Td (\xE6\x88\x91\xE4\xBB\xAC\xE5\xB0\x86\xE4\xB8\x8E\xE6\x82\xA8\xE4\xB8\x80\xE8\xB5\xB7\xEF\xBC\x8C\xE5\x85\xB1\xE5\x90\x8C\xE5\xAE\x88\xE6\x8A\xA4\xE8\xBF\x99\xE4\xBB\xBD\xE5\x8D\x83\xE5\xB9\xB4\xE5\xB7\xA5\xE8\x89\xBA\xE3\x80\x82) Tj ET";
        // 日期
        $lines[] = "BT /F2 12 Tf 50 420 Td (\xE9\xA2\x81\xE5\x8F\x91\xE6\x97\xA5\xE6\x9C\x9F\xEF\xBC\x9A{$date}) Tj ET";
        // 机构名
        $lines[] = "BT /F2 12 Tf 350 380 Td (\xE7\x84\x99\xE7\xAE\x94\xE5\x87\x9D\xE8\x89\xBA\xC2\xB7\xE9\x9D\x9E\xE9\x81\x97\xE4\xBF\x9D\xE6\x8A\xA4\xE6\x9C\xBA\xE6\x9E\x84) Tj ET";
        // 底部
        $lines[] = "BT /F2 8 Tf 50 50 Td (\xE6\x9C\xAC\xE8\xAF\x81\xE4\xB9\xA6\xE4\xBB\x85\xE4\xB8\xBA\xE6\x84\x9F\xE8\xB0\xA2\xE6\x82\xA8\xE7\x9A\x84\xE6\x8D\x90\xE8\xB5\xA0\xEF\xBC\x8C\xE4\xB8\x8D\xE4\xBD\x9C\xE4\xB8\xBA\xE4\xBB\xBB\xE4\xBD\x95\xE6\x94\xB6\xE6\x8D\xAE\xE6\x88\x96\xE7\xA8\x8E\xE5\x8A\xA1\xE5\x87\xAD\xE8\xAF\x81\xE3\x80\x82) Tj ET";

        return implode("\n", $lines);
    }
}