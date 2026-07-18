<?php

namespace App\Http\Controllers;

use App\Enums\ResponseCode;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\User\UpdatePasswordRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Models\Base;
use App\Models\Event;
use App\Models\EventSchedule;
use App\Services\AuthService;
use App\Support\Result;
use Illuminate\Http\Request;
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
}