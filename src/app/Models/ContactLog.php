<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['recruiter_id', 'student_id', 'message'])]
class ContactLog extends Model
{
    use HasFactory, HasUuids;

    // This table only has created_at timestamp
    const UPDATED_AT = null;

    /**
     * Get the recruiter who sent the message.
     */
    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(Recruiter::class);
    }

    /**
     * Get the student who received the message.
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
