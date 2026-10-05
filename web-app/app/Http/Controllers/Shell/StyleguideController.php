<?php

namespace App\Http\Controllers\Shell;

use App\Arc\SysConfig;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * GET /app/_styleguide : every x-ap-* component with sample props, only
 * when DEBUG_MODE='true' (docs/design/05 §5). ?theme=, ?density= and ?dir=
 * render it in another theme, density or direction.
 */
final class StyleguideController extends Controller
{
    public function __invoke(SysConfig $config): View
    {
        abort_unless($config->is('DEBUG_MODE', 'true'), 404);

        return view('styleguide');
    }
}
