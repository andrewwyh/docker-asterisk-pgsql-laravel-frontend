<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'mac', 'endpoint', 'format', 'profile', 'token', 'profile_origin'])]
class Phone extends Model
{
    protected $connection = 'sqlite';

    protected $hidden = ['profile', 'token', 'profile_origin'];

    public function profileUrl(): string
    {
        $path = route('phones.profile', [$this->token, "$this->mac.$this->format"], absolute: false);
        $base = $this->profile_origin ?: (config('phone_provisioning.url') ?: config('app.url'));

        return rtrim($base, '/').$path;
    }

    protected function casts(): array
    {
        return ['profile' => 'encrypted', 'profile_origin' => 'encrypted'];
    }
}
