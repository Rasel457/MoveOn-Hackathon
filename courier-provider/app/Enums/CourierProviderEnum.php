<?php

namespace App\Enums;

enum CourierProviderEnum: string
{
    case PATHAO = 'Pathao';
    case REDX   = 'Redx';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
