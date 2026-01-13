<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone_verified_at',
        'email_verified_at',
        'password',
        'plan',
        'fcm_id',
        'app_version',
        'device_id',
        'google_id',
        'apple_id',
        'facebook_id',
        'phone',
        'avatar',
        'otp',
        'ip',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];


    public function subscription()
    {
        return $this->hasOne(Subscription::class, 'user_id', 'id');
    }

    public function isPremium()
    {
        $subscription = $this->subscription;
        if ($subscription && $subscription->status === 'active') {
            return true;
        }
        return false;
    }



    // handle limit of links functions

    public function siteLinks()
    {
        return $this->hasMany(SiteLink::class);
    }

    public function activeLinks()
    {
        return $this->siteLinks()->where('is_disabled', false);
    }

    public function linkLimit()
    {
        if ($this->isPremium()) {
            return match ($this->subscription->plan) {
                'premium-monthly' => 5,
                'premium_monthly' => 5,
                'ultra-premium-monthly'   => 25,
                'ultra_premium_monthly'   => 25,
                default => 1,
            };
        }
        return 1;
    }


    public function linkLimitByPlan($plan)
    {
        return match ($plan) {
            'premium-monthly' => 5,
            'premium_monthly' => 5,
            'ultra-premium-monthly'   => 25,
            'ultra_premium_monthly'   => 25,
            default => 1,
        };
    }
    
}
