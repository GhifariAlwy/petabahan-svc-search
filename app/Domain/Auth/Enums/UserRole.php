<?php

namespace App\Domain\Auth\Enums;

enum UserRole: string
{
    case MERCHANT = 'merchant';
    case SUPPLIER = 'supplier';
    case ADMIN = 'admin';
}
