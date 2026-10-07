<?php

namespace App\Http\Requests\Api\V2;

/** POST /packages: the name is required, every other field takes the value of the add form. */
final class StorePackageRequest extends PackageRequest
{
    protected function creates(): bool
    {
        return true;
    }
}
