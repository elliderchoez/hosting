<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'recruiter_id',
    'student_id',
    'sender_name',
    'sender_email',
    'sender_company',
    'message',
    'reply',
    'replied_at',
    'read_at',
])]
class ContactLog extends Model
{
    use HasFactory, HasUuids;

    // This table only has created_at timestamp
    const UPDATED_AT = null;

    protected $casts = [
        'created_at' => 'datetime',
        'replied_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /**
     * Scope to get only unread messages.
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Get the recruiter who sent the message (if registered).
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
