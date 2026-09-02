<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonalAccessToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'tokenable_type',
        'tokenable_id',
        'name',
        'token',
        'abilities',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function tokenable()
    {
        return $this->morphTo();
    }

    public static function findToken(string $plainTextToken): ?self
    {
        if (str_contains($plainTextToken, '|')) {
            [$id, $plainTextToken] = explode('|', $plainTextToken, 2);
            $token = static::find($id);
            if ($token && hash_equals($token->token, hash('sha256', $plainTextToken))) {
                return $token;
            }
            return null;
        }

        return static::where('token', hash('sha256', $plainTextToken))->first();
    }
}
