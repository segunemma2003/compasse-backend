<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\School;
use App\Models\Tenant;

abstract class Controller
{
    /**
     * Get school from request
     */
    protected function getSchoolFromRequest(Request $request): ?School
    {
        // Try to get from request attributes (set by middleware)
        $school = $request->attributes->get('school');
        if ($school instanceof School) {
            return $school;
        }

        // Try to get from tenant
        $tenant = $request->attributes->get('tenant');
        if ($tenant instanceof Tenant) {
            try {
                // In tenant database, there's typically one school
                $school = School::first();
                if ($school) {
                    return $school;
                }
            } catch (\Exception $e) {
                // Table doesn't exist or query failed
            }
        }

        // Try to get from school_id
        $schoolId = $request->get('school_id') ?? $request->header('X-School-ID');
        if ($schoolId) {
            try {
                return School::find($schoolId);
            } catch (\Exception $e) {
                // Table doesn't exist
            }
        }

        return null;
    }

    /**
     * Safe database operation - handles missing tables
     */
    protected function safeDbOperation(callable $operation, $default = null)
    {
        try {
            return $operation();
        } catch (\Exception $e) {
            return $default;
        }
    }
}
