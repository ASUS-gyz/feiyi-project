<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->unique();
            $table->string('title', 50);
            $table->string('description', 500)->nullable();
            $table->string('icon', 500)->nullable();
            $table->string('cover_image', 500)->nullable();
            $table->string('default_difficulty', 20)->nullable();
            $table->json('difficulty_options')->nullable();
            $table->json('features')->nullable();
            $table->json('rules')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
