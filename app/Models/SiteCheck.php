<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteCheck extends Model {

    use HasFactory;

    protected $fillable = [
        'site_link_id',
        'status',
        'response_time_ms',
        'ssl_days_left',
        'html_bytes',
        'assets_bytes',
        'scores',
        'web_vitals',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
    ];

}
