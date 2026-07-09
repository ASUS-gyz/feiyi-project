<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->string('donation_no', 30);
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained('donation_projects')->onDelete('cascade');
            $table->string('project_title', 200)->comment('捐赠时项目名快照');
            $table->decimal('amount', 10, 2);
            $table->boolean('is_anonymous')->default(false);
            $table->string('message', 500)->nullable();
            $table->string('status', 20)->default('DONATION_COMPLETED');
            $table->string('certificate_url', 500)->nullable();
            $table->timestamps();

            $table->unique('donation_no');
            $table->index('user_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
