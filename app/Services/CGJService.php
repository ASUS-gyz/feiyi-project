<?php

namespace App\Services;

use App\Enums\ResponseCode;
use App\Exceptions\BusinessException;
use App\Models\Base;
use App\Models\Donation;
use App\Models\DonationProject;
use App\Models\Event;
use App\Models\User;
use App\Support\JWT;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        // 结构性参数校验（必填/数值/下限）已归位 CGJRequest，此处只做业务规则
        $projectId = $input['projectId'] ?? null;
        $amount = (float) ($input['amount'] ?? 0);

        /** @var DonationProject|null $project */
        $project = DonationProject::available()->find($projectId);

        if (!$project) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND, '捐赠项目不存在或已关闭');
        }

        // 金额上限检查（不超过目标金额的 10 倍）
        if ($amount > $project->target_amount * 10) {
            throw new BusinessException(ResponseCode::AMOUNT_LIMIT, '单次捐赠金额不能超过项目目标金额的 10 倍');
        }

        // 生成捐赠编号；留言已在 CGJRequest 验证前净化
        $donationNo = 'DON' . date('YmdHis') . strtoupper(Str::random(6));
        $message = $input['message'] ?? null;
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

    // ==================== 用户资料模块 ====================

    /**
     * 修改个人资料
     *
     * @param array $validated nickname / email / bio / region（可选字段）
     * @return array
     */
    public function updateProfile(User $user, array $validated): array
    {
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

        return $this->formatUser($user->fresh());
    }

    /**
     * 修改密码
     *
     * 改密使此前签发的所有 token 失效；同时签发新 token，当前设备无需重新登录。
     * iat 取改密秒 +1，确保续期 token 不被改密失效判定拦截。
     *
     * @param array $validated oldPassword / newPassword
     * @return array
     *
     * @throws BusinessException
     */
    public function updatePassword(User $user, array $validated): array
    {
        // 验证旧密码
        if (!Hash::check($validated['oldPassword'], $user->password)) {
            throw new BusinessException(ResponseCode::PASSWORD_ERROR);
        }

        // 更新密码（User 模型的 casts 已配置 password => hashed，自动哈希）
        $changedAt = now();
        $user->password = $validated['newPassword'];
        $user->pwd_changed_at = $changedAt;
        $user->save();

        $token = JWT::encode(['sub' => $user->id, 'iat' => $changedAt->getTimestamp() + 1]);

        return ['token' => $token];
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
     * 上传头像
     *
     * @param UploadedFile|null $file
     * @return array
     *
     * @throws BusinessException
     */
    public function uploadAvatar(User $user, ?UploadedFile $file): array
    {
        if (!$file) {
            throw new BusinessException(ResponseCode::PARAM_MISSING, '请选择要上传的文件');
        }

        // 验证文件类型与大小（≤2MB）
        $this->validateImageUpload($file, 2 * 1024 * 1024, '头像大小不能超过 2MB');

        $url = $this->storeImage($file, 'avatars');

        // 更新用户头像
        $user->avatar = $url;
        $user->save();

        return ['url' => $url];
    }

    /**
     * 上传帖子/共创图片
     *
     * @param UploadedFile|null $file
     * @return array
     *
     * @throws BusinessException
     */
    public function uploadPostImage(User $user, ?UploadedFile $file, ?string $folder): array
    {
        if (!$file) {
            throw new BusinessException(ResponseCode::PARAM_MISSING, '请选择要上传的文件');
        }

        if ($folder !== 'posts') {
            throw new BusinessException(ResponseCode::PARAM_INVALID, 'folder 参数必须为 posts');
        }

        // 验证文件类型与大小（≤5MB）
        $this->validateImageUpload($file, 5 * 1024 * 1024, '图片大小不能超过 5MB');

        // 服务端生成文件名（客户端文件名不参与存储路径）
        $filename = $this->generateImageFilename($file);

        // 存储到 storage/app/public/posts/
        $path = $file->storeAs('posts', $filename, 'public');

        if (!$path) {
            throw new BusinessException(ResponseCode::SYSTEM_ERROR, '文件上传失败');
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        return [
            'url' => $disk->url($path),
            'filename' => $filename,
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
        ];
    }

    /**
     * 校验上传图片的扩展名白名单、MIME 内容与大小
     *
     * 扩展名白名单在前：恶意扩展名（如 .php）确定性拒绝，不依赖内容嗅探。
     *
     * @throws BusinessException
     */
    private function validateImageUpload(UploadedFile $file, int $maxSize, string $sizeMessage): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new BusinessException(ResponseCode::FILE_FORMAT_ERROR, '仅支持 jpg/png/webp 格式的图片');
        }

        // MIME 内容嗅探：拦截合法扩展名夹带非图片内容的文件
        if (!in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
            throw new BusinessException(ResponseCode::PARAM_INVALID, '仅支持 jpg/png/webp 格式的图片');
        }

        if ($file->getSize() > $maxSize) {
            throw new BusinessException(ResponseCode::FILE_TOO_LARGE, $sizeMessage);
        }
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
     * 存储头像文件并生成访问 URL，同时更新用户头像字段
     */
    private function storeImage(UploadedFile $file, string $folder): string
    {
        $filename = $this->generateImageFilename($file);

        // 存储到 storage/app/public/{folder}/
        $path = $file->storeAs($folder, $filename, 'public');

        if (!$path) {
            throw new BusinessException(ResponseCode::SYSTEM_ERROR, '文件上传失败');
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        $url = $disk->url($path);

        return $url;
    }

    // ==================== 传承基地模块 ====================

    /**
     * 获取传承基地列表
     *
     * @param string|null $status 状态筛选
     * @param string|null $region 地区筛选（location 模糊匹配）
     */
    public function listBases(?string $status, ?string $region): array
    {
        $query = Base::active();

        if ($status) {
            $query->where('status', $status);
        }

        if ($region) {
            $query->where('location', 'like', '%' . $region . '%');
        }

        return $query->get()
            ->map(fn (Base $base) => $this->formatBase($base))
            ->values()->toArray();
    }

    /**
     * 获取基地详情
     *
     * @throws BusinessException
     */
    public function baseDetail(int $id): array
    {
        /** @var Base|null $base */
        $base = Base::active()->find($id);

        if (!$base) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND);
        }

        return $this->formatBaseDetail($base);
    }

    /**
     * 获取附近基地（Haversine 公式计算距离）
     *
     * 经纬度必填与取值范围校验已归位 CGJRequest
     */
    public function listNearbyBases(float $latitude, float $longitude, float $radius = 50): array
    {
        $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))";

        $bases = Base::active()
            ->select('*')
            ->selectRaw("{$haversine} AS distance", [$latitude, $longitude, $latitude])
            ->having('distance', '<=', $radius)
            ->orderBy('distance')
            ->get();

        return $bases->map(fn (Base $base) => $this->formatBase($base, (float) $base->distance))
            ->values()->toArray();
    }

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

    // ==================== 展览活动模块 ====================

    /**
     * 获取展览列表（分页）
     *
     * @param string|null $status 状态筛选
     * @param string|null $month 月份筛选（start_date/end_date 前缀匹配）
     */
    public function listEvents(?string $status, ?string $month, int $page, int $pageSize): array
    {
        $query = Event::active();

        if ($status) {
            $query->where('status', $status);
        }

        if ($month) {
            $query->where(function ($q) use ($month) {
                $q->where('start_date', 'like', $month . '%')
                  ->orWhere('end_date', 'like', $month . '%');
            });
        }

        $paginator = $query->orderBy('start_date')->paginate($pageSize, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(fn (Event $event) => [
            'id' => $event->id,
            'title' => $event->title,
            'location' => $event->location,
            'description' => $event->description,
            'startDate' => $event->start_date->toDateString(),
            'endDate' => $event->end_date->toDateString(),
            'status' => $event->status,
        ]);

        return [
            'list' => $list->values()->toArray(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ];
    }

    /**
     * 获取展览详情（含日程）
     *
     * @throws BusinessException
     */
    public function eventDetail(int $id): array
    {
        /** @var Event|null $event */
        $event = Event::active()->with(['schedules' => function ($q) {
            $q->active()->orderBy('date');
        }])->find($id);

        if (!$event) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND);
        }

        return [
            'id' => $event->id,
            'title' => $event->title,
            'location' => $event->location,
            'description' => $event->description,
            'startDate' => $event->start_date->toDateString(),
            'endDate' => $event->end_date->toDateString(),
            'status' => $event->status,
            'schedule' => $event->schedules->map(fn ($schedule) => [
                'date' => $schedule->date->toDateString(),
                'event' => $schedule->event,
            ])->values()->toArray(),
        ];
    }

    /**
     * 生成展览日历 .ics 文件
     *
     * @return array{content: string, filename: string}
     *
     * @throws BusinessException
     */
    public function eventCalendar(int $id): array
    {
        /** @var Event|null $event */
        $event = Event::active()->find($id);

        if (!$event) {
            throw new BusinessException(ResponseCode::DATA_NOT_FOUND);
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

        return [
            'content' => $ics,
            'filename' => $event->title . '.ics',
        ];
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
}
