<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 先删除唯一索引，否则修改列时报索引超长
            $table->dropUnique('users_email_unique');
            // 修改为 nullable
            $table->string('email', 191)->nullable()->change();
            // 重新添加唯一索引（191 × 4 = 764 < 767，适配 InnoDB 索引限制）
            $table->unique('email', 'users_email_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->string('email', 255)->nullable(false)->change();
            $table->unique('email', 'users_email_unique');
        });
    }
};
