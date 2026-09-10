<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masterpieces', function (Blueprint $table) {
            // 归属作者用户 ID；NULL 为无主策展名作，不强制存量数据补归属。
            // 关联语义由模型关系承载，不设数据库外键约束（项目建表约定）。
            $table->unsignedBigInteger('user_id')->nullable()->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('masterpieces', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
