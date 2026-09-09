<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 解决 MySQL 索引长度限制（< MySQL 5.7.7 / MariaDB < 10.2.2）
        Schema::defaultStringLength(191);

        $this->registerRateLimiters();
    }

    /**
     * 集中注册全站限流器（策略与阈值见 config/throttle.php）
     *
     * 所有限流器在此唯一定义，路由侧仅以 throttle:<name> 引用；
     * 阈值在请求时实时读取配置，支持环境变量覆盖与运行时调整。
     */
    private function registerRateLimiters(): void
    {
        // 全站 API 兜底：每 IP
        RateLimiter::for('global', function (Request $request) {
            return new Limit('', (int) config('throttle.global.max'), (int) config('throttle.global.decay'))
                ->by($request->ip());
        });

        // 注册：每 IP（防脚本批量造号）
        RateLimiter::for('auth-register', function (Request $request) {
            return new Limit('', (int) config('throttle.auth-register.max'), (int) config('throttle.auth-register.decay'))
                ->by($request->ip());
        });

        // 上传 / AI 聊天 / 捐赠创建按身份限流（登录按用户、游客按 IP）
        // 由 ThrottleUserOrIp 中间件实现：须在 jwt 中间件之后执行才能取到已绑定用户，
        // 不能用框架 throttle 命名限流器（其默认优先级会先于 jwt 执行）
    }
}
