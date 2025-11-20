<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\ClassModel;
use App\Models\Subject;
use App\Models\Department;
use App\Models\AcademicYear;
use App\Models\Term;
use App\Models\Guardian;
use App\Models\Livestream;
use Illuminate\Support\Facades\Artisan;

class RouteTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $tenant;
    protected $school;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->tenant = Tenant::factory()->create();
        $this->school = School::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'school_admin'
        ]);

        // Generate token
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    /**
     * Test health check route
     */
    public function test_health_check_route()
    {
        $response = $this->get('/api/health');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'ok'
        ]);
    }

    /**
     * Test authentication routes
     */
    public function test_auth_routes()
    {
        // Test login
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
            'tenant_id' => $this->tenant->id,
            'school_id' => $this->school->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'user',
            'token'
        ]);

        // Test me endpoint
        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'user'
        ]);
    }

    /**
     * Test school routes
     */
    public function test_school_routes()
    {
        $response = $this->getJson('/api/v1/schools/' . $this->school->id, [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'school'
        ]);
    }

    /**
     * Test student routes
     */
    public function test_student_routes()
    {
        $student = Student::factory()->create([
            'school_id' => $this->school->id
        ]);

        // Test index
        $response = $this->getJson('/api/v1/students', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'students'
        ]);

        // Test show
        $response = $this->getJson('/api/v1/students/' . $student->id, [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'student'
        ]);
    }

    /**
     * Test teacher routes
     */
    public function test_teacher_routes()
    {
        $teacher = Teacher::factory()->create([
            'school_id' => $this->school->id
        ]);

        // Test index
        $response = $this->getJson('/api/v1/teachers', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'teachers'
        ]);

        // Test show
        $response = $this->getJson('/api/v1/teachers/' . $teacher->id, [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'teacher'
        ]);
    }

    /**
     * Test class routes
     */
    public function test_class_routes()
    {
        $class = ClassModel::factory()->create([
            'school_id' => $this->school->id
        ]);

        // Test index
        $response = $this->getJson('/api/v1/classes', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'classes'
        ]);

        // Test show
        $response = $this->getJson('/api/v1/classes/' . $class->id, [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'class'
        ]);
    }

    /**
     * Test subject routes
     */
    public function test_subject_routes()
    {
        $subject = Subject::factory()->create([
            'school_id' => $this->school->id
        ]);

        // Test index
        $response = $this->getJson('/api/v1/subjects', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'subjects'
        ]);

        // Test show
        $response = $this->getJson('/api/v1/subjects/' . $subject->id, [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'subject'
        ]);
    }

    /**
     * Test guardian routes
     */
    public function test_guardian_routes()
    {
        $guardian = Guardian::factory()->create([
            'school_id' => $this->school->id
        ]);

        // Test index
        $response = $this->getJson('/api/v1/guardians', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'guardians'
        ]);

        // Test show
        $response = $this->getJson('/api/v1/guardians/' . $guardian->id, [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'guardian'
        ]);
    }

    /**
     * Test livestream routes
     */
    public function test_livestream_routes()
    {
        $livestream = Livestream::factory()->create([
            'school_id' => $this->school->id,
            'teacher_id' => Teacher::factory()->create(['school_id' => $this->school->id])->id,
            'class_id' => ClassModel::factory()->create(['school_id' => $this->school->id])->id,
            'subject_id' => Subject::factory()->create(['school_id' => $this->school->id])->id,
        ]);

        // Test index
        $response = $this->getJson('/api/v1/livestreams', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'livestreams'
        ]);

        // Test show
        $response = $this->getJson('/api/v1/livestreams/' . $livestream->id, [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'livestream'
        ]);
    }

    /**
     * Test subscription routes
     */
    public function test_subscription_routes()
    {
        // Test plans
        $response = $this->getJson('/api/v1/subscriptions/plans', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'plans'
        ]);

        // Test modules
        $response = $this->getJson('/api/v1/subscriptions/modules', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'modules'
        ]);
    }

    /**
     * Test file upload routes
     */
    public function test_file_upload_routes()
    {
        // Test presigned URLs
        $response = $this->getJson('/api/v1/uploads/presigned-urls', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ], [
            'type' => 'profile_picture',
            'entity_type' => 'student',
            'entity_id' => 1
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'upload_urls'
        ]);
    }

    /**
     * Test report routes
     */
    public function test_report_routes()
    {
        // Test academic report
        $response = $this->getJson('/api/v1/reports/academic', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'report'
        ]);

        // Test financial report
        $response = $this->getJson('/api/v1/reports/financial', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'report'
        ]);
    }

    /**
     * Test unauthorized access
     */
    public function test_unauthorized_access()
    {
        // Test without token
        $response = $this->getJson('/api/v1/students');
        $response->assertStatus(401);

        // Test without tenant ID
        $response = $this->getJson('/api/v1/students', [
            'Authorization' => 'Bearer ' . $this->token
        ]);
        $response->assertStatus(400);
    }

    /**
     * Test invalid routes
     */
    public function test_invalid_routes()
    {
        $response = $this->getJson('/api/v1/invalid-route', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(404);
    }

    /**
     * Test rate limiting
     */
    public function test_rate_limiting()
    {
        // Make multiple requests to test rate limiting
        for ($i = 0; $i < 10; $i++) {
            $response = $this->getJson('/api/v1/health');
            $response->assertStatus(200);
        }
    }

    /**
     * Test middleware
     */
    public function test_middleware()
    {
        // Test tenant middleware
        $response = $this->getJson('/api/v1/students', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => 'invalid-tenant'
        ]);

        $response->assertStatus(400);

        // Test module middleware
        $response = $this->getJson('/api/v1/students', [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenant->id
        ]);

        $response->assertStatus(200);
    }
}
