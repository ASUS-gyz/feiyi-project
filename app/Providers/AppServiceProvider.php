<?php

namespace App\Providers;

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
    }
}
