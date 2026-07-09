<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooperations', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('description');
            $table->date('deadline');
            $table->string('status', 20)->default('COOP_COLLECTING');
            $table->text('rules')->nullable();
            $table->text('rewards')->nullable();
            $table->json('images')->nullable();
            $table->unsignedInteger('submission_count')->default(0);
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('deadline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooperations');
    }
};
