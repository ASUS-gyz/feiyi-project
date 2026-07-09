<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masterpiece_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masterpiece_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('name', 50);
            $table->string('description', 500);
            $table->unsignedTinyInteger('difficulty')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['masterpiece_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masterpiece_steps');
    }
};
