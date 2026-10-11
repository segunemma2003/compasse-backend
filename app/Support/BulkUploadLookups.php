<?php

namespace App\Support;

use App\Models\Arm;
use App\Models\ClassModel;
use App\Models\Department;
use App\Models\Exam;
use App\Models\Subject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lets bulk upload spreadsheets refer to classes, arms, departments,
 * subjects and exams by the names admins actually see ("JSS 1", "A",
 * "Sciences", "Mathematics") instead of database ids they have no way to
 * look up. A purely numeric value that matches no name is still accepted
 * as an id, so older templates keep working.
 *
 * One instance per upload: each table is loaded once and matched in memory.
 */
class BulkUploadLookups
{
    /** @var array<string, Collection> */
    private array $cache = [];

    public function classId(?string $value): ?int
    {
        return $this->match('class', $this->classes(), $value)?->id;
    }

    /**
     * Arms are shared between classes; when the class is known, prefer an
     * arm actually attached to it.
     */
    public function armId(?string $value, ?int $classId): ?int
    {
        $arms = $this->arms();
        if ($classId) {
            $armIds = $this->cache['class_arm'][$classId] ??= DB::table('class_arm')->where('class_id', $classId)->pluck('arm_id')->all();
            $linked = $arms->whereIn('id', $armIds);
            if ($linked->isNotEmpty() && $this->find($linked, $value)) {
                return $this->find($linked, $value)->id;
            }
        }

        return $this->match('arm', $arms, $value)?->id;
    }

    public function departmentId(?string $value): ?int
    {
        return $this->match('department', $this->departments(), $value)?->id;
    }

    /**
     * Subjects are usually per class ("Mathematics" exists for every class),
     * so the student's class narrows a name down to the right one. Codes
     * (e.g. "MTH") are accepted too.
     */
    public function subjectId(?string $value, ?int $classId): ?int
    {
        $subjects = $this->subjects();
        if ($classId) {
            $forClass = $subjects->where('class_id', $classId);
            if ($hit = $this->find($forClass, $value, ['name', 'code'])) {
                return $hit->id;
            }
        }

        return $this->match('subject', $subjects, $value, ['name', 'code'])?->id;
    }

    /**
     * An exam by name, narrowed to the student's class and (when given) term.
     */
    public function exam(?string $value, ?int $classId, ?int $termId): ?Exam
    {
        $value = $this->clean($value);
        if ($value === '') {
            return null;
        }

        $exams = Exam::query()
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->when($termId, fn ($q) => $q->where('term_id', $termId))
            ->get(['id', 'name', 'subject_id', 'class_id', 'term_id']);

        if ($hit = $this->find($exams, $value)) {
            return $hit;
        }
        if (ctype_digit($value) && ($byId = Exam::find((int) $value))) {
            return $byId;
        }

        throw new \Exception("Exam \"{$value}\" not found" . ($classId ? " for this student's class" : '') . '.');
    }

    /**
     * Valid names per column, shown next to the upload so admins can copy them.
     *
     * @return array<string, string[]>
     */
    public function options(string $type): array
    {
        return match ($type) {
            'students' => [
                'class' => $this->classes()->pluck('name')->values()->all(),
                'arm'   => $this->arms()->pluck('name')->unique()->values()->all(),
            ],
            'teachers' => [
                'department' => $this->departments()->pluck('name')->values()->all(),
            ],
            'scores' => [
                'subject' => $this->subjects()->map(fn ($s) => $s->code ? "{$s->name} ({$s->code})" : $s->name)->unique()->values()->all(),
            ],
            default => [],
        };
    }

    /** First real name for a column, used in the downloadable example row. */
    public function firstName(string $kind): ?string
    {
        return match ($kind) {
            'class'      => $this->classes()->first()?->name,
            'arm'        => $this->arms()->first()?->name,
            'department' => $this->departments()->first()?->name,
            'subject'    => $this->subjects()->first()?->name,
            default      => null,
        };
    }

    // ─────────────────────────────────────────────────────────────────────

    private function classes(): Collection
    {
        return $this->cache['classes'] ??= ClassModel::orderBy('name')->get(['id', 'name']);
    }

    private function arms(): Collection
    {
        return $this->cache['arms'] ??= Arm::orderBy('name')->get(['id', 'name']);
    }

    private function departments(): Collection
    {
        return $this->cache['departments'] ??= Department::orderBy('name')->get(['id', 'name']);
    }

    private function subjects(): Collection
    {
        return $this->cache['subjects'] ??= Subject::orderBy('name')->get(['id', 'name', 'code', 'class_id']);
    }

    /**
     * Blank → null; a known name (or numeric id) → the record; anything
     * else → a row error that lists what the school actually has.
     */
    private function match(string $label, Collection $rows, ?string $value, array $fields = ['name'])
    {
        $value = $this->clean($value);
        if ($value === '') {
            return null;
        }

        if ($hit = $this->find($rows, $value, $fields)) {
            return $hit;
        }

        $valid = $rows->pluck('name')->unique()->take(15)->implode(', ');
        throw new \Exception(ucfirst($label) . " \"{$value}\" not found." . ($valid ? " Use one of: {$valid}" : " No {$label}s have been set up yet."));
    }

    private function find(Collection $rows, ?string $value, array $fields = ['name'])
    {
        $needle = $this->normalize($value);
        if ($needle === '') {
            return null;
        }

        foreach ($fields as $field) {
            $hit = $rows->first(fn ($r) => $this->normalize($r->{$field} ?? '') === $needle);
            if ($hit) {
                return $hit;
            }
        }

        return ctype_digit($needle) ? $rows->firstWhere('id', (int) $needle) : null;
    }

    private function clean(?string $value): string
    {
        return trim((string) $value);
    }

    /** "JSS 1", "jss1" and "JSS-1" all compare equal. */
    private function normalize(?string $value): string
    {
        return strtolower(preg_replace('/[\s\-_.]+/', '', (string) $value));
    }
}
