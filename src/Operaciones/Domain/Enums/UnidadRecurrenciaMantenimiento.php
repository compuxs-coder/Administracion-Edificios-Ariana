<?php

namespace Src\Operaciones\Domain\Enums;

use Carbon\CarbonImmutable;

enum UnidadRecurrenciaMantenimiento: string
{
    case DIARIA = 'diaria';
    case SEMANAL = 'semanal';
    case MENSUAL = 'mensual';
    case ANUAL = 'anual';

    public function dateAt(CarbonImmutable $anchor, int $interval, int $sequence): CarbonImmutable
    {
        $amount = $interval * $sequence;

        return match ($this) {
            self::DIARIA => $anchor->addDays($amount),
            self::SEMANAL => $anchor->addWeeks($amount),
            self::MENSUAL => $anchor->isLastOfMonth()
                ? $anchor->addMonthsNoOverflow($amount)->endOfMonth()->startOfDay()
                : $anchor->addMonthsNoOverflow($amount),
            self::ANUAL => $anchor->addYearsNoOverflow($amount),
        };
    }

    public function includesDate(CarbonImmutable $anchor, int $interval, CarbonImmutable $date): bool
    {
        $days = (int) $anchor->diffInDays($date, false);
        if ($days < 0) {
            return false;
        }

        $sequence = match ($this) {
            self::DIARIA => $days % $interval === 0 ? intdiv($days, $interval) : null,
            self::SEMANAL => $days % (7 * $interval) === 0 ? intdiv($days, 7 * $interval) : null,
            self::MENSUAL => ($months = (($date->year - $anchor->year) * 12) + $date->month - $anchor->month) >= 0 && $months % $interval === 0
                ? intdiv($months, $interval)
                : null,
            self::ANUAL => ($years = $date->year - $anchor->year) >= 0 && $years % $interval === 0
                ? intdiv($years, $interval)
                : null,
        };

        return $sequence !== null && $this->dateAt($anchor, $interval, $sequence)->isSameDay($date);
    }
}
