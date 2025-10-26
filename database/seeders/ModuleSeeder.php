<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modules = [
            // Core Modules (always included)
            [
                'name' => 'Student Management',
                'slug' => 'student_management',
                'description' => 'Manage student enrollment, profiles, and academic records',
                'icon' => 'users',
                'category' => 'core',
                'features' => ['enrollment', 'profiles', 'academic_records', 'class_assignment'],
                'requirements' => [],
                'is_active' => true,
                'is_core' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Teacher Management',
                'slug' => 'teacher_management',
                'description' => 'Manage teacher profiles, assignments, and schedules',
                'icon' => 'user-tie',
                'category' => 'core',
                'features' => ['profiles', 'assignments', 'schedules', 'performance'],
                'requirements' => [],
                'is_active' => true,
                'is_core' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Academic Management',
                'slug' => 'academic_management',
                'description' => 'Manage classes, subjects, academic years, and terms',
                'icon' => 'graduation-cap',
                'category' => 'core',
                'features' => ['classes', 'subjects', 'academic_years', 'terms'],
                'requirements' => [],
                'is_active' => true,
                'is_core' => true,
                'sort_order' => 3,
            ],

            // Assessment Modules
            [
                'name' => 'Computer-Based Testing (CBT)',
                'slug' => 'cbt',
                'description' => 'Online examinations with multiple question types',
                'icon' => 'laptop',
                'category' => 'assessment',
                'features' => ['online_exams', 'multiple_choice', 'essay_questions', 'auto_grading'],
                'requirements' => ['student_management'],
                'is_active' => true,
                'is_core' => false,
                'sort_order' => 10,
            ],
            [
                'name' => 'Result Management',
                'slug' => 'result_management',
                'description' => 'Grade management and result processing',
                'icon' => 'chart-line',
                'category' => 'assessment',
                'features' => ['grading', 'result_processing', 'report_cards', 'analytics'],
                'requirements' => ['student_management'],
                'is_active' => true,
                'is_core' => false,
                'sort_order' => 11,
            ],

            // Communication Modules
            [
                'name' => 'SMS Integration',
                'slug' => 'sms_integration',
                'description' => 'Send SMS notifications to parents and students',
                'icon' => 'sms',
                'category' => 'communication',
                'features' => ['bulk_sms', 'automated_notifications', 'custom_messages'],
                'requirements' => ['student_management'],
                'is_active' => true,
                'is_core' => false,
                'sort_order' => 20,
            ],
            [
                'name' => 'Email Integration',
                'slug' => 'email_integration',
                'description' => 'Send email notifications and newsletters',
                'icon' => 'envelope',
                'category' => 'communication',
                'features' => ['bulk_email', 'newsletters', 'automated_emails'],
                'requirements' => ['student_management'],
                'is_active' => true,
                'is_core' => false,
                'sort_order' => 21,
            ],

            // Financial Modules
            [
                'name' => 'Fee Management',
                'slug' => 'fee_management',
                'description' => 'Manage school fees and payments',
                'icon' => 'credit-card',
                'category' => 'financial',
                'features' => ['fee_structure', 'payment_tracking', 'receipts', 'arrears'],
                'requirements' => ['student_management'],
                'is_active' => true,
                'is_core' => false,
                'sort_order' => 30,
            ],
            [
                'name' => 'Payroll Management',
                'slug' => 'payroll_management',
                'description' => 'Manage staff salaries and benefits',
                'icon' => 'money-bill-wave',
                'category' => 'financial',
                'features' => ['salary_calculation', 'benefits', 'tax_deductions', 'payslips'],
                'requirements' => ['teacher_management'],
                'is_active' => true,
                'is_core' => false,
                'sort_order' => 31,
            ],

            // Administrative Modules
            [
                'name' => 'Attendance Management',
                'slug' => 'attendance_management',
                'description' => 'Track student and staff attendance',
                'icon' => 'clock',
                'category' => 'administrative',
                'features' => ['daily_attendance', 'reports', 'notifications', 'analytics'],
                'requirements' => ['student_management', 'teacher_management'],
                'is_active' => true,
                'is_core' => false,
                'sort_order' => 40,
            ],
            [
                'name' => 'Transport Management',
                'slug' => 'transport_management',
                'description' => 'Manage school transport and routes',
                'icon' => 'bus',
                'category' => 'administrative',
                'features' => ['route_management', 'driver_assignment', 'pickup_tracking'],
                'requirements' => ['student_management'],
                'is_active' => true,
                'is_core' => false,
                'sort_order' => 41,
            ],
            [
                'name' => 'Hostel Management',
                'slug' => 'hostel_management',
                'description' => 'Manage boarding facilities and room assignments',
                'icon' => 'bed',
                'category' => 'administrative',
                'features' => ['room_management', 'allocation', 'maintenance', 'billing'],
                'requirements' => ['student_management'],
                'is_active' => true,
                'is_core' => false,
                'sort_order' => 42,
            ],
            [
                'name' => 'Health Management',
                'slug' => 'health_management',
                'description' => 'Manage student health records and medical information',
                'icon' => 'heart',
                'category' => 'administrative',
                'features' => ['health_records', 'medical_history', 'appointments', 'medications'],
                'requirements' => ['student_management'],
                'is_active' => true,
                'is_core' => false,
                'sort_order' => 43,
            ],
            [
                'name' => 'Inventory Management',
                'slug' => 'inventory_management',
                'description' => 'Manage school assets and inventory',
                'icon' => 'box',
                'category' => 'administrative',
                'features' => ['asset_tracking', 'stock_management', 'purchases', 'maintenance'],
                'requirements' => [],
                'is_active' => true,
                'is_core' => false,
                'sort_order' => 44,
            ],
            [
                'name' => 'Event Management',
                'slug' => 'event_management',
                'description' => 'Plan and manage school events and activities',
                'icon' => 'calendar',
                'category' => 'administrative',
                'features' => ['event_planning', 'scheduling', 'notifications', 'attendance'],
                'requirements' => ['student_management'],
                'is_active' => true,
                'is_core' => false,
                'sort_order' => 45,
            ],
        ];

        foreach ($modules as $module) {
            Module::create($module);
        }
    }
}
