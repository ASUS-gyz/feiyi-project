<?php

namespace App\Http\Requests;

use App\Support\TextSanitizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * CGJ 模块（认证外的捐赠/上传/基地/展览）参数验证层
 *
 * 认证与个人资料走专属表单请求（Auth\LoginRequest 等），不在本类范围内。
 * 文件上传的内容筛查（扩展名白名单/MIME 嗅探/大小）留在 Service：
 * UploadSecurityTest 编码了 10006/10007 等专属错误码，且属安全筛查单元，
 * 表单请求全局异常只会渲染 10001，收编即契约漂移。
 */
class CGJRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 公开接口无需鉴权，已登录接口由 jwt.auth 中间件处理
        return true;
    }

    /**
     * 验证前净化自由文本：先净化后验长，杜绝标签垫长绕过（存储型 XSS 写侧防御）
     */
    public function prepareForValidation(): void
    {
        if ($this->getRouteAction() === 'donations.create' && array_key_exists('message', $this->all())) {
            $this->merge(['message' => TextSanitizer::clean($this->input('message'))]);
        }
    }

    public function rules(): array
    {
        $action = $this->getRouteAction();

        return match ($action) {
            // === 文件上传 ===（结构性筛查在 Service，规则留空）
            'upload.avatar' => [],
            'upload.postImage' => [],

            // === 传承基地 ===
            'bases.list' => [
                'status' => 'nullable|string|in:BASE_OPEN,BASE_APPOINTMENT,BASE_CLOSED',
                'region' => 'nullable|string|max:100',
            ],
            'bases.nearby' => [
                'latitude'  => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'radius'    => 'nullable|numeric|min:0',
            ],
            'bases.detail' => [],

            // === 展览活动 ===
            'events.list' => [
                'status' => 'nullable|string|in:EVENT_UPCOMING,EVENT_ONGOING,EVENT_PAST',
                'month'  => 'nullable|date_format:Y-m',
            ],
            'events.detail' => [],
            'events.calendar' => [],

            // === 捐赠支持 ===
            'donations.projects' => [],
            'donations.create' => [
                'projectId'   => 'required|integer|min:1',
                'amount'      => 'required|numeric|min:10',
                'isAnonymous' => 'nullable|boolean',
                'message'     => 'nullable|string|max:500',
            ],
            // 分页参数不在此校验：钳制语义由服务层分页解析器统一处理
            'donations.records' => [],
            'donations.certificate' => [],

            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'projectId.required'    => '请选择捐赠项目',
            'projectId.integer'     => '捐赠项目不合法',
            'projectId.min'         => '捐赠项目不合法',
            'amount.required'       => '捐赠金额不能为空',
            'amount.numeric'        => '捐赠金额必须为数字',
            'amount.min'            => '捐赠金额不能低于 10 元',
            'isAnonymous.boolean'   => '是否匿名参数不合法',
            'message.max'           => '捐赠留言不能超过 500 字',
            'status.in'             => '状态参数不合法',
            'latitude.required'     => '请提供经纬度参数',
            'longitude.required'    => '请提供经纬度参数',
            'latitude.between'      => '纬度不合法',
            'longitude.between'     => '经度不合法',
            'radius.numeric'        => '搜索半径不合法',
            'radius.min'            => '搜索半径不合法',
            'month.date_format'     => '月份格式应为 YYYY-MM',
        ];
    }

    /**
     * CGJ 路由未命名：按 method + path 推断 action（去掉可能的 api/ 前缀）
     */
    private function getRouteAction(): ?string
    {
        $name = $this->route()?->getName();
        if ($name) {
            return $name;
        }

        $method = $this->method();
        $path   = ltrim((string) $this->path(), '/');
        $path   = str_starts_with($path, 'api/') ? substr($path, 4) : $path;

        return match (true) {
            // upload
            $method === 'POST' && $path === 'upload/avatar' => 'upload.avatar',
            $method === 'POST' && $path === 'upload/post-image' => 'upload.postImage',
            // bases（nearby 先于 {id} 判断）
            $method === 'GET' && $path === 'bases/nearby' => 'bases.nearby',
            $method === 'GET' && $path === 'bases' => 'bases.list',
            $method === 'GET' && preg_match('#^bases/\d+$#', $path) === 1 => 'bases.detail',
            // events
            $method === 'GET' && $path === 'events' => 'events.list',
            $method === 'GET' && preg_match('#^events/\d+/calendar$#', $path) === 1 => 'events.calendar',
            $method === 'GET' && preg_match('#^events/\d+$#', $path) === 1 => 'events.detail',
            // donations
            $method === 'GET' && $path === 'donations/projects' => 'donations.projects',
            $method === 'POST' && $path === 'donations' => 'donations.create',
            $method === 'GET' && $path === 'donations/records' => 'donations.records',
            $method === 'GET' && preg_match('#^donations/\d+/certificate$#', $path) === 1 => 'donations.certificate',
            default => null,
        };
    }
}
