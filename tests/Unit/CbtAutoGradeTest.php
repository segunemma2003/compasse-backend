<?php

namespace Tests\Unit;

use App\Models\Question;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** CBT auto-grading accepts every shape a correct answer has been saved in. */
class CbtAutoGradeTest extends TestCase
{
    private function q(string $type, $options, $correct): Question
    {
        return new Question(['question_type' => $type, 'options' => $options, 'correct_answer' => $correct, 'marks' => 1]);
    }

    public static function cases(): array
    {
        $objects = [['text' => '3', 'is_correct' => false], ['text' => '4', 'is_correct' => true], ['text' => '5'], ['text' => '6']];

        return [
            'exam builder: ["4"]' => ['multiple_choice', $objects, ['4'], ['4'], true],
            'wrong option' => ['multiple_choice', $objects, ['4'], ['5'], false],
            'seeded: plain string "30"' => ['multiple_choice', ['22', '27', '30', '33'], '30', ['30'], true],
            'letter "B"' => ['multiple_choice', $objects, ['B'], ['4'], true],
            'only is_correct flags' => ['multiple_choice', $objects, [], ['4'], true],
            'option_text objects, case + spaces' => ['multiple_choice', [['option_text' => 'Yes'], ['option_text' => 'No']], ['YES'], [' yes '], true],
            'image-only options use letters' => ['multiple_choice', [['option_text' => '', 'media_url' => 'a.png'], ['option_text' => '', 'media_url' => 'b.png']], ['B'], ['B'], true],
            'true/false any case' => ['true_false', null, ['true'], ['True'], true],
            'true/false wrong' => ['true_false', null, ['True'], ['False'], false],
            'fill blank ignores case' => ['fill_blank', null, ['Abuja'], ['  abuja'], true],
            'fill blank accepts alternatives' => ['fill_blank', null, ['Abuja', 'FCT'], ['fct'], true],
            'empty answer' => ['multiple_choice', $objects, ['4'], [''], false],
        ];
    }

    #[DataProvider('cases')]
    public function test_grading(string $type, $options, $correct, array $given, bool $expected): void
    {
        $this->assertSame($expected, $this->q($type, $options, $correct)->isCorrectAnswer($given));
    }
}
