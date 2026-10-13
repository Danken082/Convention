<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->string('district', 20);
            $table->string('surname', 100);
            $table->string('given_name', 100);
            $table->string('middle_initial', 10)->nullable();
            $table->string('nickname', 100)->nullable();
            $table->unsignedTinyInteger('age');
            $table->string('gender', 30);
            $table->string('shirt_size', 10);
            $table->string('division', 150);
            $table->string('ministry', 150);
            $table->string('email');
            $table->string('proof_of_payment_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
