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

        // 上传：登录按用户，未认证回退 IP
        RateLimiter::for('upload', function (Request $request) {
            $key = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip();

            return new Limit('', (int) config('throttle.upload.max'), (int) config('throttle.upload.decay'))
                ->by($key);
        });

        // AI 聊天：登录按账号、游客按 IP
        RateLimiter::for('chat', function (Request $request) {
            if ($request->user()?->id) {
                return new Limit('', (int) config('throttle.chat.auth_max'), (int) config('throttle.chat.decay'))
                    ->by('user:'.$request->user()->id);
            }

            return new Limit('', (int) config('throttle.chat.guest_max'), (int) config('throttle.chat.decay'))
                ->by('ip:'.$request->ip());
        });

        // 捐赠创建：登录按用户，未认证回退 IP
        RateLimiter::for('donation', function (Request $request) {
            $key = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip();

            return new Limit('', (int) config('throttle.donation.max'), (int) config('throttle.donation.decay'))
                ->by($key);
        });
    }
}
