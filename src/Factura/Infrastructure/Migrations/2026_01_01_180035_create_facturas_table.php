<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('ruc', 20)->unique();
            $table->string('email');
            $table->string('phone', 30);
            $table->string('address', 500);
            $table->string('city', 100);
            $table->string('country', 100);
            $table->string('status', 50);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas');
    }
};
