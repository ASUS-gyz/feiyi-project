<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30);
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained('shop_products')->onDelete('cascade');
            $table->string('product_name', 200);
            $table->string('product_image', 500)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('price', 10, 2)->comment('下单时单价快照');
            $table->decimal('total_amount', 10, 2);
            $table->string('status', 20)->default('ORDER_PENDING');
            $table->string('address', 300);
            $table->string('contact_name', 50);
            $table->string('contact_phone', 20);
            $table->string('remark', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique('order_no');
            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_orders');
    }
};
