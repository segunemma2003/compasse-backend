<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RecycleBinController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Deleting a payment takes it off the fee; restoring it from the recycle bin puts it back. */
class RecycleBinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tables = [
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('password'), $t->string('role'), $t->timestamps(), $t->softDeletes()],
            'schools' => fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->timestamps()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->string('admission_number')->nullable(), $t->timestamps(), $t->softDeletes()],
            'fees' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->unsignedBigInteger('student_id')->nullable(), $t->string('fee_type')->nullable(), $t->decimal('amount', 12, 2), $t->decimal('amount_paid', 12, 2)->default(0), $t->decimal('balance', 12, 2)->default(0), $t->string('status')->nullable(), $t->timestamps()],
            'payments' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->unsignedBigInteger('student_id')->nullable(), $t->unsignedBigInteger('fee_id')->nullable(), $t->decimal('amount', 12, 2), $t->string('payment_method')->nullable(), $t->string('payment_reference')->nullable(), $t->date('payment_date')->nullable(), $t->string('status')->nullable(), $t->text('notes')->nullable(), $t->timestamps()],
        ];
        foreach ($tables as $name => $def) {
            Schema::dropIfExists($name);
            Schema::create($name, $def);
        }
        Schema::dropIfExists('recycle_bin');
        (require database_path('migrations/tenant/2026_10_14_000004_create_recycle_bin_table.php'))->up();

        DB::table('schools')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('students')->insert(['id' => 1, 'first_name' => 'Ada', 'last_name' => 'O']);
        DB::table('fees')->insert(['id' => 1, 'school_id' => 1, 'student_id' => 1, 'fee_type' => 'tuition', 'amount' => 1000, 'amount_paid' => 600, 'balance' => 400, 'status' => 'partial']);
        DB::table('payments')->insert(['id' => 7, 'school_id' => 1, 'student_id' => 1, 'fee_id' => 1, 'amount' => 600, 'status' => 'confirmed', 'payment_reference' => 'PAY-7']);
        User::forceCreate(['id' => 1, 'name' => 'Admin', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'school_admin']);
    }

    private function req(string $method = 'GET', array $data = []): Request
    {
        $u = User::find(1);
        $this->actingAs($u);
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_delete_and_restore_a_payment(): void
    {
        $this->assertSame(422, (new PaymentController())->destroy($this->req('DELETE'), 7)->getStatusCode(), 'a reason is required');
        User::forceCreate(['id' => 2, 'name' => 'Bursar', 'email' => 'b@example.com', 'password' => 'x', 'role' => 'accountant']);
        $bursar = User::find(2);
        $r = Request::create('/', 'DELETE', ['reason' => 'entered twice']);
        $r->setUserResolver(fn () => $bursar);
        $this->assertSame(403, (new PaymentController())->destroy($r, 7)->getStatusCode(), 'only the school administrator');
        $res = (new PaymentController())->destroy($this->req('DELETE', ['reason' => 'entered twice']), 7);
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame(0, DB::table('payments')->count());
        $fee = DB::table('fees')->find(1);
        $this->assertEquals(0, $fee->amount_paid);
        $this->assertSame('pending', $fee->status);

        $bin = new RecycleBinController();
        $items = $bin->index($this->req())->getData(true)['items'];
        $this->assertCount(1, $items);
        $this->assertStringContainsString('Ada', $items[0]['label']);

        $this->assertSame(200, $bin->restore($this->req('POST', ['key' => $items[0]['key']]))->getStatusCode());
        $this->assertSame(1, DB::table('payments')->where('id', 7)->count());
        $fee = DB::table('fees')->find(1);
        $this->assertEquals(600, $fee->amount_paid);
        $this->assertSame('partial', $fee->status);
        $this->assertCount(0, $bin->index($this->req())->getData(true)['items']);
    }
}
