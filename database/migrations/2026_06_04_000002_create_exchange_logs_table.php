<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('from_currency', ['gold', 'gems']);
            $table->enum('to_currency', ['gold', 'gems']);
            $table->unsignedBigInteger('from_amount');  // source deduction is always a whole number
            $table->decimal('to_amount', 20, 8);         // credited amount is fractional after rate+fee
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_logs');
    }
};
