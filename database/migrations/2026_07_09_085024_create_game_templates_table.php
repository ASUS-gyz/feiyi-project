<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->string('name', 100);
            $table->string('skeleton_url', 500);
            $table->json('foil_colors')->nullable();
            $table->string('difficulty', 20)->default('DIFFICULTY_EASY');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['game_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_templates');
    }
};
