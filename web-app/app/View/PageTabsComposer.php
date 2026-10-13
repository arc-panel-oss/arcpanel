<?php

namespace App\View;

use App\Auth\Principal;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The tab bar of a page in a sidebar hub (x-ap-page), from Navigation
 * (docs/features/specs/2026-10-13-001-e2-sidebar-hubs-design.md H2, H3).
 */
final class PageTabsComposer
{
    public function __construct(
        private readonly Navigation $navigation,
        private readonly Request $request,
    ) {}

    public function compose(View $view): void
    {
        $route = $this->request->route();
        $view->with('hubTabs', app()->bound(Principal::class) && $route !== null
            ? $this->navigation->tabs(app(Principal::class), $route->getName(), $route->parameters())
            : null);
    }
}
