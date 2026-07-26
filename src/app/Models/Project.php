<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'user_id', 
    'name', 
    'subdomain', 
    'github_repo_url', 
    'branch', 
    'root_dir', 
    'env_vars', 
    'language', 
    'status', 
    'container_id', 
    'port', 
    'last_visited_at',
    'db_name',
    'db_user',
    'db_password',
    'db_driver',
    'demo_instructions'
])]
class Project extends Model
{
    use HasFactory, HasUuids;

    protected $casts = [
        'last_visited_at' => 'datetime',
        'port' => 'integer',
    ];

    protected $hidden = [
        'db_password',
    ];

    /**
     * Get the student (user) that owns this project.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the deployments history of the project.
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
