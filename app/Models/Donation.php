<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Donation extends Model
{
    protected $table = 'donations';

    protected $fillable = [
        'donation_no',
        'user_id',
        'project_id',
        'project_title',
        'amount',
        'is_anonymous',
        'message',
        'status',
        'certificate_url',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'is_anonymous' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(DonationProject::class, 'project_id');
    }
}