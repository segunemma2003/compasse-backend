<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PsychomotorAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'term_id',
        'academic_year_id',
        'assessed_by',
        // Psychomotor
        'handwriting',
        'drawing',
        'sports',
        'musical_skills',
        'handling_tools',
        // Affective
        'punctuality',
        'neatness',
        'politeness',
        'honesty',
        'relationship_with_others',
        'self_control',
        'attentiveness',
        'perseverance',
        'emotional_stability',
        'teacher_comment',
    ];

    protected $casts = [
        'handwriting' => 'integer',
        'drawing' => 'integer',
        'sports' => 'integer',
        'musical_skills' => 'integer',
        'handling_tools' => 'integer',
        'punctuality' => 'integer',
        'neatness' => 'integer',
        'politeness' => 'integer',
        'honesty' => 'integer',
        'relationship_with_others' => 'integer',
        'self_control' => 'integer',
        'attentiveness' => 'integer',
        'perseverance' => 'integer',
        'emotional_stability' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'assessed_by');
    }

    public function getPsychomotorAverage(): float
    {
        $skills = [
            $this->handwriting,
            $this->drawing,
            $this->sports,
            $this->musical_skills,
            $this->handling_tools,
        ];
        $skills = array_filter($skills);
        return count($skills) > 0 ? round(array_sum($skills) / count($skills), 2) : 0;
    }

    public function getAffectiveAverage(): float
    {
        $traits = [
            $this->punctuality,
            $this->neatness,
            $this->politeness,
            $this->honesty,
            $this->relationship_with_others,
            $this->self_control,
            $this->attentiveness,
            $this->perseverance,
            $this->emotional_stability,
        ];
        $traits = array_filter($traits);
        return count($traits) > 0 ? round(array_sum($traits) / count($traits), 2) : 0;
    }
}

