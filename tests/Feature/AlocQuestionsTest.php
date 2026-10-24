<?php

namespace Tests\Feature;

use App\Support\AlocQuestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** ALOC past questions land in the central bank once, with options, answer and year; schools can be given access. */
class AlocQuestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_import(): void
    {
        if (! Schema::hasTable('question_bank_questions')) {
            $this->markTestSkipped('central question bank tables not migrated in this test database');
        }
        $uid = DB::table('users')->insertGetId(['name' => 'Super', 'email' => 's@x.test', 'password' => 'x', 'role' => 'super_admin', 'created_at' => now(), 'updated_at' => now()]);
        $q = fn ($id) => ['id' => $id, 'question' => "<p>What is H<sub>2</sub>O? #{$id}</p>", 'option' => ['a' => 'Water', 'b' => 'Salt', 'c' => 'Acid', 'd' => 'Base', 'e' => ''],
            'section' => 'Compounds', 'image' => '', 'answer' => 'a', 'solution' => 'Hydrogen and oxygen', 'examtype' => 'utme', 'examyear' => '2010'];
        Http::fake(['questions.aloc.com.ng/*' => Http::sequence()->push(['subject' => 'chemistry', 'status' => 200, 'data' => [$q(1), $q(2)]])->push(['subject' => 'chemistry', 'status' => 200, 'data' => [$q(2), $q(3)]])]);

        $r = AlocQuestions::import('chemistry', 'utme', null, 2, $uid, 'ALOC-abc');
        $this->assertSame(3, $r['added']);
        $this->assertSame(1, $r['skipped'], 'question 2 came twice');
        Http::assertSent(fn ($req) => $req->hasHeader('AccessToken', 'ALOC-abc') && str_contains($req->url(), 'subject=chemistry') && str_contains($req->url(), 'type=utme'));
        $row = DB::table('question_bank_questions')->first();
        $this->assertSame('jamb', $row->curriculum_type);
        $this->assertSame('"A"', $row->correct_answer);
        $this->assertCount(4, json_decode($row->options, true), 'empty option e dropped');
        $this->assertStringContainsString('H<sub>2</sub>O', $row->question_text);
        $this->assertSame('Chemistry', DB::table('question_bank_subjects')->value('name'));
    }

    public function test_bad_token_message(): void
    {
        if (! Schema::hasTable('question_bank_questions')) {
            $this->markTestSkipped('central question bank tables not migrated');
        }
        Http::fake(['questions.aloc.com.ng/*' => Http::response(['status' => 406, 'error' => 'Access token not valid or deactivated'], 406)]);
        $this->expectExceptionMessage('Access token not valid');
        AlocQuestions::import('english', 'wassce', null, 1, 1, 'bad');
    }
}
