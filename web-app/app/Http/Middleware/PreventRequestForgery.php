<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

/**
 * The framework's CSRF check without the XSRF-TOKEN cookie: the panel's
 * scripts read the token from the csrf-token meta tag, and a cookie that
 * scripts can read would reach the webmail and database tools ports of the
 * same host, which browsers do not keep apart from the panel (E46 spec §6.3).
 */
class PreventRequestForgery extends Middleware
{
    /** @var bool */
    protected $addHttpCookie = false;
}
