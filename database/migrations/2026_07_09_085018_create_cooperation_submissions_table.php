<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooperation_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('cooperation_id')->constrained()->onDelete('cascade');
            $table->string('title', 50);
            $table->string('description', 1000);
            $table->json('images');
            $table->string('author_name', 50)->nullable();
            $table->string('status', 20)->default('SUBMISSION_PENDING');
            $table->string('feedback', 500)->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();

            $table->index('cooperation_id');
            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooperation_submissions');
    }
};
