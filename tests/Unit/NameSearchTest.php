<?php

namespace Tests\Unit;

use App\Support\NameSearch;
use App\Support\SpreadsheetMapping;
use PHPUnit\Framework\TestCase;

/** Invoice sheets name children however the school writes them. */
class NameSearchTest extends TestCase
{
    private function kids()
    {
        return collect([
            (object) ['id' => 1, 'first_name' => 'FOLAKUNMI', 'middle_name' => 'MICHAEL', 'last_name' => 'OWOEYE', 'class_id' => 1],
            (object) ['id' => 2, 'first_name' => 'ZURI', 'middle_name' => 'AWELE', 'last_name' => 'CHUKWUDUMLU', 'class_id' => 1],
            (object) ['id' => 3, 'first_name' => 'David', 'middle_name' => null, 'last_name' => 'Okon', 'class_id' => 1],
            (object) ['id' => 4, 'first_name' => 'David', 'middle_name' => null, 'last_name' => 'Okon', 'class_id' => 2],
        ]);
    }

    public function test_names_in_any_order_with_or_without_middle_name(): void
    {
        foreach (['Folakunmi Owoeye', 'OWOEYE Folakunmi', 'Owoeye Folakunmi Michael', 'folakunmi  michael owoeye', 'Owoeye, Folakunmi'] as $typed) {
            $this->assertSame(1, NameSearch::match($this->kids(), $typed)[0]?->id, $typed);
        }
        $this->assertSame(2, NameSearch::match($this->kids(), 'Zuri Chukwudumlu')[0]?->id);
        // A name the school never recorded is still fine when first + surname match.
        $this->assertSame(2, NameSearch::match($this->kids(), 'Zuri Adaeze Chukwudumlu')[0]?->id);
        $this->assertNull(NameSearch::match($this->kids(), 'Zuri Okon')[0]);
        $this->assertNull(NameSearch::match($this->kids(), 'Zuri')[0], 'one name is not enough');
    }

    public function test_same_name_needs_the_class(): void
    {
        [$s, $problem] = NameSearch::match($this->kids(), 'David Okon');
        $this->assertNull($s);
        $this->assertStringContainsString('More than one', $problem);
        $this->assertSame(4, NameSearch::match($this->kids(), 'David Okon', 2)[0]?->id);
    }

    public function test_split_name_columns_are_joined(): void
    {
        $rows = [['S/N', 'Surname', 'First Name', 'Other Names', 'Tuition'], ['1', 'OWOEYE', 'Folakunmi', 'Michael', '150000']];
        [$out] = SpreadsheetMapping::apply('fees', $rows, 0, ['ignore', 'name', 'name', 'name', 'line']);
        $this->assertSame(['Student Name', 'Tuition'], $out[0]);
        $this->assertSame(['OWOEYE Folakunmi Michael', '150000'], $out[1]);
    }
}
