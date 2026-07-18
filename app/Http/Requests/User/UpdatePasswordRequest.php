<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
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
        return [
            'oldPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:6'],
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
            'oldPassword' => '旧密码',
            'newPassword' => '新密码',
        ];
    }
}