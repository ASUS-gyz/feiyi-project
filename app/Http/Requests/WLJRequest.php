<?php

namespace App\Http\Requests;

use App\Support\TextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class WLJRequest extends FormRequest
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
        $fields = match ($this->route()?->getName()) {
            'posts.create', 'posts.update' => ['title', 'content'],
            'comments.create', 'comments.update' => ['content'],
            'cooperations.submit' => ['title', 'description', 'authorName'],
            default => [],
        };

        if ($fields === []) {
            return;
        }

        $input = $this->all();
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = TextSanitizer::clean($input[$field]);
            }
        }
        $this->merge($input);
    }

    public function rules(): array
    {
        $action = $this->route()?->getName();

        return match ($action) {
            // === 互动帖子 ===
            'posts.list' => [
                'category'  => 'string|in:GAME,QA,DONATION,COLLAB,OTHER',
                'keyword'   => 'string|max:100',
                'sortBy'    => 'string|in:createdAt,likeCount,commentCount,viewCount',
                'order'     => 'string|in:asc,desc',
            ],
            'posts.create' => [
                'title'    => 'required|string|min:2|max:100',
                'content'  => 'required|string|max:5000',
                'category' => 'required|string|in:GAME,QA,DONATION,COLLAB,OTHER',
                'images'   => 'nullable|array',
                'images.*' => 'string',
            ],
            'posts.update' => [
                'title'    => 'string|min:2|max:100',
                'content'  => 'string|max:5000',
                'category' => 'string|in:GAME,QA,DONATION,COLLAB,OTHER',
                'images'   => 'nullable|array',
                'images.*' => 'string',
            ],

            // === 评论与回复 ===
            'comments.list' => [
                'postId'   => 'integer|min:1',
                'parentId' => 'integer|min:0',
            ],
            'comments.create' => [
                'content'  => 'required|string|min:1|max:2000',
                'postId'   => 'required|integer|min:1',
                'parentId' => 'nullable|integer|min:1',
            ],
            'comments.update' => [
                'content' => 'required|string|min:1|max:2000',
            ],

            // === 传世名作 ===
            'masterpieces.list' => [
                'period'   => 'string|in:QING,MING,CONTEMPORARY',
                'school'   => 'string|in:SUZHOU,CHAOSHAN,LITERATI',
                'keyword'  => 'string|max:100',
                'sortBy'   => 'string|in:createdAt,likeCount,viewCount',
                'order'    => 'string|in:asc,desc',
            ],

            // === 收藏夹 ===
            'favorites.list' => [
                'targetType' => 'string|in:POST,MASTERPIECE,ARTICLE',
            ],
            'favorites.check' => [
                'targetId'   => 'required|integer|min:1',
                'targetType' => 'required|string|in:POST,MASTERPIECE,ARTICLE',
            ],
            'favorites.add' => [
                'targetId'   => 'required|integer|min:1',
                'targetType' => 'required|string|in:POST,MASTERPIECE,ARTICLE',
            ],

            // === 共创计划 ===
            'cooperations.list' => [
                'status'   => 'string|in:COOP_COLLECTING,COOP_REVIEWING,COOP_PRODUCING,COOP_COMPLETED',
            ],
            'cooperations.submit' => [
                'title'       => 'required|string|min:2|max:50',
                'description' => 'required|string|min:10|max:1000',
                'images'      => 'required|array|min:1',
                'images.*'    => 'string',
                'authorName'  => 'nullable|string|max:50',
            ],
            'cooperations.mySubmissions' => [
            ],

            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'title.required'          => '标题不能为空',
            'title.min'               => '标题过短',
            'title.max'               => '标题过长',
            'content.required'        => '内容不能为空',
            'content.min'             => '内容过短',
            'content.max'             => '内容过长',
            'category.required'       => '分类不能为空',
            'category.in'             => '分类不合法',
            'postId.required'         => '帖子ID不能为空',
            'targetId.required'       => '目标ID不能为空',
            'targetType.required'     => '目标类型不能为空',
            'targetType.in'           => '目标类型不合法',
            'description.required'    => '设计说明不能为空',
            'description.min'         => '设计说明过短',
            'images.required'         => '设计图不能为空',
            'images.min'              => '请至少上传一张设计图',
        ];
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        // 将 camelCase 转为 snake_case 便于 Service 使用
        if (is_array($validated)) {
            $mapped = [];
            foreach ($validated as $k => $v) {
                $mapped[Str::snake($k)] = $v;
            }

            // 分页参数不再逐路由校验：原样并入 validated 数据（规则删除后
            // validated 不再携带它们），由服务层的分页解析器统一钳制
            // 并应用端点默认值（默认值差异在此处不可知，不能在此钳制）
            foreach (['page', 'pageSize', 'page_size'] as $paginationKey) {
                if (($paginationValue = $this->input($paginationKey)) !== null) {
                    $mapped[Str::snake($paginationKey)] = $paginationValue;
                }
            }

            return $mapped;
        }

        return $validated;
    }
}
