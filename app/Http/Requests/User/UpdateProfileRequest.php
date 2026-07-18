<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * 确定用户是否有权限发起此请求
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 获取验证规则
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'nickname' => ['nullable', 'string', 'max:50'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'bio' => ['nullable', 'string', 'max:500'],
            'region' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * 获取自定义字段名称
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nickname' => '昵称',
            'email' => '邮箱',
            'bio' => '个人简介',
            'region' => '所在地区',
        ];
    }
}