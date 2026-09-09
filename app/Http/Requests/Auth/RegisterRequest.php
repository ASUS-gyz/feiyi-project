<?php

namespace App\Http\Requests\Auth;

use App\Support\TextSanitizer;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * 确定用户是否有权限发起此请求
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 验证前净化昵称（存储型 XSS 写侧防御，先净化后验长）
     */
    public function prepareForValidation(): void
    {
        if ($this->has('nickname')) {
            $this->merge(['nickname' => TextSanitizer::clean($this->input('nickname'))]);
        }
    }

    /**
     * 获取验证规则
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50', 'unique:users,name'],
            'password' => ['required', 'string', 'min:6'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'nickname' => ['nullable', 'string', 'max:50'],
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
            'username' => '用户名',
            'password' => '密码',
            'email' => '邮箱',
            'nickname' => '昵称',
        ];
    }
}
