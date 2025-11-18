<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\School;

class ReportController extends Controller
{
    /**
     * Get academic report
     */
    public function academic(Request $request): JsonResponse
    {
        try {
            $school = $this->getSchoolFromRequest($request);
            if (!$school) {
                return response()->json([
                    'error' => 'School not found',
                    'message' => 'Unable to determine school context.'
                ], 404);
            }

            $startDate = $request->get('start_date', now()->startOfYear());
            $endDate = $request->get('end_date', now()->endOfYear());

            $report = [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'summary' => [
                    'total_students' => $this->safeCount('students'),
                    'total_teachers' => $this->safeCount('teachers'),
                    'total_classes' => $this->safeCount('classes'),
                    'total_subjects' => $this->safeCount('subjects'),
                ],
                'performance' => [
                    'average_score' => $this->safeAvg('grades', 'total_score'),
                    'pass_rate' => $this->calculatePassRate(),
                ],
                'exams' => $this->safeGet('exams', ['start_date', '>=', $startDate], ['start_date', '<=', $endDate]),
            ];

            return response()->json([
                'report' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate academic report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get financial report
     */
    public function financial(Request $request): JsonResponse
    {
        try {
            $school = $this->getSchoolFromRequest($request);
            if (!$school) {
                return response()->json([
                    'error' => 'School not found',
                    'message' => 'Unable to determine school context.'
                ], 404);
            }

            $startDate = $request->get('start_date', now()->startOfMonth());
            $endDate = $request->get('end_date', now()->endOfMonth());

            $report = [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'summary' => [
                    'total_fees' => $this->safeSum('fees', 'amount'),
                    'total_payments' => $this->safeSum('payments', 'amount'),
                    'pending_fees' => $this->safeSum('fees', 'amount', [['status', '!=', 'paid']]),
                ],
                'breakdown' => [
                    'by_month' => [],
                    'by_category' => [],
                ],
            ];

            return response()->json([
                'report' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate financial report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get attendance report
     */
    public function attendance(Request $request): JsonResponse
    {
        try {
            $school = $this->getSchoolFromRequest($request);
            if (!$school) {
                return response()->json([
                    'error' => 'School not found',
                    'message' => 'Unable to determine school context.'
                ], 404);
            }

            $startDate = $request->get('start_date', now()->startOfMonth());
            $endDate = $request->get('end_date', now()->endOfMonth());

            $report = [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'summary' => [
                    'total_days' => 0,
                    'present_days' => 0,
                    'absent_days' => 0,
                    'attendance_rate' => 0,
                ],
                'by_class' => [],
                'by_student' => [],
            ];

            try {
                $totalRecords = DB::table('attendances')
                    ->whereBetween('date', [$startDate, $endDate])
                    ->count();
                
                $presentRecords = DB::table('attendances')
                    ->whereBetween('date', [$startDate, $endDate])
                    ->where('status', 'present')
                    ->count();

                $report['summary'] = [
                    'total_records' => $totalRecords,
                    'present_records' => $presentRecords,
                    'absent_records' => $totalRecords - $presentRecords,
                    'attendance_rate' => $totalRecords > 0 ? round(($presentRecords / $totalRecords) * 100, 2) : 0,
                ];
            } catch (\Exception $e) {
                // Table doesn't exist, return empty report
            }

            return response()->json([
                'report' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate attendance report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export report
     */
    public function export(Request $request, string $type): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'format' => 'required|in:pdf,excel,csv',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        // Implementation would generate and return the report
        return response()->json([
            'message' => 'Report export initiated',
            'type' => $type,
            'format' => $request->format,
            'download_url' => null // Would contain actual download URL
        ]);
    }

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
        if ($tenant) {
            try {
                $school = School::first();
                if ($school) {
                    return $school;
                }
            } catch (\Exception $e) {
                // Table doesn't exist
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
     * Safe count - handles missing tables
     */
    protected function safeCount(string $table, array $where = []): int
    {
        try {
            $query = DB::table($table);
            foreach ($where as $condition) {
                if (count($condition) === 2) {
                    $query->where($condition[0], $condition[1]);
                } elseif (count($condition) === 3) {
                    $query->where($condition[0], $condition[1], $condition[2]);
                }
            }
            return $query->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Safe sum - handles missing tables
     */
    protected function safeSum(string $table, string $column, array $where = []): float
    {
        try {
            $query = DB::table($table);
            foreach ($where as $condition) {
                if (count($condition) === 2) {
                    $query->where($condition[0], $condition[1]);
                } elseif (count($condition) === 3) {
                    $query->where($condition[0], $condition[1], $condition[2]);
                }
            }
            return (float) $query->sum($column);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Safe average - handles missing tables
     */
    protected function safeAvg(string $table, string $column, array $where = []): float
    {
        try {
            $query = DB::table($table);
            foreach ($where as $condition) {
                if (count($condition) === 2) {
                    $query->where($condition[0], $condition[1]);
                } elseif (count($condition) === 3) {
                    $query->where($condition[0], $condition[1], $condition[2]);
                }
            }
            return (float) $query->avg($column);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Safe get - handles missing tables
     */
    protected function safeGet(string $table, ...$where): array
    {
        try {
            $query = DB::table($table);
            foreach ($where as $condition) {
                if (is_array($condition) && count($condition) >= 2) {
                    if (count($condition) === 2) {
                        $query->where($condition[0], $condition[1]);
                    } elseif (count($condition) === 3) {
                        $query->where($condition[0], $condition[1], $condition[2]);
                    }
                }
            }
            return $query->get()->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Calculate pass rate
     */
    protected function calculatePassRate(): float
    {
        try {
            $total = DB::table('grades')->count();
            if ($total === 0) return 0;
            
            $passed = DB::table('grades')
                ->where('total_score', '>=', 50)
                ->count();
            
            return round(($passed / $total) * 100, 2);
        } catch (\Exception $e) {
            return 0;
        }
    }
}
