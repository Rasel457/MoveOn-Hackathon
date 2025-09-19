<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierProvider extends Model
{
    protected $table = 'courier_providers';

    protected $casts = [
        'home_delivery_available' => 'boolean',
        'pickup_available' => 'boolean',
    ];
}
