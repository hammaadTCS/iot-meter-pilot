<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cnic', 50)->nullable()->change();
            $table->string('phone_number', 20)->nullable()->change();
            $table->string('address', 500)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cnic')->nullable(false)->change();
            $table->string('phone_number')->nullable(false)->change();
            $table->string('address')->nullable(false)->change();
        });
    }
};
