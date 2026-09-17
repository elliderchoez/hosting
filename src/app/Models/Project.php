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
    'backend_project_id',
    'is_backend_service',
    'name', 
    'subdomain', 
    'github_repo_url', 
    'branch', 
    'root_dir', 
    'env_vars', 
    'language', 
    'framework',
    'status', 
    'container_id', 
    'port', 
    'last_visited_at',
    'db_name',
    'db_user',
    'db_password',
    'db_driver',
    'demo_instructions',
    'category'
])]
class Project extends Model
{
    use HasFactory, HasUuids;

    protected $casts = [
        'last_visited_at' => 'datetime',
        'port' => 'integer',
        'is_backend_service' => 'boolean',
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
     * Get the backend project linked to this frontend project (if fullstack).
     */
    public function backendProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'backend_project_id');
    }

    /**
     * Get the frontend project that uses this backend service (if any).
     */
    public function frontendProject(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Project::class, 'backend_project_id');
    }

    /**
     * Get the deployments history of the project.
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
