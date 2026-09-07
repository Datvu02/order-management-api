<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name', 255);
            $table->string('sku', 64);
            $table->unsignedInteger('quantity');
            $table->unsignedDecimal('unit_price', 12, 2);
            $table->unsignedDecimal('total_price', 12, 2);
            $table->timestamps();

            $table->unique(['order_id', 'product_id']);
            $table->index('product_id');
            $table->index('sku');

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->restrictOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
