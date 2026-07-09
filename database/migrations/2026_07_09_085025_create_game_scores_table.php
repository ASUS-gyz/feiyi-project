<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('game_type', 20);
            $table->unsignedBigInteger('level_id');
            $table->string('level_name', 100)->nullable();
            $table->unsignedInteger('score');
            $table->unsignedInteger('duration');
            $table->string('difficulty', 20)->nullable();
            $table->json('metadata')->nullable();
            $table->string('certificate_url', 500)->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'game_type']);
            $table->index(['game_type', 'level_id', 'score']);
            $table->index(['game_type', 'level_id', 'difficulty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_scores');
    }
};
