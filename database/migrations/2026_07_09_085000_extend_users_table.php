<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nickname', 50)->nullable()->after('email');
            $table->string('avatar', 500)->nullable()->after('password');
            $table->string('role', 20)->default('USER')->after('avatar');
            $table->string('bio', 500)->nullable()->after('role');
            $table->string('region', 100)->nullable()->after('bio');
            $table->boolean('is_deleted')->default(false)->after('remember_token');
            $table->dateTime('deleted_at')->nullable()->after('is_deleted');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['nickname', 'avatar', 'role', 'bio', 'region', 'is_deleted', 'deleted_at']);
        });
    }
};
