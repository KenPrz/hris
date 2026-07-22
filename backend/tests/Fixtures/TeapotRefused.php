<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Exceptions\Domain\DomainException;

/**
 * A stand-in domain failure, used only to prove the render hook. Lives in tests/ rather
 * than app/Exceptions/Domain/ because that directory must stay diffable against the
 * error table in docs/03-api.md, and this code is not in it.
 */
final class TeapotRefused extends DomainException
{
    public function __construct(private readonly string $vessel)
    {
        parent::__construct('That vessel cannot brew coffee.');
    }

    public function errorCode(): string
    {
        return 'teapot_refused';
    }

    public function httpStatus(): int
    {
        return 418;
    }

    public function details(): array
    {
        return ['vessel' => $this->vessel];
    }
}
