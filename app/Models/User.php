<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = ['username', 'password_hash', 'role', 'status'];

    protected $hidden = ['password_hash'];

    protected $authPasswordName = 'password_hash';

    protected $rememberTokenName = '';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    public static function usernameRules(): array
    {
        return ['required', 'string', 'min:3', 'max:64', 'regex:/\A[a-zA-Z0-9_]+\z/'];
    }
}
