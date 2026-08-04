<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->timestamp('identity_verified_at')->nullable()->after('user_agent');
            $table->string('identity_photo_url', 2048)->nullable()->after('identity_verified_at');
            $table->json('proctor_snapshots')->nullable()->after('identity_photo_url');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropColumn(['identity_verified_at', 'identity_photo_url', 'proctor_snapshots']);
        });
    }
};
