<?php

namespace Src\Finanzas\Domain\Enums;

enum PeriodicidadCobro: string
{
    case MENSUAL = 'mensual';
    case TRIMESTRAL = 'trimestral';
    case SEMESTRAL = 'semestral';
    case ANUAL = 'anual';
    case UNICO = 'unico';
    case MANUAL = 'manual';
}
