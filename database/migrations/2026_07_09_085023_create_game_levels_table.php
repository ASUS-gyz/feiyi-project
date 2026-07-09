<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->string('name', 100);
            $table->string('pattern_url', 500)->nullable();
            $table->string('thumbnail', 500)->nullable();
            $table->unsignedInteger('stroke_count')->nullable();
            $table->unsignedInteger('time_limit')->nullable();
            $table->string('difficulty', 20)->default('DIFFICULTY_EASY');
            $table->string('description', 200)->nullable();
            $table->json('extra_data')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['game_id', 'sort_order']);
            $table->index(['game_id', 'difficulty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_levels');
    }
};
