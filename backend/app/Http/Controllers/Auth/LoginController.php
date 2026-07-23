<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\Domain\InvalidCredentials;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

final class LoginController
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email'))->first();

        // One check, one failure. Never branch the response on whether the user was found.
        if ($user === null || ! Hash::check((string) $request->string('password'), (string) $user->password)) {
            throw new InvalidCredentials;
        }

        $token = $user->createToken('web')->plainTextToken;

        return response()->json(['data' => [
            'token' => $token,
            'user' => ['id' => $user->id, 'email' => $user->email, 'name' => $user->name],
        ]]);
    }
}
