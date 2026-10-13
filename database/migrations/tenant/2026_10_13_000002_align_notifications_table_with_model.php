<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The notifications table was created in Laravel's notifiable/morph
     * shape, but the Notification model and NotificationController read and
     * write user_id / title / message / is_read — so listing a user's
     * notifications crashed ("Unknown column 'user_id'") and creating one
     * failed on the never-filled notifiable_* / data columns. Add the
     * columns the app uses and relax the ones it never fills.
     */
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index()->after('id');
            }
            if (! Schema::hasColumn('notifications', 'title')) {
                $table->string('title')->nullable();
            }
            if (! Schema::hasColumn('notifications', 'message')) {
                $table->text('message')->nullable();
            }
            if (! Schema::hasColumn('notifications', 'is_read')) {
                $table->boolean('is_read')->default(false);
            }
        });

        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasColumn('notifications', 'notifiable_type')) {
                $table->string('notifiable_type')->nullable()->change();
                $table->unsignedBigInteger('notifiable_id')->nullable()->change();
            }
            if (Schema::hasColumn('notifications', 'data')) {
                $table->text('data')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // Additive fix; columns are left in place on rollback.
    }
};
