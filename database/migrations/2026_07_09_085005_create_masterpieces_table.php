<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masterpieces', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('period', 20);
            $table->string('school', 20);
            $table->string('icon', 10)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('time_making', 50)->nullable();
            $table->string('foil_used', 50)->nullable();
            $table->unsignedTinyInteger('difficulty')->nullable();
            $table->text('background')->nullable();
            $table->text('technique')->nullable();
            $table->text('story')->nullable();
            $table->text('value')->nullable();
            $table->string('cover_image', 500);
            $table->json('images')->nullable();
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);
            $table->string('status', 30)->default('MASTERPIECE_PUBLISHED');
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();

            $table->index('period');
            $table->index('school');
            $table->index('status');
            $table->index('difficulty');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masterpieces');
    }
};
