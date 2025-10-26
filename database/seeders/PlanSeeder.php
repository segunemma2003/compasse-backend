<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Module;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get modules
        $coreModules = Module::where('is_core', true)->pluck('slug')->toArray();
        $assessmentModules = Module::where('category', 'assessment')->pluck('slug')->toArray();
        $communicationModules = Module::where('category', 'communication')->pluck('slug')->toArray();
        $financialModules = Module::where('category', 'financial')->pluck('slug')->toArray();
        $administrativeModules = Module::where('category', 'administrative')->pluck('slug')->toArray();

        $plans = [
            [
                'name' => 'Free Plan',
                'description' => 'Basic features for small schools',
                'type' => 'free',
                'price' => 0.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'trial_days' => 0,
                'features' => ['basic_reporting', 'email_support'],
                'limits' => [
                    'students' => 50,
                    'teachers' => 10,
                    'storage_gb' => 1,
                    'sms_per_month' => 100,
                    'email_per_month' => 500,
                ],
                'modules' => $coreModules,
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'Basic Plan',
                'description' => 'Essential features for growing schools',
                'type' => 'basic',
                'price' => 29.99,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'trial_days' => 14,
                'features' => ['advanced_reporting', 'priority_support', 'cbt_basic'],
                'limits' => [
                    'students' => 200,
                    'teachers' => 25,
                    'storage_gb' => 10,
                    'sms_per_month' => 1000,
                    'email_per_month' => 2000,
                ],
                'modules' => array_merge($coreModules, $assessmentModules, $communicationModules),
                'is_active' => true,
                'is_popular' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Premium Plan',
                'description' => 'Advanced features for established schools',
                'type' => 'premium',
                'price' => 79.99,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'trial_days' => 14,
                'features' => ['advanced_analytics', 'custom_reports', 'api_access', 'cbt_advanced'],
                'limits' => [
                    'students' => 1000,
                    'teachers' => 100,
                    'storage_gb' => 50,
                    'sms_per_month' => 5000,
                    'email_per_month' => 10000,
                ],
                'modules' => array_merge($coreModules, $assessmentModules, $communicationModules, $financialModules, $administrativeModules),
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 3,
            ],
            [
                'name' => 'Enterprise Plan',
                'description' => 'Complete solution for large institutions',
                'type' => 'enterprise',
                'price' => 199.99,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'trial_days' => 30,
                'features' => ['unlimited_analytics', 'custom_integrations', 'dedicated_support', 'white_label'],
                'limits' => [
                    'students' => -1, // unlimited
                    'teachers' => -1, // unlimited
                    'storage_gb' => 500,
                    'sms_per_month' => -1, // unlimited
                    'email_per_month' => -1, // unlimited
                ],
                'modules' => array_merge($coreModules, $assessmentModules, $communicationModules, $financialModules, $administrativeModules),
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $plan) {
            $createdPlan = Plan::create($plan);

            // Attach modules to plan
            if (!empty($plan['modules'])) {
                $moduleIds = Module::whereIn('slug', $plan['modules'])->pluck('id');
                $createdPlan->modules()->attach($moduleIds);
            }
        }
    }
}
