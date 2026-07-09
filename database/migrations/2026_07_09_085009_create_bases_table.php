<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bases', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('location', 200);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('status', 20)->default('BASE_OPEN');
            $table->string('booking_type', 20)->nullable();
            $table->string('booking_value', 100)->nullable();
            $table->string('courses', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('contact', 50)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('opening_hours', 100)->nullable();
            $table->json('images')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bases');
    }
};
