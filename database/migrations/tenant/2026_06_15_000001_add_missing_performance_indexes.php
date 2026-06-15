<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing indexes on columns that appear in WHERE / JOIN / ORDER BY clauses
 * across the tenant schema. Each index is conditional so re-running is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── library_books ──────────────────────────────────────────────────────
        if (Schema::hasTable('library_books')) {
            Schema::table('library_books', function (Blueprint $table) {
                if (!$this->hasIndex('library_books', 'library_books_school_id_status_index')) {
                    $table->index(['school_id', 'status'], 'library_books_school_id_status_index');
                }
            });
        }

        // ── hostel_maintenance ─────────────────────────────────────────────────
        if (Schema::hasTable('hostel_maintenance')) {
            Schema::table('hostel_maintenance', function (Blueprint $table) {
                if (!$this->hasIndex('hostel_maintenance', 'hostel_maintenance_reported_by_index')) {
                    $table->index('reported_by', 'hostel_maintenance_reported_by_index');
                }
                if (!$this->hasIndex('hostel_maintenance', 'hostel_maintenance_assigned_to_index')) {
                    $table->index('assigned_to', 'hostel_maintenance_assigned_to_index');
                }
            });
        }

        // ── health_appointments ────────────────────────────────────────────────
        if (Schema::hasTable('health_appointments')) {
            Schema::table('health_appointments', function (Blueprint $table) {
                if (!$this->hasIndex('health_appointments', 'health_appointments_created_by_index')) {
                    $table->index('created_by', 'health_appointments_created_by_index');
                }
                if (!$this->hasIndex('health_appointments', 'health_appointments_appointment_date_index')) {
                    $table->index('appointment_date', 'health_appointments_appointment_date_index');
                }
            });
        }

        // ── inventory_transactions ─────────────────────────────────────────────
        if (Schema::hasTable('inventory_transactions')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                if (!$this->hasIndex('inventory_transactions', 'inventory_transactions_recorded_by_index')) {
                    $table->index('recorded_by', 'inventory_transactions_recorded_by_index');
                }
                if (!$this->hasIndex('inventory_transactions', 'inventory_transactions_item_created_index')) {
                    $table->index(['item_id', 'created_at'], 'inventory_transactions_item_created_index');
                }
            });
        }

        // ── notifications ──────────────────────────────────────────────────────
        if (Schema::hasTable('notifications')) {
            Schema::table('notifications', function (Blueprint $table) {
                if (
                    Schema::hasColumn('notifications', 'user_id')
                    && Schema::hasColumn('notifications', 'created_at')
                    && ! $this->hasIndex('notifications', 'notifications_user_id_created_at_index')
                ) {
                    $table->index(['user_id', 'created_at'], 'notifications_user_id_created_at_index');
                }
                if (
                    Schema::hasColumn('notifications', 'user_id')
                    && Schema::hasColumn('notifications', 'is_read')
                    && ! $this->hasIndex('notifications', 'notifications_user_id_is_read_index')
                ) {
                    $table->index(['user_id', 'is_read'], 'notifications_user_id_is_read_index');
                }
                if (
                    Schema::hasColumn('notifications', 'notifiable_id')
                    && Schema::hasColumn('notifications', 'notifiable_type')
                    && Schema::hasColumn('notifications', 'created_at')
                    && ! $this->hasIndex('notifications', 'notifications_notifiable_created_at_index')
                ) {
                    $table->index(
                        ['notifiable_type', 'notifiable_id', 'created_at'],
                        'notifications_notifiable_created_at_index'
                    );
                }
                if (
                    Schema::hasColumn('notifications', 'notifiable_id')
                    && Schema::hasColumn('notifications', 'notifiable_type')
                    && Schema::hasColumn('notifications', 'read_at')
                    && ! $this->hasIndex('notifications', 'notifications_notifiable_read_at_index')
                ) {
                    $table->index(
                        ['notifiable_type', 'notifiable_id', 'read_at'],
                        'notifications_notifiable_read_at_index'
                    );
                }
            });
        }

        // ── student_results ────────────────────────────────────────────────────
        if (Schema::hasTable('student_results')) {
            Schema::table('student_results', function (Blueprint $table) {
                if (!$this->hasIndex('student_results', 'student_results_term_year_index')) {
                    $table->index(['term_id', 'academic_year_id'], 'student_results_term_year_index');
                }
                if (!$this->hasIndex('student_results', 'student_results_grade_index')) {
                    $table->index('grade', 'student_results_grade_index');
                }
            });
        }

        // ── library_borrows ────────────────────────────────────────────────────
        if (Schema::hasTable('library_borrows')) {
            Schema::table('library_borrows', function (Blueprint $table) {
                if (!$this->hasIndex('library_borrows', 'library_borrows_status_due_date_index')) {
                    $table->index(['status', 'due_date'], 'library_borrows_status_due_date_index');
                }
            });
        }

        // ── ca_scores ──────────────────────────────────────────────────────────
        if (Schema::hasTable('ca_scores')) {
            Schema::table('ca_scores', function (Blueprint $table) {
                if (!$this->hasIndex('ca_scores', 'ca_scores_ca_student_index')) {
                    $table->index(['continuous_assessment_id', 'student_id'], 'ca_scores_ca_student_index');
                }
            });
        }

        // ── payments ───────────────────────────────────────────────────────────
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (!$this->hasIndex('payments', 'payments_status_date_index')) {
                    $table->index(['status', 'payment_date'], 'payments_status_date_index');
                }
            });
        }
    }

    public function down(): void
    {
        $drops = [
            'library_books'           => ['library_books_school_id_status_index'],
            'hostel_maintenance'      => ['hostel_maintenance_reported_by_index', 'hostel_maintenance_assigned_to_index'],
            'health_appointments'     => ['health_appointments_created_by_index', 'health_appointments_appointment_date_index'],
            'inventory_transactions'  => ['inventory_transactions_recorded_by_index', 'inventory_transactions_item_created_index'],
            'notifications'           => [
                'notifications_user_id_created_at_index',
                'notifications_user_id_is_read_index',
                'notifications_notifiable_created_at_index',
                'notifications_notifiable_read_at_index',
            ],
            'student_results'         => ['student_results_term_year_index', 'student_results_grade_index'],
            'library_borrows'         => ['library_borrows_status_due_date_index'],
            'ca_scores'               => ['ca_scores_ca_student_index'],
            'payments'                => ['payments_status_date_index'],
        ];

        foreach ($drops as $table => $indexes) {
            if (!Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as $idx) {
                    try { $blueprint->dropIndex($idx); } catch (\Throwable) {}
                }
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return !empty($indexes);
    }
};
