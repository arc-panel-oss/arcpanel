{{--
    The role-aware shell (docs/design/05 §4): sidebar from Navigation, top bar
    with breadcrumb, command palette, impersonation badge, user switcher,
    notification tray and user menu. Data comes from App\View\ShellComposer.
--}}
@props(['title', 'crumbs' => []])
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $shell['dir'] }}" data-theme="{{ $shell['prefs']->theme }}" data-density="{{ $shell['prefs']->density }}" data-sidebar="{{ $shell['prefs']->sidebar }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · {{ $shell['appName'] }}</title>
    <link rel="icon" href="/images/favicon.png" type="image/png">
    <link rel="stylesheet" href="@apAsset('app.css')">
    <x-ap-i18n />
    <script type="module" src="@apAsset('app.js')"></script>
</head>
<body>
<x-icons />
<div class="ap-root">
    <a class="skip-link" href="#main">{{ __('Skip to content') }}</a>
    <div class="ap-shell" x-data="apSidebar({ rail: @js($shell['prefs']->sidebar === 'rail') })">
        <aside class="ap-sidebar" id="sidebar" aria-label="{{ __('Main navigation') }}" x-ref="sidebar" x-bind:class="{ 'is-open': open }">
            <div class="ap-sidebar-brand">
                <a class="ap-sidebar-home" href="{{ $shell['home'] }}" aria-label="{{ $shell['appName'] }}"><x-logo /></a>
                <button type="button" class="ap-btn ap-btn-ghost ap-btn-icon ap-btn-sm ap-sidebar-close" aria-label="{{ __('Close menu') }}" x-on:click="close()"><x-ap-icon name="x" /></button>
            </div>
            <nav class="ap-nav" aria-label="{{ __('Sections') }}">
                @foreach ($shell['navigation'] as $group)
                    @if ($group['label'] === null)
                        @foreach ($group['items'] as $item)
                            <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif x-bind:title="rail && wide ? @js($item['label']) : null"><x-ap-icon :name="$item['icon']" /><span>{{ $item['label'] }}</span></a>
                        @endforeach
                    @else
                        <div class="ap-nav-group" role="group" aria-labelledby="nav-{{ $group['key'] }}">
                            <span class="ap-nav-label eyebrow" id="nav-{{ $group['key'] }}">{{ $group['label'] }}</span>
                            @foreach ($group['items'] as $item)
                                <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif x-bind:title="rail && wide ? @js($item['label']) : null"><x-ap-icon :name="$item['icon']" /><span>{{ $item['label'] }}</span></a>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </nav>
            <div class="ap-sidebar-foot">
                <div class="ap-server">
                    <span class="mono ap-server-host">{{ $shell['hostname'] }}</span>
                    <span class="subtle ap-server-brand">ArcPanel</span>
                </div>
            </div>
            {{-- Wide screens: folds the sidebar to its icon rail and back, as the menu button of the top bar --}}
            <button type="button" class="ap-sidebar-collapse" x-on:click="toggleRail()" aria-controls="sidebar" x-bind:aria-expanded="(!rail).toString()"
                x-bind:aria-label="rail ? @js(__('Expand sidebar')) : @js(__('Collapse sidebar'))" x-bind:title="rail ? @js(__('Expand sidebar')) : null">
                <x-ap-icon name="chevron-left" /><span>{{ __('Collapse sidebar') }}</span>
            </button>
        </aside>
        {{-- Phone drawer: the page behind is dimmed, a tap on it closes the menu --}}
        <div class="ap-sidebar-backdrop" x-show="open" x-cloak x-on:click="close()" aria-hidden="true"></div>
        <div class="ap-main">
            <header class="ap-topbar">
                <button type="button" class="ap-btn ap-btn-ghost ap-btn-icon ap-menu-toggle" aria-label="{{ __('Open menu') }}" aria-controls="sidebar"
                    x-ref="toggle" x-bind:aria-expanded="expanded().toString()" x-on:click="toggle()"
                    x-bind:aria-label="wide ? (rail ? @js(__('Expand sidebar')) : @js(__('Collapse sidebar'))) : @js(__('Open menu'))"
                    x-bind:title="wide ? (rail ? @js(__('Expand sidebar')) : @js(__('Collapse sidebar'))) : null"><x-ap-icon name="menu" /></button>
                <nav aria-label="{{ __('Breadcrumb') }}">
                    <ol class="ap-crumbs">
                        @foreach ($crumbs as $crumb)
                            @if ($loop->last)
                                <li><span aria-current="page">{{ $crumb['label'] }}</span></li>
                            @elseif (empty($crumb['url']))
                                <li><span>{{ $crumb['label'] }}</span><x-ap-icon name="chevron-right" size="sm" /></li>
                            @else
                                <li><a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a><x-ap-icon name="chevron-right" size="sm" /></li>
                            @endif
                        @endforeach
                    </ol>
                </nav>
                <div class="ap-topbar-spacer"></div>

                @if ($shell['impersonating'])
                    <form class="ap-imp" method="post" action="{{ route('impersonate.stop') }}">
                        @csrf
                        <x-ap-icon name="user" size="sm" />
                        <span>{!! __('Logged in as :user', ['user' => '<b>'.e($shell['effectiveUser']).'</b>']) !!}</span>
                        <button type="submit"><x-ap-icon name="return" size="sm" />{{ __('Return') }}</button>
                    </form>
                @endif

                <div x-data="apPalette({ url: @js(route('shell.palette')), searchUrl: @js(Route::has('search') ? route('search') : '') })" class="ap-palette-host">
                    <button type="button" class="ap-search-trigger" x-on:click="show()" x-cloak>
                        <x-ap-icon name="search" size="sm" /><span class="ap-search-label">{{ __('Search or jump to…') }}</span><span class="ap-kbd">Ctrl K</span>
                    </button>
                    {{-- Moved to the end of .ap-root: inside the top bar, whose backdrop blur makes it the box of fixed
                         children, the overlay covered only the bar and a click beside the dialog did not close it --}}
                    <template x-teleport=".ap-root">
                    <div class="ap-overlay ap-overlay-palette" x-show="open" x-cloak x-on:click.self="close()" x-on:keydown="onKeydown($event)">
                        <div class="ap-palette" role="dialog" aria-modal="true" aria-label="{{ __('Search') }}" x-ref="dialog">
                            <div class="ap-palette-input">
                                <x-ap-icon name="search" />
                                <input type="text" x-model="query" x-on:input="onInput()" autocomplete="off" role="combobox" aria-expanded="true"
                                    aria-controls="ap-palette-results" x-bind:aria-activedescendant="items.length ? 'ap-palette-item-' + active : null"
                                    aria-label="{{ $shell['searchLabel'] }}" placeholder="{{ $shell['searchLabel'] }}">
                                <button type="button" class="ap-kbd ap-palette-close" x-on:click="close()" aria-label="{{ __('Close') }}" title="{{ __('Close') }}">Esc</button>
                            </div>
                            <div class="ap-palette-body" id="ap-palette-results" role="listbox" aria-label="{{ __('Results') }}">
                                <template x-for="group in groups" x-bind:key="group.label">
                                    <div role="group" x-bind:aria-label="group.label">
                                        <span class="ap-palette-group eyebrow" x-text="group.label"></span>
                                        <template x-for="item in group.items" x-bind:key="item.url">
                                            <a class="ap-palette-item" role="option" x-bind:href="item.url" x-bind:id="'ap-palette-item-' + indexOf(item)"
                                                x-bind:class="{ 'is-active': indexOf(item) === active }" x-bind:aria-selected="(indexOf(item) === active).toString()"
                                                x-on:mouseenter="active = indexOf(item)">
                                                <svg class="ic" aria-hidden="true"><use x-bind:href="'#i-' + item.icon"></use></svg>
                                                <span><template x-for="part in parts(item.label)"><span x-text="part.text" x-bind:class="{ 'ap-mark': part.mark }"></span></template></span>
                                                <span class="meta" x-text="item.meta"></span>
                                            </a>
                                        </template>
                                    </div>
                                </template>
                                <p class="ap-palette-empty" x-show="query.trim().length >= 2 && !loading && groups.length === 0 && !error">{{ __('No results') }}</p>
                                <p class="ap-palette-empty" x-show="query.trim().length < 2">{{ __('Type at least 2 characters') }}</p>
                                <p class="ap-error" x-show="error" x-text="error" role="alert"></p>
                            </div>
                            <div class="ap-palette-foot">
                                <a class="ap-palette-all" x-show="allResultsUrl" x-bind:href="allResultsUrl">{{ __('View all results') }}</a>
                                <span><span class="ap-kbd">↑</span><span class="ap-kbd">↓</span>{{ __('Move') }}</span>
                                <span><span class="ap-kbd">Enter</span>{{ __('Open') }}</span>
                                <span><span class="ap-kbd">Esc</span>{{ __('Close') }}</span>
                            </div>
                        </div>
                    </div>
                    </template>
                </div>

                @if ($shell['canSwitchUser'])
                    {{-- The script (apUserSwitch) replaces the <datalist> with its own list; without it the input still works --}}
                    <details class="ap-dropdown ap-user-switch" x-data="apUserSwitch({ users: @js($shell['switchEntries']) })" x-on:toggle="opened($event)">
                        <summary class="ap-btn ap-btn-ghost ap-btn-sm" title="{{ __('Switch user') }}"><x-ap-icon name="switch" /><span class="ap-switch-label">{{ __('Switch user') }}</span></summary>
                        <div class="ap-menu ap-switch-menu">
                            <form method="post" action="{{ route('impersonate.form') }}" class="ap-switch-form" x-on:submit="submit($event)">
                                @csrf
                                <div class="ap-switch-head">
                                    <span class="eyebrow">{{ __('Log in as') }}</span>
                                    <span class="ap-switch-count subtle">{{ trans_choice(':count account|:count accounts', count($shell['switchEntries'])) }}</span>
                                </div>
                                <label class="sr-only" for="ap-switch-user">{{ __('Search users') }}</label>
                                <div class="ap-switch-search">
                                    <x-ap-icon name="search" />
                                    <input class="ap-input" id="ap-switch-user" name="user" list="ap-switch-users" autocomplete="off" spellcheck="false"
                                        placeholder="{{ __('Search users') }}" x-ref="input" x-model="query" x-init="$el.removeAttribute('list')" x-on:keydown="keydown($event)"
                                        role="combobox" aria-autocomplete="list" aria-controls="ap-switch-list" aria-expanded="true"
                                        x-bind:aria-activedescendant="matches.items[active] ? 'ap-switch-' + matches.items[active].name : null">
                                </div>
                                <datalist id="ap-switch-users">
                                    @foreach ($shell['switchUsers'] as $name)<option value="{{ $name }}"></option>@endforeach
                                </datalist>
                                <ul class="ap-switch-list" id="ap-switch-list" role="listbox" aria-label="{{ __('Users') }}" x-cloak>
                                    <template x-for="(user, index) in matches.items" x-bind:key="user.name">
                                        <li class="ap-switch-item" role="option" x-bind:id="'ap-switch-' + user.name" x-bind:aria-selected="(index === active).toString()"
                                            x-bind:class="{ 'is-active': index === active }" x-on:mousemove="active = index" x-on:click="pick(user.name)">
                                            <span class="ap-switch-icon" aria-hidden="true" x-bind:class="'is-' + user.role">
                                                <svg class="ic" x-show="user.role === 'admin'"><use href="#i-shield" /></svg>
                                                <svg class="ic" x-show="user.role === 'reseller'"><use href="#i-users" /></svg>
                                                <svg class="ic" x-show="user.role !== 'admin' &amp;&amp; user.role !== 'reseller'"><use href="#i-user" /></svg>
                                            </span>
                                            <span class="ap-switch-who">
                                                <b x-text="user.name"></b>
                                                <span class="subtle" x-text="user.fullName || user.roleLabel"></span>
                                            </span>
                                            <span class="ap-badge is-warn" x-show="user.suspended"><span class="dot"></span>{{ __('Suspended') }}</span>
                                            <span class="ap-badge" x-show="!user.suspended && user.role !== 'user'" x-bind:class="user.role === 'admin' ? 'is-accent' : 'is-info'"><span class="dot" x-show="user.role !== 'admin'"></span><span x-text="user.roleLabel"></span></span>
                                        </li>
                                    </template>
                                </ul>
                                <p class="ap-switch-empty subtle" x-show="matches.items.length === 0" x-cloak>
                                    <span x-show="query.trim() !== ''">{{ __('No user matches.') }}</span>
                                    <span x-show="query.trim() === ''">{{ __('No user to log in as.') }}</span>
                                </p>
                                <p class="ap-switch-more subtle" x-show="matches.more > 0" x-cloak x-text="@js(__(':count more: keep typing to narrow the list.')).replace(':count', matches.more)"></p>
                                <div class="ap-switch-foot">
                                    <span class="subtle"><kbd class="ap-kbd">↑</kbd><kbd class="ap-kbd">↓</kbd> {{ __('to move') }} <kbd class="ap-kbd">↵</kbd> {{ __('to log in') }}</span>
                                    <button type="submit" class="ap-btn ap-btn-sm ap-btn-primary"><x-ap-icon name="arrow" />{{ __('Log in as') }}</button>
                                </div>
                                <p class="ap-switch-note subtle"><x-ap-icon name="shield" />{{ __('The switch is recorded in the security log.') }}</p>
                            </form>
                        </div>
                    </details>
                @endif

                <div class="ap-tray" x-data="apNotifications({ url: @js(route('shell.notifications')) })" x-on:keydown.escape="close()" x-on:click.outside="close()" x-cloak>
                    <span class="ap-icon-btn-wrap">
                        <button type="button" class="ap-btn ap-btn-icon ap-btn-ghost" x-on:click="toggle()" x-bind:aria-expanded="open.toString()" aria-controls="ap-tray-panel"
                            x-bind:aria-label="@js(__('Notifications')) + (unread ? ', ' + unread + ' ' + @js(__('unread')) : '')"><x-ap-icon name="bell" /></button>
                        <span class="ap-dot-count" x-show="unread > 0" x-text="unread" aria-hidden="true"></span>
                    </span>
                    <div class="ap-tray-panel ap-menu" id="ap-tray-panel" x-show="open" role="region" aria-label="{{ __('Notifications') }}">
                        <div class="ap-row ap-tray-head">
                            <span class="ap-menu-label eyebrow">{{ __('Notifications') }}</span>
                            <button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" x-show="unread > 0" x-on:click="acknowledgeAll()"><x-ap-icon name="check" />{{ __('Mark all read') }}</button>
                            <button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" x-show="items.length > 2" x-on:click="removeAll()"><x-ap-icon name="trash" />{{ __('Delete all notifications') }}</button>
                        </div>
                        <template x-for="item in items" x-bind:key="item.id">
                            <article class="ap-notice" x-bind:class="{ 'is-unread': !item.acknowledged }">
                                <div class="ap-notice-body"><b x-text="item.title"></b><p><template x-for="(part, index) in item.parts ?? [{ text: item.body }]" x-bind:key="index"><span><template x-if="part.url"><a x-bind:href="part.url" x-bind:target="part.external ? '_blank' : null" x-bind:rel="part.external ? 'noopener noreferrer' : null" x-text="part.text"></a></template><template x-if="!part.url"><span x-text="part.text"></span></template></span></template></p><span class="subtle" x-text="item.date + ' ' + item.time"></span></div>
                                <div class="ap-actions">
                                    <button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" x-show="!item.acknowledged" x-on:click="acknowledge(item)" aria-label="{{ __('Mark as read') }}" title="{{ __('Mark as read') }}"><x-ap-icon name="check" /></button>
                                    <button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" x-on:click="remove(item)" aria-label="{{ __('Delete') }}" title="{{ __('Delete') }}"><x-ap-icon name="trash" /></button>
                                </div>
                            </article>
                        </template>
                        <p class="ap-palette-empty" x-show="loaded && items.length === 0">{{ __('You’re all caught up') }}</p>
                    </div>
                </div>

                <div class="ap-user-menu" x-data="apUserMenu({ theme: @js($shell['prefs']->theme), density: @js($shell['prefs']->density), canChangeTheme: @js($shell['prefs']->canChangeTheme) })"
                    x-on:keydown.escape="close(true)" x-on:click.outside="close()">
                    <button type="button" class="ap-user-btn" x-ref="button" x-on:click="toggle()" x-bind:aria-expanded="open.toString()" aria-haspopup="true" aria-controls="ap-user-menu">
                        <span class="ap-avatar" aria-hidden="true">{{ $shell['initials'] }}</span>
                        <span class="who"><b>{{ $shell['loginUser'] }}</b><span>{{ $shell['roleLabel'] }}</span></span>
                        <span class="sr-only">{{ __('Account menu') }}</span>
                        <x-ap-icon name="chevron-down" size="sm" />
                    </button>
                    <div class="ap-menu ap-user-panel" id="ap-user-menu" x-show="open" x-cloak>
                        <span class="ap-menu-label eyebrow">{{ $shell['loginUser'] }} · {{ $shell['roleLabel'] }}</span>
                        <a class="ap-menu-item" href="{{ $shell['accountUrl'] }}"><x-ap-icon name="user-circle" />{{ __('Account') }}</a>
                        @if ($shell['prefs']->canChangeTheme)
                            <div class="ap-menu-row" role="group" aria-label="{{ __('Theme') }}">
                                <span class="ap-menu-row-label"><x-ap-icon name="moon" />{{ __('Theme') }}</span>
                                <div class="ap-segment">
                                    <button type="button" x-on:click="setTheme('nocturne')" x-bind:aria-pressed="(theme === 'nocturne').toString()">{{ __('Dark') }}</button>
                                    <button type="button" x-on:click="setTheme('light')" x-bind:aria-pressed="(theme === 'light').toString()">{{ __('Light') }}</button>
                                    <button type="button" x-on:click="setTheme('auto')" x-bind:aria-pressed="(theme === 'auto').toString()">{{ __('Auto') }}</button>
                                </div>
                            </div>
                        @endif
                        <div class="ap-menu-row" role="group" aria-label="{{ __('Density') }}">
                            <span class="ap-menu-row-label"><x-ap-icon name="list" />{{ __('Density') }}</span>
                            <div class="ap-segment">
                                <button type="button" x-on:click="setDensity('comfortable')" x-bind:aria-pressed="(density === 'comfortable').toString()">{{ __('Comfortable') }}</button>
                                <button type="button" x-on:click="setDensity('compact')" x-bind:aria-pressed="(density === 'compact').toString()">{{ __('Compact') }}</button>
                            </div>
                        </div>
                        <form method="post" action="{{ route('shell.language') }}" class="ap-menu-row">
                            @csrf
                            <label class="ap-menu-row-label" for="ap-language"><x-ap-icon name="globe" />{{ __('Language') }}</label>
                            <select class="ap-select ap-select-sm" id="ap-language" name="language" onchange="this.form.requestSubmit()">
                                @foreach ($shell['languages'] as $code => $name)
                                    <option value="{{ $code }}" @selected($code === app()->getLocale())>{{ $name }}</option>
                                @endforeach
                            </select>
                            <noscript><button type="submit" class="ap-btn ap-btn-sm">{{ __('Save') }}</button></noscript>
                        </form>
                        <div class="ap-menu-sep" role="separator"></div>
                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="ap-menu-item"><x-ap-icon name="logout" />{{ __('Log out') }}</button>
                        </form>
                    </div>
                </div>
            </header>
            <main class="ap-content" id="main" tabindex="-1">
                {{ $slot }}
            </main>
        </div>
    </div>
    <div class="ap-toast-region" data-toast-region aria-live="polite">
        @if (session('ok'))
            <x-ap-toast type="ok" :title="session('ok')">
                {{-- ok_link: {url, label}, e.g. "Open phpMyAdmin" after a database was added --}}
                @if (is_array(session('ok_link')) && filled(session('ok_link')['url'] ?? null))
                    <a href="{{ session('ok_link')['url'] }}" target="_blank" rel="noopener">{{ session('ok_link')['label'] ?? '' }}</a>
                @endif
            </x-ap-toast>
        @endif
        @if (session('warn'))
            <x-ap-toast type="warn" :title="session('warn')" />
        @endif
        @if (session('error'))
            <x-ap-toast type="error" :title="session('error')" :message="session('error_detail')" />
        @endif
    </div>
    <x-ap-confirm />
</div>
</body>
</html>
