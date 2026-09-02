<?php

namespace App\Traits;

use App\Models\PersonalAccessToken;
use Illuminate\Support\Str;

trait HasApiTokens
{
    /**
     * The access token currently associated with the user instance.
     *
     * @var \App\Models\PersonalAccessToken|null
     */
    protected ?PersonalAccessToken $currentAccessToken = null;

    public function tokens()
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
    }

    public function createToken(string $name, array $abilities = ['*']): object
    {
        $plainTextToken = Str::random(40);

        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
        ]);

        return new class($token, $token->id . '|' . $plainTextToken) {
            public $accessToken;
            public $plainTextToken;

            public function __construct($accessToken, $plainTextToken)
            {
                $this->accessToken = $accessToken;
                $this->plainTextToken = $plainTextToken;
            }
        };
    }

    public function currentAccessToken(): ?PersonalAccessToken
    {
        return $this->currentAccessToken;
    }

    public function withAccessToken(PersonalAccessToken $accessToken): self
    {
        $this->currentAccessToken = $accessToken;
        return $this;
    }
}
