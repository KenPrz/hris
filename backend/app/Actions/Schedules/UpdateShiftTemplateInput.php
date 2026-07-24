<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

final readonly class UpdateShiftTemplateInput
{
    /**
     * @param  array<int, array{weekday: int, is_rest: bool, start_minute?: int|null, end_minute?: int|null, break_minutes?: int|null}>  $days
     */
    public function __construct(
        public string $name,
        public array $days,
    ) {}
}
