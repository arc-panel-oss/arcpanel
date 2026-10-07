<?php

namespace App\Http\Resources\V2;

/**
 * Role of a user in API v2 answers and requests.
 */
enum ApiRole: string
{
    case Admin = 'admin';
    case Reseller = 'reseller';
    case User = 'user';
}
