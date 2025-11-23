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

        // If we're in a tenant context, get the school from the tenant database
        if (tenancy()->initialized) {
            try {
                // In tenant database, there's typically one school per tenant
                $school = School::first();
                if ($school) {
                    // Cache it in request attributes for subsequent calls
                    $request->attributes->set('school', $school);
                    return $school;
                }
            } catch (\Exception $e) {
                // Table doesn't exist or query failed
            }
        }

        // Try to get from tenant
        $tenant = $request->attributes->get('tenant');
        if ($tenant instanceof Tenant) {
            try {
                // In tenant database, there's typically one school
                $school = School::first();
                if ($school) {
                    $request->attributes->set('school', $school);
                    return $school;
                }
            } catch (\Exception $e) {
                // Table doesn't exist or query failed
            }
        }

        // Try to get from school_id parameter or header
        $schoolId = $request->get('school_id') ?? $request->header('X-School-ID');
        if ($schoolId) {
            try {
                $school = School::find($schoolId);
                if ($school) {
                    $request->attributes->set('school', $school);
                    return $school;
                }
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
