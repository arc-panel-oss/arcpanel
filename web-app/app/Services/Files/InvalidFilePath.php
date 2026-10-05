<?php

namespace App\Services\Files;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A path that is not inside the home directory: answered with 404 (E3 spec §6.4). */
final class InvalidFilePath extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('This file or folder does not exist.');
    }
}
