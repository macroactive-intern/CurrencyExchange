<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('from_currency', 50);
            $table->string('to_currency', 50);
            $table->decimal('from_amount', 20, 2);
            $table->decimal('to_amount', 20, 2);
            $table->decimal('fee_amount', 20, 2);
            $table->decimal('rate', 20, 8);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_transactions');
    }
};
