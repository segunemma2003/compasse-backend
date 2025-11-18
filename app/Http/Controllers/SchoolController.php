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
use Illuminate\Support\Facades\Artisan;
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

        // If no tenant provided, create a new tenant with its own database for this school
        if (!$tenant instanceof Tenant) {
            try {
                Log::info("No tenant provided, creating new tenant and database for school", [
                    'school_name' => $request->input('name')
                ]);

                // Create new tenant with unique database for this school
                $tenant = $this->tenantService->createTenant([
                    'name' => $request->input('name') . ' School',
                    'subdomain' => Str::slug($request->input('name')),
                    'settings' => []
                ]);

                Log::info("New tenant created for school", [
                    'tenant_id' => $tenant->id,
                    'database_name' => $tenant->database_name,
                    'school_name' => $request->input('name')
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to create tenant for school: " . $e->getMessage(), [
                    'school_name' => $request->input('name'),
                    'error' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'error' => 'Failed to create tenant',
                    'message' => 'Unable to create tenant and database for this school: ' . $e->getMessage()
                ], 500);
            }
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
            'logo_file' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,svg|max:5120', // 5MB max for logo
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

        // Handle logo file upload if provided
        $logoUrl = $data['logo'] ?? null;
        if ($request->hasFile('logo_file')) {
            try {
                $fileUploadService = app(\App\Services\FileUploadService::class);
                $uploadResult = $fileUploadService->uploadFile(
                    $request->file('logo_file'),
                    'schools/logos'
                );
                $logoUrl = $uploadResult['url'] ?? $uploadResult['key'] ?? null;

                Log::info("School logo uploaded", [
                    'logo_url' => $logoUrl,
                    'tenant_id' => $tenant->id
                ]);
            } catch (\Exception $e) {
                Log::warning("Failed to upload school logo: " . $e->getMessage());
                // Continue without logo if upload fails
            }
        }

        try {
            // Ensure tenant database connection is configured
            if (!$this->tenantService) {
                $this->tenantService = app(TenantService::class);
            }

            // Every tenant should have their own database (never use main DB)
            $mainDatabaseName = config('database.connections.mysql.database');
            $databaseName = $tenant->database_name;

            // Known default database names that should be replaced
            $defaultDatabaseNames = ['compasse_main', $mainDatabaseName];

            // If tenant is using main database name or any default, generate a new database name from school name
            if (empty($databaseName) || in_array($databaseName, $defaultDatabaseNames)) {
                $schoolName = $data['name'] ?? 'school';
                $databaseName = now()->format('YmdHis') . '_' . Str::slug($schoolName);

                // Update tenant with new database name
                $tenant->database_name = $databaseName;
                $tenant->save();

                // Refresh tenant model to get updated database_name
                $tenant->refresh();

                Log::info("Updated tenant database name", [
                    'tenant_id' => $tenant->id,
                    'old_database' => $tenant->getOriginal('database_name'),
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

            // Tenant DB payload - no tenant_id needed (each DB is isolated per tenant)
            $tenantPayload = [
                'name' => $data['name'],
                'code' => $code,
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'website' => $data['website'] ?? null,
                'logo' => $logoUrl ?? $data['logo'] ?? null,
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

                    // After school is created, create admin account directly in tenant DB
                    if ($wasRecentlyCreated) {
                        try {
                            // Switch to tenant database
                            $this->tenantService->switchToTenant($tenant);

                            // Generate admin email based on school name
                            $schoolName = $tenantSchool->name;
                            $cleanName = preg_replace('/\d{4}-\d{2}-\d{2}.*$/', '', $schoolName);
                            $cleanName = preg_replace('/\d{2}:\d{2}:\d{2}/', '', $cleanName);
                            $cleanName = trim($cleanName);
                            $slug = \Illuminate\Support\Str::slug($cleanName);
                            $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
                            $slug = trim($slug, '-');
                            $slug = preg_replace('/-+/', '-', $slug);

                            if (empty($slug) || strlen($slug) < 3) {
                                $slug = $tenant->subdomain ?? \Illuminate\Support\Str::slug($tenant->name ?? 'school');
                                $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
                                $slug = trim($slug, '-');
                            }

                            if (empty($slug) || strlen($slug) < 3) {
                                $slug = 'school';
                            }

                            $adminEmail = "admin@{$slug}.com";

                            // Check if admin already exists
                            $adminUser = \App\Models\User::where('email', $adminEmail)->first();

                            if (!$adminUser) {
                                // Create admin user in tenant database
                                $adminUser = \App\Models\User::create([
                                    'name' => 'Administrator',
                                    'email' => $adminEmail,
                                    'password' => \Illuminate\Support\Facades\Hash::make('Password@12345'),
                                    'role' => 'school_admin',
                                    'status' => 'active',
                                    'email_verified_at' => now(),
                                ]);

                                Log::info("Tenant admin account created after school creation", [
                                    'tenant_id' => $tenant->id,
                                    'school_id' => $tenantSchool->id,
                                    'admin_email' => $adminEmail
                                ]);
                            } else {
                                Log::info("Tenant admin account already exists", [
                                    'tenant_id' => $tenant->id,
                                    'school_id' => $tenantSchool->id,
                                    'admin_email' => $adminEmail
                                ]);
                            }

                            // Switch back to main database
                            \Illuminate\Support\Facades\Config::set('database.default', 'mysql');
                            \Illuminate\Support\Facades\DB::setDefaultConnection('mysql');
                        } catch (\Exception $adminError) {
                            Log::warning("Failed to create tenant admin account: " . $adminError->getMessage());
                            // Switch back to main database even on error
                            \Illuminate\Support\Facades\Config::set('database.default', 'mysql');
                            \Illuminate\Support\Facades\DB::setDefaultConnection('mysql');
                        }
                    }
                } catch (\Exception $e) {
                    // If tenant database creation fails, log and continue with main DB only
                    Log::warning("Failed to create school in tenant database: " . $e->getMessage());
                    $tenantSchool = null;
                    $wasRecentlyCreated = true;
                }
            }

            // Main DB payload - only basic registration info (no school-specific data)
            $mainPayload = [
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'code' => $code, // Store code in main DB for identification
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'website' => $data['website'] ?? null,
                'logo' => $logoUrl ?? $data['logo'] ?? null,
                'status' => $status,
                // Note: principal_id, vice_principal_id, academic_year, term, settings
                // are NOT stored in main DB - only in tenant DB
            ];

            $school = School::on('mysql')->updateOrCreate(
                ['tenant_id' => $tenant->id],
                $mainPayload
            );

            // Load relationships - principal/vicePrincipal only exist in tenant DB
            $school->load('tenant');

            $schoolData = $school->fresh()->toArray();

            // Ensure tenant_id is included in response
            if (!isset($schoolData['tenant_id'])) {
                $schoolData['tenant_id'] = $tenant->id;
            }

            // Add tenant school data if available (contains full school info)
            if ($tenantSchool) {
                $schoolData['principal_id'] = $tenantSchool->principal_id;
                $schoolData['vice_principal_id'] = $tenantSchool->vice_principal_id;
                $schoolData['academic_year'] = $tenantSchool->academic_year;
                $schoolData['term'] = $tenantSchool->term;
                $schoolData['settings'] = $tenantSchool->settings;
            }

            $response = [
                'message' => $wasRecentlyCreated ? 'School created successfully' : 'School updated successfully',
                'school' => $schoolData,
                'tenant' => $tenant->toArray(),
            ];

            return response()->json($response, $wasRecentlyCreated ? 201 : 200);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to create school',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * List all schools
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $tenant = $request->attributes->get('tenant');

            if (!$tenant instanceof Tenant) {
                $tenantId = $request->input('tenant_id') ?? $request->header('X-Tenant-ID');
                if ($tenantId) {
                    $tenant = Tenant::find($tenantId);
                }
            }

            // If tenant is found, switch to tenant database
            if ($tenant instanceof Tenant && $tenant->database_name) {
                $this->tenantService->switchToTenant($tenant);
            }

            // In tenant database, schools table doesn't have tenant_id
            // Each tenant database is isolated, so we just query all schools in the tenant DB
            $query = School::query();

            // Note: In tenant DB, there's no tenant_id column since each DB is isolated per tenant

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // Only load tenant relationship if we're in main DB (not tenant DB)
            if (!$tenant || !$tenant->database_name) {
                $schools = $query->with('tenant')->paginate($request->get('per_page', 15));
            } else {
                $schools = $query->paginate($request->get('per_page', 15));
            }

            return response()->json($schools);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve schools',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get school information
     */
    public function show(School $school): JsonResponse
    {
        try {
            // Try to load relationships, but don't fail if they don't exist
            $school->load(['principal', 'vicePrincipal']);

            // Get stats safely
            $stats = [];
            try {
                if (method_exists($school, 'getStats')) {
                    $stats = $school->getStats();
                } else {
                    // Fallback stats
                    $stats = [
                        'teachers' => 0,
                        'students' => 0,
                        'classes' => 0,
                    ];
                }
            } catch (\Exception $e) {
                // Stats not available, use empty array
                $stats = [];
            }

            return response()->json([
                'school' => $school,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve school',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update school information
     */
    public function update(Request $request, School $school): JsonResponse
    {
        // Handle logo file upload if provided
        $logoUrl = $request->input('logo');
        if ($request->hasFile('logo_file')) {
            try {
                $fileUploadService = app(\App\Services\FileUploadService::class);
                $uploadResult = $fileUploadService->uploadFile(
                    $request->file('logo_file'),
                    'schools/logos'
                );
                $logoUrl = $uploadResult['url'] ?? $uploadResult['key'] ?? null;

                Log::info("School logo updated", [
                    'logo_url' => $logoUrl,
                    'school_id' => $school->id
                ]);
            } catch (\Exception $e) {
                Log::warning("Failed to upload school logo: " . $e->getMessage());
                // Continue without logo if upload fails
            }
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'logo' => 'nullable|string|max:255',
            'logo_file' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,svg|max:5120', // 5MB max for logo
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
            $updateData = $request->all();
            if ($logoUrl) {
                $updateData['logo'] = $logoUrl;
            }

            $school->update($updateData);

            return response()->json([
                'message' => 'School updated successfully',
                'school' => $school->fresh()
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
        try {
            $stats = [
                'teachers' => $this->safeDbOperation(function() use ($school) {
                    return $school->teachers()->count();
                }, 0),
                'students' => $this->safeDbOperation(function() use ($school) {
                    return $school->students()->count();
                }, 0),
                'classes' => $this->safeDbOperation(function() use ($school) {
                    return $school->classes()->count();
                }, 0),
                'subjects' => $this->safeDbOperation(function() use ($school) {
                    return $school->subjects()->count();
                }, 0),
                'departments' => $this->safeDbOperation(function() use ($school) {
                    return $school->departments()->count();
                }, 0),
                'academic_years' => $this->safeDbOperation(function() use ($school) {
                    return $school->academicYears()->count();
                }, 0),
                'terms' => $this->safeDbOperation(function() use ($school) {
                    return $school->terms()->count();
                }, 0),
            ];

            return response()->json([
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'stats' => [
                    'teachers' => 0,
                    'students' => 0,
                    'classes' => 0,
                    'subjects' => 0,
                    'departments' => 0,
                    'academic_years' => 0,
                    'terms' => 0,
                ]
            ]);
        }
    }

    /**
     * Get school dashboard data
     */
    public function dashboard(School $school): JsonResponse
    {
        try {
            $currentAcademicYear = $this->safeDbOperation(function() use ($school) {
                return method_exists($school, 'getCurrentAcademicYear') ? $school->getCurrentAcademicYear() : null;
            });
            $currentTerm = $this->safeDbOperation(function() use ($school) {
                return method_exists($school, 'getCurrentTerm') ? $school->getCurrentTerm() : null;
            });

            $dashboard = [
                'school' => $school,
                'current_academic_year' => $currentAcademicYear,
                'current_term' => $currentTerm,
                'stats' => $this->safeDbOperation(function() use ($school) {
                    return method_exists($school, 'getStats') ? $school->getStats() : [
                        'teachers' => 0,
                        'students' => 0,
                        'classes' => 0,
                    ];
                }, [
                    'teachers' => 0,
                    'students' => 0,
                    'classes' => 0,
                ]),
                'recent_activities' => $this->getRecentActivities($school),
                'upcoming_events' => $this->getUpcomingEvents($school),
            ];

            return response()->json([
                'dashboard' => $dashboard
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'dashboard' => [
                    'school' => $school,
                    'current_academic_year' => null,
                    'current_term' => null,
                    'stats' => [
                        'teachers' => 0,
                        'students' => 0,
                        'classes' => 0,
                    ],
                    'recent_activities' => [],
                    'upcoming_events' => [],
                ]
            ]);
        }
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
     * Delete school
     */
    public function destroy(School $school): JsonResponse
    {
        try {
            // Prevent deletion if school has active students or teachers
            $hasStudents = Student::where('school_id', $school->id)->exists();
            $hasTeachers = Teacher::where('school_id', $school->id)->exists();

            if ($hasStudents || $hasTeachers) {
                return response()->json([
                    'error' => 'Cannot delete school',
                    'message' => 'School has associated students or teachers. Please remove them first.'
                ], 422);
            }

            $school->delete();

            return response()->json([
                'message' => 'School deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete school',
                'message' => $e->getMessage()
            ], 500);
        }
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
