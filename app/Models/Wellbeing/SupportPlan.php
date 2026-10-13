<?php

namespace App\Models\Wellbeing;

use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportPlan extends Model
{
    protected $fillable = ['student_id', 'title', 'goals', 'start_date', 'review_date', 'status', 'created_by'];

    protected $casts = ['goals' => 'array', 'start_date' => 'date:Y-m-d', 'review_date' => 'date:Y-m-d'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
