<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('fundraiser_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('fundraiser_type')->nullable();
            $table->string('organization_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('cause_title')->nullable();
            $table->text('cause_description')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->string('verification_status')->default('pending');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('fundraiser_profiles');
    }
};
