<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('to_status');
            $table->index(['order_id', 'created_at']);
            $table->index('changed_by');

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->restrictOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('changed_by')
                ->references('id')
                ->on('users')
                ->restrictOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
