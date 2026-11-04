<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['driver', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'connected_at', 'refreshed_at'])]
#[Hidden(['access_token', 'refresh_token'])]
class ErpConnection extends Model
{
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'refreshed_at' => 'datetime',
        ];
    }
}
