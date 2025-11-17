<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\Tenant;
use App\Models\Teacher;
use App\Models\Student;
use App\Models\ClassModel;
use App\Models\Subject;
use App\Models\Department;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class SchoolController extends Controller
{
    protected $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    /**
     * Create or initialize school for the current tenant.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\Tenant|null $tenant */
        $tenant = $request->attributes->get('tenant');

        // If tenant not in attributes, try to get from request body or header
        if (!$tenant instanceof Tenant) {
            $tenantId = $request->input('tenant_id') ?? $request->header('X-Tenant-ID');

            // Log for debugging
            if ($tenantId) {
                Log::debug('SchoolController: Attempting to find tenant', [
                    'tenant_id' => $tenantId,
                    'tenant_id_type' => gettype($tenantId),
                    'has_input' => $request->has('tenant_id'),
                    'has_header' => $request->hasHeader('X-Tenant-ID'),
                ]);
            }

            if ($tenantId) {
                $tenant = Tenant::find($tenantId);

                // Log result
                if ($tenant instanceof Tenant) {
                    Log::debug('SchoolController: Tenant found', ['tenant_id' => $tenant->id, 'tenant_name' => $tenant->name]);
                } else {
                    Log::warning('SchoolController: Tenant not found', ['tenant_id' => $tenantId]);
                }

                // If we found a tenant, switch to its database
                if ($tenant instanceof Tenant) {
                    $this->tenantService->switchToTenant($tenant);
                }
            }
        }

        if (!$tenant instanceof Tenant) {
            return response()->json([
                'error' => 'Tenant context missing',
                'message' => 'Unable to resolve tenant for this request. Please provide tenant_id in request body or X-Tenant-ID header.'
            ], 400);
        }

        if (!$tenant->isActive()) {
            return response()->json([
                'error' => 'Tenant inactive',
                'message' => 'This tenant is currently inactive.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'logo' => 'nullable|string|max:255',
            'principal_id' => 'nullable|exists:teachers,id',
            'vice_principal_id' => 'nullable|exists:teachers,id',
            'academic_year' => 'nullable|string|max:255',
            'term' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive,suspended',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $status = $data['status'] ?? 'active';
        $tenantConnection = $tenant->getDatabaseConnectionName();

        try {
            // Ensure tenant database connection is configured
            if (!$this->tenantService) {
                $this->tenantService = app(TenantService::class);
            }

            // Every tenant should have their own database (never use main DB)
            $mainDatabaseName = config('database.connections.mysql.database');
            $databaseName = $tenant->database_name;

            // If tenant is using main database name, generate a new database name from school name
            if ($databaseName === $mainDatabaseName || empty($databaseName)) {
                $schoolName = $data['name'] ?? 'school';
                $databaseName = now()->format('YmdHis') . '_' . Str::slug($schoolName);

                // Update tenant with new database name
                $tenant->database_name = $databaseName;
                $tenant->save();

                // Refresh tenant model to get updated database_name
                $tenant->refresh();

                Log::info("Updated tenant database name", [
                    'tenant_id' => $tenant->id,
                    'old_database' => $mainDatabaseName,
                    'new_database' => $databaseName
                ]);
            }

            // Now use the updated database name for all operations
            $databaseName = $tenant->database_name;
            $databaseExists = false;

            // Try to connect to the tenant database to check if it exists
            try {
                // Configure connection temporarily
                $tempConnection = 'temp_check_' . $tenant->id;
                Config::set("database.connections.{$tempConnection}", [
                    'driver' => 'mysql',
                    'host' => $tenant->database_host,
                    'port' => $tenant->database_port,
                    'database' => $databaseName,
                    'username' => $tenant->database_username,
                    'password' => $tenant->database_password,
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                ]);

                // Try a simple query - if it succeeds, database exists
                DB::connection($tempConnection)->select('SELECT 1');
                $databaseExists = true;
            } catch (\Exception $e) {
                // Database doesn't exist or can't connect
                $databaseExists = false;
                Log::debug("Database check failed (may not exist): " . $e->getMessage());
            }

            if (!$databaseExists) {
                Log::info("Tenant database does not exist. Creating database and running migrations...", [
                    'tenant_id' => $tenant->id,
                    'database_name' => $databaseName
                ]);

                // Ensure tenant has the correct database_name before creating database
                $tenant->refresh();
                if ($tenant->database_name !== $databaseName) {
                    $tenant->database_name = $databaseName;
                    $tenant->save();
                    $tenant->refresh();
                }

                // Verify database name is correct
                Log::debug("Creating database with name", [
                    'tenant_id' => $tenant->id,
                    'database_name_in_tenant' => $tenant->database_name,
                    'expected_database_name' => $databaseName
                ]);

                // Create tenant database and run migrations
                $this->tenantService->createTenantDatabase($tenant);

                Log::info("Tenant database created and migrations completed", [
                    'tenant_id' => $tenant->id,
                    'database_name' => $tenant->database_name
                ]);
            }

            // Switch to tenant database
            $this->tenantService->switchToTenant($tenant);

            // Query tenant database for existing school
            try {
                $tenantSchool = School::on($tenantConnection)
                    ->where('tenant_id', $tenant->id)
                    ->first();
            } catch (\Exception $e) {
                // If query still fails after creating database, log error
                Log::error("Tenant database query failed after creation: " . $e->getMessage(), [
                    'tenant_id' => $tenant->id,
                    'database_name' => $databaseName
                ]);
                $tenantSchool = null;
            }

            $baseCode = $data['code']
                ?? ($tenantSchool?->code ?? Str::upper(Str::slug($data['name'], '_')));

            // Try to ensure unique code in tenant DB, fallback to base code if it fails
            try {
                $code = $this->ensureUniqueCode($tenantConnection, $baseCode, $tenantSchool?->id);
            } catch (\Exception $e) {
                Log::warning("Failed to ensure unique code in tenant database: " . $e->getMessage());
                $code = $baseCode;
            }

            $tenantPayload = [
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'code' => $code,
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'website' => $data['website'] ?? null,
                'logo' => $data['logo'] ?? null,
                'principal_id' => $data['principal_id'] ?? null,
                'vice_principal_id' => $data['vice_principal_id'] ?? null,
                'academic_year' => $data['academic_year'] ?? null,
                'term' => $data['term'] ?? null,
                'settings' => $data['settings'] ?? [],
                'status' => $status,
            ];

            $wasRecentlyCreated = false;

            if ($tenantSchool) {
                $tenantSchool->fill($tenantPayload);
                $tenantSchool->save();
            } else {
                // Try to create in tenant database, fallback to main if it fails
                try {
                    $tenantSchool = School::on($tenantConnection)->create($tenantPayload);
                    $wasRecentlyCreated = true;
                } catch (\Exception $e) {
                    // If tenant database creation fails, log and continue with main DB only
                    Log::warning("Failed to create school in tenant database: " . $e->getMessage());
                    $tenantSchool = null;
                    $wasRecentlyCreated = true;
                }
            }

            $mainPayload = [
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'website' => $data['website'] ?? null,
                'logo' => $data['logo'] ?? null,
                'principal_id' => $data['principal_id'] ?? null,
                'vice_principal_id' => $data['vice_principal_id'] ?? null,
                'academic_year' => $data['academic_year'] ?? null,
                'term' => $data['term'] ?? null,
                'settings' => $data['settings'] ?? [],
                'status' => $status,
            ];

            $school = School::on('mysql')->updateOrCreate(
                ['tenant_id' => $tenant->id],
                $mainPayload
            );

            $response = [
                'message' => $wasRecentlyCreated ? 'School created successfully' : 'School updated successfully',
                'school' => $school->fresh()->load(['tenant', 'principal', 'vicePrincipal']),
            ];

            if ($tenantSchool) {
                $response['tenant_school'] = $tenantSchool;
            }

            return response()->json($response, $wasRecentlyCreated ? 201 : 200);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to create school',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get school information
     */
    public function show(School $school): JsonResponse
    {
        $school->load(['principal', 'vicePrincipal', 'departments', 'academicYears', 'terms']);

        return response()->json([
            'school' => $school,
            'stats' => $school->getStats()
        ]);
    }

    /**
     * Update school information
     */
    public function update(Request $request, School $school): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'logo' => 'nullable|string|max:255',
            'principal_id' => 'nullable|exists:teachers,id',
            'vice_principal_id' => 'nullable|exists:teachers,id',
            'academic_year' => 'nullable|string|max:255',
            'term' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,inactive,suspended',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $school->update($request->all());

            return response()->json([
                'message' => 'School updated successfully',
                'school' => $school
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update school',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get school statistics
     */
    public function stats(School $school): JsonResponse
    {
        $stats = [
            'teachers' => $school->teachers()->count(),
            'students' => $school->students()->count(),
            'classes' => $school->classes()->count(),
            'subjects' => $school->subjects()->count(),
            'departments' => $school->departments()->count(),
            'academic_years' => $school->academicYears()->count(),
            'terms' => $school->terms()->count(),
        ];

        return response()->json([
            'stats' => $stats
        ]);
    }

    /**
     * Get school dashboard data
     */
    public function dashboard(School $school): JsonResponse
    {
        $currentAcademicYear = $school->getCurrentAcademicYear();
        $currentTerm = $school->getCurrentTerm();

        $dashboard = [
            'school' => $school,
            'current_academic_year' => $currentAcademicYear,
            'current_term' => $currentTerm,
            'stats' => $school->getStats(),
            'recent_activities' => $this->getRecentActivities($school),
            'upcoming_events' => $this->getUpcomingEvents($school),
        ];

        return response()->json([
            'dashboard' => $dashboard
        ]);
    }

    /**
     * Get school organogram
     */
    public function organogram(School $school): JsonResponse
    {
        $organogram = [
            'principal' => $school->principal,
            'vice_principal' => $school->vicePrincipal,
            'departments' => $school->departments()->with('head')->get(),
            'year_tutors' => $this->getYearTutors($school),
            'class_teachers' => $this->getClassTeachers($school),
        ];

        return response()->json([
            'organogram' => $organogram
        ]);
    }

    /**
     * Get year tutors
     */
    protected function getYearTutors(School $school)
    {
        return Teacher::where('school_id', $school->id)
                     ->where('role', 'year_tutor')
                     ->with('user')
                     ->get();
    }

    /**
     * Get class teachers
     */
    protected function getClassTeachers(School $school)
    {
        return ClassModel::where('school_id', $school->id)
                        ->with(['classTeacher.user', 'students'])
                        ->get();
    }

    /**
     * Get recent activities
     */
    protected function getRecentActivities(School $school)
    {
        return [
            [
                'id' => 1,
                'type' => 'student_registration',
                'description' => 'New student registered: John Doe',
                'timestamp' => now()->subHours(2),
                'user' => 'Admin User'
            ],
            [
                'id' => 2,
                'type' => 'exam_created',
                'description' => 'Mathematics exam created for SS1A',
                'timestamp' => now()->subHours(4),
                'user' => 'Teacher Smith'
            ],
            [
                'id' => 3,
                'type' => 'payment_received',
                'description' => 'School fees payment received: $500',
                'timestamp' => now()->subHours(6),
                'user' => 'Finance Office'
            ]
        ];
    }

    /**
     * Get upcoming events
     */
    protected function getUpcomingEvents(School $school)
    {
        return [
            [
                'id' => 1,
                'title' => 'Parent-Teacher Meeting',
                'date' => now()->addDays(3),
                'time' => '10:00 AM',
                'location' => 'School Hall',
                'type' => 'meeting'
            ],
            [
                'id' => 2,
                'title' => 'Mathematics Exam',
                'date' => now()->addDays(5),
                'time' => '9:00 AM',
                'location' => 'Classrooms',
                'type' => 'exam'
            ],
            [
                'id' => 3,
                'title' => 'Sports Day',
                'date' => now()->addDays(7),
                'time' => '8:00 AM',
                'location' => 'Sports Field',
                'type' => 'event'
            ]
        ];
    }

    /**
     * Get school information by subdomain
     */
    public function getBySubdomain(Request $request, string $subdomain): JsonResponse
    {
        try {
            // Find tenant by subdomain
            $tenant = Tenant::where('subdomain', $subdomain)
                          ->where('status', 'active')
                          ->first();

            if (!$tenant) {
                return response()->json([
                    'error' => 'School not found',
                    'message' => "No active school found with subdomain: {$subdomain}"
                ], 404);
            }

            // Get school(s) for this tenant
            $schools = School::where('tenant_id', $tenant->id)
                            ->where('status', 'active')
                            ->get();

            if ($schools->isEmpty()) {
                return response()->json([
                    'error' => 'School not found',
                    'message' => "No active school found for subdomain: {$subdomain}"
                ], 404);
            }

            // If multiple schools, return the first one (or you can modify to return all)
            $school = $schools->first();
            $school->load(['tenant', 'principal', 'vicePrincipal']);

            return response()->json([
                'success' => true,
                'subdomain' => $subdomain,
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'subdomain' => $tenant->subdomain,
                    'domain' => $tenant->domain,
                    'status' => $tenant->status,
                ],
                'school' => [
                    'id' => $school->id,
                    'name' => $school->name,
                    'address' => $school->address,
                    'phone' => $school->phone,
                    'email' => $school->email,
                    'website' => $school->website,
                    'logo' => $school->logo,
                    'status' => $school->status,
                    'academic_year' => $school->academic_year,
                    'term' => $school->term,
                    'settings' => $school->settings,
                    'created_at' => $school->created_at,
                    'updated_at' => $school->updated_at,
                ],
                'stats' => $school->getStats() ?? []
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve school information',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ensure the school code is unique within the tenant connection.
     */
    protected function ensureUniqueCode(string $connection, string $baseCode, ?int $ignoreId = null): string
    {
        $base = Str::upper(Str::slug($baseCode, '_'));
        $code = $base;
        $attempts = 0;

        while (
            School::on($connection)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('code', $code)
                ->exists()
        ) {
            $code = $base . '_' . Str::upper(Str::random(4));
            $attempts++;

            if ($attempts >= 10) {
                break;
            }
        }

        return $code;
    }
}
