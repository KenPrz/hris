<?php

declare(strict_types=1);

namespace App\Domain\Pay;

/**
 * What kind of day this is, for pay purposes.
 *
 * Philippine holidays are set by annual presidential proclamation and the dates move —
 * Eid'l Fitr and Eid'l Adha move a great deal. Which calendar dates carry which type is
 * therefore data (the holidays table, M4); this enum is only the closed set of types the
 * Labor Code recognises.
 *
 * DoubleRegularHoliday is the case where two regular holidays coincide, which happens
 * rarely but is not hypothetical — Araw ng Kagitingan has fallen on Good Friday.
 */
enum DayType: string
{
    case Ordinary = 'ordinary';
    case SpecialWorking = 'special_working';
    case SpecialNonWorking = 'special_non_working';
    case RegularHoliday = 'regular_holiday';
    case DoubleRegularHoliday = 'double_regular_holiday';
}
