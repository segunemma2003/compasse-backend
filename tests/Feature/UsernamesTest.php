<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Usernames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Everyone gets a username to sign in with — parents without an email included. */
class UsernamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tables = [
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('username')->nullable()->unique(), $t->string('password'), $t->string('role'), $t->timestamps(), $t->softDeletes()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('admission_number')->nullable(), $t->timestamps()],
            'teachers' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('employee_id')->nullable(), $t->timestamps()],
        ];
        foreach ($tables as $n => $def) {
            Schema::dropIfExists($n);
            Schema::create($n, $def);
        }
    }

    private function user(int $id, string $name, string $role): User
    {
        return User::forceCreate(['id' => $id, 'name' => $name, 'email' => "u{$id}@x.test", 'password' => 'x', 'role' => $role]);
    }

    public function test_usernames(): void
    {
        $p1 = $this->user(1, 'Mr. & Mrs. Bankole Owoeye', 'guardian');
        $p2 = $this->user(2, 'Bisi Owoeye', 'guardian');
        $this->assertSame('bowoeye', DB::table('users')->where('id', 1)->value('username'), 'given on creation');
        $this->assertSame('bowoeye2', DB::table('users')->where('id', 2)->value('username'), 'unique');

        $t = $this->user(3, 'Esse Egbaunu', 'teacher');
        DB::table('users')->where('id', 3)->update(['username' => null]);
        DB::table('teachers')->insert(['user_id' => 3, 'employee_id' => 'THETE0001']);
        $this->assertSame('thete0001', Usernames::ensure($t->fresh()), 'staff ID');

        $s = $this->user(4, 'Ada Okafor', 'student');
        $this->assertNull(DB::table('users')->where('id', 4)->value('username'), 'waits for the student record');
        DB::table('students')->insert(['user_id' => 4, 'admission_number' => 'QS/2026/001']);
        $this->assertSame('qs/2026/001', Usernames::ensure($s->fresh()), 'admission number');
        $this->assertSame('qs/2026/001', Usernames::ensure($s->fresh()), 'kept');

        DB::table('users')->update(['username' => null]);
        $this->assertSame(4, Usernames::assignMissing());
    }
}
