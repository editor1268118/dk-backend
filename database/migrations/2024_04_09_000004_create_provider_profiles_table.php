<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name')->nullable();
            $table->string('professional_title')->nullable();
            $table->string('category')->nullable();
            $table->text('bio')->nullable();
            $table->integer('experience_years')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->string('verification_status')->default('pending');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('provider_profiles');
    }
};
