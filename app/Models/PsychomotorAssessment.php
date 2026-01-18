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
        // Standard Psychomotor (Hardcoded)
        'handwriting',
        'drawing',
        'sports',
        'musical_skills',
        'handling_tools',
        // Standard Affective (Hardcoded)
        'punctuality',
        'neatness',
        'politeness',
        'honesty',
        'relationship_with_others',
        'self_control',
        'attentiveness',
        'perseverance',
        'emotional_stability',
        // Dynamic Fields (JSON)
        'custom_psychomotor',
        'custom_affective',
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
        'custom_psychomotor' => 'array',  // Dynamic skills
        'custom_affective' => 'array',     // Dynamic traits
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
        // Standard skills
        $skills = [
            $this->handwriting,
            $this->drawing,
            $this->sports,
            $this->musical_skills,
            $this->handling_tools,
        ];
        
        // Add custom psychomotor skills
        if ($this->custom_psychomotor && is_array($this->custom_psychomotor)) {
            $skills = array_merge($skills, array_values($this->custom_psychomotor));
        }
        
        $skills = array_filter($skills);
        return count($skills) > 0 ? round(array_sum($skills) / count($skills), 2) : 0;
    }

    public function getAffectiveAverage(): float
    {
        // Standard traits
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
        
        // Add custom affective traits
        if ($this->custom_affective && is_array($this->custom_affective)) {
            $traits = array_merge($traits, array_values($this->custom_affective));
        }
        
        $traits = array_filter($traits);
        return count($traits) > 0 ? round(array_sum($traits) / count($traits), 2) : 0;
    }

    /**
     * Get all psychomotor skills (standard + custom)
     */
    public function getAllPsychomotorSkills(): array
    {
        $standard = [
            'handwriting' => $this->handwriting,
            'drawing' => $this->drawing,
            'sports' => $this->sports,
            'musical_skills' => $this->musical_skills,
            'handling_tools' => $this->handling_tools,
        ];
        
        $custom = $this->custom_psychomotor ?? [];
        
        return array_merge($standard, $custom);
    }

    /**
     * Get all affective traits (standard + custom)
     */
    public function getAllAffectiveTraits(): array
    {
        $standard = [
            'punctuality' => $this->punctuality,
            'neatness' => $this->neatness,
            'politeness' => $this->politeness,
            'honesty' => $this->honesty,
            'relationship_with_others' => $this->relationship_with_others,
            'self_control' => $this->self_control,
            'attentiveness' => $this->attentiveness,
            'perseverance' => $this->perseverance,
            'emotional_stability' => $this->emotional_stability,
        ];
        
        $custom = $this->custom_affective ?? [];
        
        return array_merge($standard, $custom);
    }
}

