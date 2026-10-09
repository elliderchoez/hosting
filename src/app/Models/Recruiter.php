<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;

#[Fillable([
    'account_type',
    'name',
    'email',
    'company',
    'position',
    'tax_id',
    'website_url',
    'linkedin_url',
    'phone',
    'password',
    'verified',
    'status',
    'rejection_reason',
    'reviewed_at',
    'reviewed_by',
])]
#[Hidden(['password', 'remember_token'])]
class Recruiter extends Authenticatable
{
    use HasFactory, HasUuids;

    protected $casts = [
        'verified' => 'boolean',
        'reviewed_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Check if account belongs to a formal company.
     */
    public function isCompany(): bool
    {
        return $this->account_type === 'company';
    }

    /**
     * Check if account belongs to an independent recruiter.
     */
    public function isRecruiter(): bool
    {
        return $this->account_type === 'recruiter';
    }

    /**
     * Check if the account has been approved by admin.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved' || $this->verified === true;
    }

    /**
     * Check if the account registration is pending review.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the account was rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * The admin user who reviewed this recruiter/company application.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Get the contact logs sent by this recruiter.
     */
    public function contactLogs(): HasMany
    {
        return $this->hasMany(ContactLog::class);
    }
}
