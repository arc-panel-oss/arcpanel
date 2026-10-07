<?php

namespace App\Http\Requests\Api\V2;

/** PATCH /packages/{package}: the fields left out keep their value. */
final class UpdatePackageRequest extends PackageRequest
{
    protected function creates(): bool
    {
        return false;
    }
}
