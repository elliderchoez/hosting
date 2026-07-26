<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;

#[Fillable(['name', 'email', 'company', 'position', 'password', 'verified'])]
#[Hidden(['password'])]
class Recruiter extends Authenticatable
{
    use HasFactory, HasUuids;

    protected $casts = [
        'verified' => 'boolean',
    ];

    /**
     * Get the contact logs sent by this recruiter.
     */
    public function contactLogs(): HasMany
    {
        return $this->hasMany(ContactLog::class);
    }
}
