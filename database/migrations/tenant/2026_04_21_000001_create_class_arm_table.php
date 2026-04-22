<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('class_arm')) {
            return;
        }

        Schema::create('class_arm', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('arm_id');
            $table->integer('capacity')->default(30);
            $table->unsignedBigInteger('class_teacher_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->foreign('class_id')->references('id')->on('classes')->onDelete('cascade');
            $table->foreign('arm_id')->references('id')->on('arms')->onDelete('cascade');
            $table->unique(['class_id', 'arm_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_arm');
    }
};
