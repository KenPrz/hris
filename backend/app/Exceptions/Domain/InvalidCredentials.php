<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Wrong email OR wrong password — deliberately indistinguishable, so the API cannot be
 * used to enumerate which accounts exist. See docs/03-api.md.
 */
final class InvalidCredentials extends DomainException
{
    public function __construct()
    {
        parent::__construct('The email or password is incorrect.');
    }

    public function errorCode(): string
    {
        return 'invalid_credentials';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
