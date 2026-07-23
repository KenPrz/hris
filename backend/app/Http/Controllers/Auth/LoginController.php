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

        // Always hash-check, against a dummy hash if the user is unknown, so an unknown email
        // and a wrong password take the same time — otherwise login timing leaks which emails
        // exist. See docs/03-api.md.
        $hash = $user?->password ?? '$2y$12$0XTiFwtg7qDoRycZGf7ISuO6cHMSZjMbMKxkpKw63HoBKtS0h6tx.';
        $passwordOk = Hash::check((string) $request->string('password'), $hash);

        // One check, one failure. Never branch the response on whether the user was found.
        if ($user === null || ! $passwordOk) {
            throw new InvalidCredentials;
        }

        $token = $user->createToken('web')->plainTextToken;

        return response()->json(['data' => [
            'token' => $token,
            'user' => ['id' => $user->id, 'email' => $user->email, 'name' => $user->name],
        ]]);
    }
}
