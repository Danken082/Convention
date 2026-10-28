<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Registration extends Model
{
    protected $fillable = [
        'district',
        'surname',
        'given_name',
        'middle_initial',
        'nickname',
        'birthdate',
        'age',
        'gender',
        'shirt_size',
        'division',
        'ministry',
        'email',
        'proof_of_payment_path',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'age' => 'integer',
        ];
    }
}
