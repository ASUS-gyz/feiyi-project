<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('target_id');
            $table->string('target_type', 20);
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'target_id', 'target_type']);
            $table->index(['target_id', 'target_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
