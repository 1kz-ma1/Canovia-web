<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'onboarding_version', 'onboarding_completed_at', 'onboarding_skipped_at', 'first_run_completed_at', 'last_resource_provider', 'workspace_mode_preference'])]
#[Hidden(['password', 'remember_token', 'map_personalization_preferences'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */

    public function plans()
    {
        return $this->hasMany(Plan::class);
    }

    public function planMemberships()
    {
        return $this->hasMany(PlanMember::class);
    }

    public function collaborativePlans()
    {
        return $this->belongsToMany(Plan::class, 'plan_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function createdPlanResources()
    {
        return $this->hasMany(PlanResource::class, 'created_by_user_id');
    }

    public function futureMemos()
    {
        return $this->hasMany(FutureMemo::class);
    }

    public function productGrants()
    {
        return $this->hasMany(UserProductGrant::class);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'onboarding_version' => 'integer',
            'onboarding_completed_at' => 'datetime',
            'onboarding_skipped_at' => 'datetime',
            'first_run_completed_at' => 'datetime',
            'map_personalization_preferences' => 'array',
        ];
    }
}
