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
use App\Support\JWT;
use App\Support\Result;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
        $changedAt = now();
        $user->password = $validated['newPassword'];
        $user->pwd_changed_at = $changedAt;
        $user->save();

        // 改密使此前签发的所有 token 失效；同时签发新 token，当前设备无需重新登录。
        // iat 取改密秒 +1，确保续期 token 不被改密失效判定拦截
        $token = JWT::encode(['sub' => $user->id, 'iat' => $changedAt->getTimestamp() + 1]);

        return Result::success('密码修改成功', ['token' => $token]);
    }

    // ==================== 文件上传模块 ====================

    /**
     * 允许的图片 MIME 类型
     */
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * 允许的图片扩展名白名单（与 MIME 内容嗅探双重校验）
     */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * 校验上传图片的扩展名白名单、MIME 内容与大小
     *
     * 扩展名白名单在前：恶意扩展名（如 .php）确定性拒绝，不依赖内容嗅探。
     * 校验通过返回 null，失败返回对应的错误响应。
     */
    private function validateImageUpload(UploadedFile $file, int $maxSize, string $sizeMessage): ?JsonResponse
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return Result::error(ResponseCode::FILE_FORMAT_ERROR, '仅支持 jpg/png/webp 格式的图片');
        }

        // MIME 内容嗅探：拦截合法扩展名夹带非图片内容的文件
        if (!in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
            return Result::error(ResponseCode::PARAM_INVALID, '仅支持 jpg/png/webp 格式的图片');
        }

        if ($file->getSize() > $maxSize) {
            return Result::error(ResponseCode::FILE_TOO_LARGE, $sizeMessage);
        }

        return null;
    }

    /**
     * 服务端生成存储文件名：扩展名由已验证的 MIME 内容推导，客户端文件名不参与存储路径
     */
    private function generateImageFilename(UploadedFile $file): string
    {
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        return (string) Str::uuid() . '.' . $extension;
    }

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

        // 验证文件类型与大小（≤2MB）
        if ($error = $this->validateImageUpload($file, 2 * 1024 * 1024, '头像大小不能超过 2MB')) {
            return $error;
        }

        // 服务端生成文件名（客户端文件名不参与存储路径）
        $filename = $this->generateImageFilename($file);

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
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user) {
            return Result::error(ResponseCode::UNAUTHORIZED);
        }

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

        // 验证文件类型与大小（≤5MB）
        if ($error = $this->validateImageUpload($file, 5 * 1024 * 1024, '图片大小不能超过 5MB')) {
            return $error;
        }

        // 服务端生成文件名（客户端文件名不参与存储路径）
        $filename = $this->generateImageFilename($file);

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

        /** @var Donation $donation */
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