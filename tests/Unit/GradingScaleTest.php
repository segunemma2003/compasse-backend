<?php

namespace Tests\Unit;

use App\Models\GradingSystem;
use Tests\TestCase;

/** Scores between two bands (79.5 between 70–79 and 80–100) take the lower band, not F. */
class GradingScaleTest extends TestCase
{
    public function test_decimal_scores_between_bands(): void
    {
        $g = new GradingSystem(['grade_boundaries' => [
            ['grade' => 'A1', 'point' => 8, 'min' => 80, 'max' => 100, 'remark' => 'Distinction'],
            ['grade' => 'B2', 'point' => 7, 'min' => 70, 'max' => 79, 'remark' => 'Excellent'],
            ['grade' => 'F9', 'point' => 0, 'min' => 0, 'max' => 39, 'remark' => 'Fail'],
        ]]);
        $this->assertSame('B2', $g->getGrade(79.5)['grade']);
        $this->assertSame('A1', $g->getGrade(80)['grade']);
        $this->assertSame(7, $g->getGrade(70)['point']);
        $this->assertSame('F9', $g->getGrade(12)['grade']);
    }
}
