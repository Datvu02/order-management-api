<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('status', 32)->default('pending');
            $table->unsignedDecimal('subtotal', 12, 2)->default(0);
            $table->unsignedDecimal('shipping_fee', 12, 2)->default(0);
            $table->unsignedDecimal('discount', 12, 2)->default(0);
            $table->unsignedDecimal('total', 12, 2)->default(0);
            $table->string('shipping_name', 255);
            $table->string('shipping_phone', 20);
            $table->text('shipping_address');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('order_number');
            $table->index(['user_id', 'status']);
            $table->index(['warehouse_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
