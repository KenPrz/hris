<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The acting user has no employee record (e.g. a bare System Admin account). Only an
 * employee can record their own attendance.
 */
final class NotAnEmployee extends DomainException
{
    public function __construct()
    {
        parent::__construct('Only an employee can record a punch.');
    }

    public function errorCode(): string
    {
        return 'not_an_employee';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
