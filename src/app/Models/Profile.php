<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['user_id', 'bio', 'phone', 'github_username', 'linkedin_url', 'skills', 'education', 'cv_pdf_path'])]
class Profile extends Model
{
    use HasFactory, HasUuids;

    protected $casts = [
        'skills' => 'array',
        'education' => 'array',
    ];

    /**
     * Get the student (user) that owns the profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
