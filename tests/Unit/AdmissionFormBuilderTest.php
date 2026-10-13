<?php

namespace Tests\Unit;

use App\Http\Controllers\PublicAdmissionController;
use App\Models\AdmissionCycle;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use ReflectionMethod;
use Tests\TestCase;

/** Admission cycles with several classes and the school's own form fields. */
class AdmissionFormBuilderTest extends TestCase
{
    public function test_fields_are_normalised_with_unique_keys(): void
    {
        $fields = AdmissionCycle::normaliseFields([
            ['label' => 'Birth certificate', 'type' => 'file', 'required' => true],
            ['label' => 'Religion', 'type' => 'select', 'options' => ['Christianity', ' Islam ', '']],
            ['label' => 'Religion', 'type' => 'bogus'],
            ['label' => '   '],
        ]);

        $this->assertCount(3, $fields, 'a blank label is dropped');
        $this->assertSame('birth_certificate', $fields[0]['key']);
        $this->assertSame(['Christianity', 'Islam'], $fields[1]['options']);
        $this->assertSame('religion_2', $fields[2]['key'], 'duplicate labels get distinct keys');
        $this->assertSame('text', $fields[2]['type'], 'unknown types fall back to text');
    }

    public function test_class_ids_fall_back_to_the_single_class(): void
    {
        $this->assertSame([3, 4], (new AdmissionCycle(['class_ids' => ['3', 4], 'class_id' => 9]))->classIds());
        $this->assertSame([9], (new AdmissionCycle(['class_id' => 9]))->classIds());
    }

    private function collect(Request $request, array $fields): array
    {
        $m = new ReflectionMethod(PublicAdmissionController::class, 'collectCustomFields');
        $m->setAccessible(true);

        return $m->invoke(app(PublicAdmissionController::class), $request, $fields);
    }

    public function test_custom_answers_are_validated(): void
    {
        $fields = AdmissionCycle::normaliseFields([
            ['label' => 'Allergies', 'type' => 'textarea'],
            ['label' => 'Religion', 'type' => 'select', 'options' => ['Christianity', 'Islam']],
            ['label' => 'I confirm', 'type' => 'checkbox', 'required' => true],
            ['label' => 'Birth certificate', 'type' => 'file', 'required' => true],
        ]);

        [, , $error] = $this->collect(Request::create('/', 'POST', ['custom' => ['religion' => 'Islam', 'i_confirm' => '1']]), $fields);
        $this->assertSame('Please upload: Birth certificate.', $error);

        [, , $error] = $this->collect(Request::create('/', 'POST', ['custom' => ['religion' => 'Other', 'i_confirm' => '1']]), $fields);
        $this->assertStringContainsString('Religion', $error);

        $file = UploadedFile::fake()->create('birth.pdf', 100, 'application/pdf');
        $request = Request::create('/', 'POST', ['custom' => ['allergies' => ' Peanuts ', 'religion' => 'Islam', 'i_confirm' => '1']], [], ['files' => ['birth_certificate' => $file]]);
        [$answers, $uploads, $error] = $this->collect($request, $fields);

        $this->assertNull($error);
        $this->assertSame(['allergies' => 'Peanuts', 'religion' => 'Islam', 'i_confirm' => true], $answers);
        $this->assertArrayHasKey('birth_certificate', $uploads);
    }
}
