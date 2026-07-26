<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['project_id', 'status', 'build_log', 'duration_seconds'])]
class Deployment extends Model
{
    use HasFactory, HasUuids;

    protected $casts = [
        'duration_seconds' => 'integer',
    ];

    /**
     * Get the project that this deployment belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
