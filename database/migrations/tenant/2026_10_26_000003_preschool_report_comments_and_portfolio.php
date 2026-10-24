<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preschool Progress Report: comments can be switched off per learning area, teachers can add
     * comments for subjects that are not learning areas, and a portfolio of 6–9 pictures per child.
     */
    public function up(): void
    {
        if (Schema::hasTable('result_domains') && ! Schema::hasColumn('result_domains', 'comments_enabled')) {
            Schema::table('result_domains', fn (Blueprint $t) => $t->boolean('comments_enabled')->default(true));
        }
        if (! Schema::hasTable('student_report_extra_comments')) {
            Schema::create('student_report_extra_comments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('student_id')->index();
                $t->unsignedBigInteger('academic_year_id');
                $t->unsignedBigInteger('term_id')->nullable();
                $t->string('subject', 120);
                $t->text('comment');
                $t->string('teacher_name', 100)->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('student_portfolio_items')) {
            Schema::create('student_portfolio_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('student_id')->index();
                $t->unsignedBigInteger('academic_year_id');
                $t->unsignedBigInteger('term_id')->nullable();
                $t->string('image_url', 1000);
                $t->string('caption', 200)->nullable();
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_portfolio_items');
        Schema::dropIfExists('student_report_extra_comments');
        if (Schema::hasColumn('result_domains', 'comments_enabled')) {
            Schema::table('result_domains', fn (Blueprint $t) => $t->dropColumn('comments_enabled'));
        }
    }
};
