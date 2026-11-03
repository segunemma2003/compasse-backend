<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Name search and matching that copes with full names: "Ada Obi", "OBI Ada" or
 * "Ada Chioma Obi" all find the student with first name Ada and surname Obi.
 */
class NameSearch
{
    /** Lower-case words of a name, punctuation dropped. */
    public static function words(?string $s): array
    {
        $s = mb_strtolower(trim((string) $s));
        $s = preg_replace('/[^\p{L}\p{N}\'\-]+/u', ' ', $s);

        return array_values(array_unique(array_filter(explode(' ', $s), fn ($w) => $w !== '' && $w !== '-')));
    }

    /**
     * Every word of $search must appear in one of $columns, e.g. "ada obi" →
     * (first_name like %ada% or last_name like %ada% …) and (… %obi% …).
     */
    public static function apply(EloquentBuilder|Builder $query, ?string $search, array $columns): EloquentBuilder|Builder
    {
        $words = self::words($search);
        if (! $words) {
            return $query;
        }

        return $query->where(function ($q) use ($words, $columns) {
            foreach ($words as $w) {
                $like = '%' . addcslashes($w, '%_\\') . '%';
                $q->where(function ($any) use ($columns, $like) {
                    foreach ($columns as $c) {
                        $any->orWhere($c, 'like', $like);
                    }
                });
            }
        });
    }

    /**
     * The one student a typed name means, among $students (each with first_name,
     * last_name, optional middle_name / class_id). Order of the names doesn't matter,
     * and a middle name may be given or left out. $classId narrows ties.
     *
     * @return array{0: mixed, 1: ?string} [student or null, problem or null]
     */
    public static function match(Collection $students, ?string $typed, ?int $classId = null): array
    {
        $want = self::words($typed);
        if (! $want) {
            return [null, null];
        }
        $core = fn ($s) => self::words("{$s->first_name} {$s->last_name}");
        $all = fn ($s) => self::words("{$s->first_name} " . ($s->middle_name ?? '') . " {$s->last_name}");

        $tiers = [
            // exactly the same names (any order)
            fn ($s) => self::sameSet($all($s), $want) || self::sameSet($core($s), $want),
            // the sheet gives first + surname (+ maybe middle) — all of them are this student's names
            fn ($s) => count($want) >= 2 && ! array_diff($want, $all($s)) && ! array_diff($core($s), $want),
            // the sheet adds a name the school didn't record, or leaves out the middle name
            fn ($s) => count($want) >= 2 && (! array_diff($core($s), $want) || ! array_diff($want, $all($s))),
        ];

        foreach ($tiers as $test) {
            $hits = $students->filter($test)->values();
            if ($hits->count() > 1 && $classId) {
                $inClass = $hits->where('class_id', $classId)->values();
                $hits = $inClass->isNotEmpty() ? $inClass : $hits;
            }
            if ($hits->count() === 1) {
                return [$hits->first(), null];
            }
            if ($hits->count() > 1) {
                return [null, 'More than one student matches "' . trim((string) $typed) . '" — add the admission number or the class.'];
            }
        }

        return [null, null];
    }

    private static function sameSet(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
