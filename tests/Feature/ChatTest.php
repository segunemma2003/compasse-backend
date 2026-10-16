<?php

namespace Tests\Feature;

use App\Http\Controllers\MessageController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Chat list: last message and unread count per person; opening a chat marks it read. */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('users');
        Schema::create('users', fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('password'), $t->string('role'), $t->string('status')->default('active'), $t->boolean('email_generated')->default(false), $t->timestamps(), $t->softDeletes()]);
        Schema::dropIfExists('messages');
        Schema::create('messages', fn ($t) => [$t->id(), $t->unsignedBigInteger('sender_id'), $t->unsignedBigInteger('receiver_id'), $t->string('subject')->nullable(), $t->text('body'), $t->string('type')->nullable(), $t->boolean('is_read')->default(false), $t->timestamp('read_at')->nullable(), $t->timestamps()]);
        Schema::dropIfExists('notifications');
        Schema::create('notifications', fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('title')->nullable(), $t->text('message')->nullable(), $t->string('type')->nullable(), $t->boolean('is_read')->default(false), $t->timestamps()]);
        foreach ([[1, 'school_admin', 'Ada'], [2, 'staff', 'Bola'], [3, 'staff', 'Chi']] as [$id, $role, $name]) {
            User::forceCreate(['id' => $id, 'name' => $name, 'email' => "u{$id}@school.test", 'password' => 'x', 'role' => $role]);
        }
        DB::table('messages')->insert([
            ['sender_id' => 2, 'receiver_id' => 1, 'body' => 'Hello', 'is_read' => false, 'created_at' => now()->subMinutes(5)],
            ['sender_id' => 2, 'receiver_id' => 1, 'body' => 'Are you there?', 'is_read' => false, 'created_at' => now()],
        ]);
    }

    private function req(int $uid, array $q = []): Request
    {
        $u = User::find($uid);
        $this->actingAs($u);
        $r = Request::create('/', 'GET', $q);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_chat_list_and_thread(): void
    {
        $c = new MessageController();
        $chats = collect($c->chats($this->req(1))->getData(true)['chats']);
        $bola = $chats->firstWhere('id', 2);
        $this->assertSame(2, $bola['unread']);
        $this->assertSame('Are you there?', $bola['last_message']);
        $this->assertSame(2, $chats->first()['id'], 'the latest conversation comes first');

        $thread = $c->thread($this->req(1), 2)->getData(true);
        $this->assertCount(2, $thread['messages']);
        $this->assertSame(0, DB::table('messages')->where('receiver_id', 1)->where('is_read', false)->count());

        $this->assertCount(0, $c->chats($this->req(1, ['unread' => 1]))->getData(true)['chats']);
    }
}
