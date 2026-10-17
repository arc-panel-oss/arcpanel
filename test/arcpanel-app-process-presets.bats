#!/usr/bin/env bats
# ArcPanel app process presets (E42 M4,
# docs/features/specs/2026-10-16-003-e42-app-processes-design.md §5).
# Run as root on a server installed with the ArcPanel installer. The render
# cases need nothing else. The end-to-end cases build real apps and skip
# with a reason when what they need is missing: Laravel Octane needs
# network access to Packagist and GitHub, composer, and the swoole module
# for the domain's PHP version (v-add-sys-php-extension X.Y swoole); the
# Python cases need the python system package (v-add-sys-package python)
# and network access to PyPI.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
PKG_DIR=$ARCPANEL/data/packages
PASS="Tpp-Fixed-Pass-42"
U=tpp
DR=render.tpp.example
DL=swoole.tpp.example
DRR=rr.tpp.example
DG=gunicorn.tpp.example
DU=uvicorn.tpp.example
# Outside /root: the user builds the skeleton there
CACHE=/var/tmp/arcpanel-e42-cache

# Local address of the domain (web.conf may hold the NAT address)
web_ip() {
    local ip
    ip=$(grep "^DOMAIN='$2' " "$ARCPANEL/data/users/$1/web.conf" | grep -o " IP='[^']*'" | cut -d"'" -f2)
    if [ -f "$ARCPANEL/data/ips/$ip" ]; then
        echo "$ip"
    else
        basename "$(grep -l "^NAT='$ip'" "$ARCPANEL"/data/ips/* | head -n1)"
    fi
}

fetch() {
    curl -s --noproxy '*' --max-time 15 --resolve "$2:80:$(web_ip "$1" "$2")" "http://$2${3:-/}"
}

# Run CMD until its output contains EXPECTED, at most 60 seconds
eventually_has() {
    local expected="$1" out i
    shift
    for i in $(seq 1 120); do
        out=$("$@" 2> /dev/null)
        if [[ "$out" == *"$expected"* ]]; then
            echo "$out"
            return 0
        fi
        sleep 0.5
    done
    echo "$out"
    return 1
}

as_user() {
    runuser -u $U -- env -i HOME=/home/$U USER=$U PATH=/usr/local/bin:/usr/bin:/bin LANG=C.UTF-8 COMPOSER_NO_INTERACTION=1 "$@"
}

# Make DIR (relative to the domain folder of DOMAIN) for the user
app_dir() {
    local domain=$1 dir=/home/$U/web/$1/$2
    mkdir -p "$dir"
    chown $U:$U "$dir"
    echo "$dir"
}

touch_as_user() {
    local file=$1
    mkdir -p "$(dirname "$file")"
    : > "$file"
    chown -R $U:$U "$(dirname "$file")"
}

preset_start() {
    v-list-app-process-presets $U "$1" "$2" json | jq -r --arg n "$3" '.PRESETS[] | select(.NAME == $n) | .START'
}

preset_detected() {
    v-list-app-process-presets $U "$1" "$2" json | jq -r --arg n "$3" '.PRESETS[] | select(.NAME == $n) | .DETECTED'
}

online() {
    curl -s -o /dev/null --max-time 8 -w '%{http_code}' "$1" | grep -q '^[23]'
}

php_version() {
    local backend
    backend=$(grep "^DOMAIN='$1' " "$ARCPANEL/data/users/$U/web.conf" | grep -o " BACKEND='[^']*'" | cut -d"'" -f2)
    if [[ "$backend" =~ PHP-([0-9]+)_([0-9]+)$ ]]; then
        echo "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
    else
        php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;'
    fi
}

cleanup() {
    local unit
    for unit in /etc/systemd/system/arcpanel-app-$U-*.service; do
        [ -e "$unit" ] || continue
        systemctl disable --now "${unit##*/}" > /dev/null 2>&1 || true
        rm -f "$unit"
    done
    pkill -9 -u $U > /dev/null 2>&1 || true
    v-delete-user $U > /dev/null 2>&1 || true
    v-delete-user-package tpp-dev > /dev/null 2>&1 || true
    rm -f "$PKG_DIR/tpp-dev.tenancy"
    systemctl daemon-reload
}

setup_file() {
    cleanup
    v-add-sys-app-processes
    local tmp
    tmp=$(mktemp)
    sed "s/^SHELL=.*/SHELL='bash'/" "$PKG_DIR/default.pkg" > "$tmp"
    v-add-user-package "$tmp" tpp-dev > /dev/null
    rm -f "$tmp"
    v-change-package-tenancy tpp-dev APP_PROCESSES yes
    v-change-package-tenancy tpp-dev APP_PROCESSES_MAX 10
    v-change-package-tenancy tpp-dev APP_PROCESSES_MEMORY 768
    v-change-package-tenancy tpp-dev PORTS 10
    v-add-user $U "$PASS" tpp@example.invalid tpp-dev > /dev/null
    for domain in $DR $DL $DRR $DG $DU; do
        v-add-web-domain $U "$domain" > /dev/null
    done
    mkdir -p "$CACHE"
}

teardown_file() {
    cleanup
}

#----------------------------------------------------------#
#                         Render                           #
#----------------------------------------------------------#

@test "Render: every preset of the catalog is listed with its kind and runtime" {
    run v-list-app-process-presets json
    assert_success
    echo "$output" | jq -e '[.PRESETS[].NAME] == ["octane-swoole","octane-roadrunner","octane-frankenphp","laravel-queue","laravel-horizon","laravel-scheduler","gunicorn","uvicorn","celery","go-binary","puma","sidekiq","custom"]'
    echo "$output" | jq -e '[.PRESETS[] | select(.KIND == "web") | .TEMPLATE] | all(. != "")'
    echo "$output" | jq -e '[.PRESETS[] | select(.TEMPLATE != "") | .TEMPLATE_AVAILABLE] | all(. == "yes")'
}

@test "Render: Laravel presets use the PHP CLI of the domain's version" {
    dir=$(app_dir $DR laravel)
    touch_as_user "$dir/artisan"
    v=$(php_version $DR)
    assert_equal "$(preset_start $DR laravel octane-swoole)" "/usr/bin/php$v artisan octane:start --server=swoole --host=127.0.0.1 --port=%port% --workers=%workers% --task-workers=%workers% --max-requests=500"
    assert_equal "$(preset_start $DR laravel octane-roadrunner)" "/usr/bin/php$v artisan octane:start --server=roadrunner --host=127.0.0.1 --port=%port% --workers=%workers% --max-requests=500"
    assert_equal "$(preset_start $DR laravel laravel-queue)" "/usr/bin/php$v artisan queue:work --sleep=3 --tries=3 --max-time=3600"
    assert_equal "$(preset_start $DR laravel laravel-horizon)" "/usr/bin/php$v artisan horizon"
    assert_equal "$(preset_start $DR laravel laravel-scheduler)" "/usr/bin/php$v artisan schedule:work"
    assert_equal "$(preset_detected $DR laravel laravel-queue)" "yes"
    assert_equal "$(preset_detected $DR laravel gunicorn)" "no"
}

@test "Render: the Swoole preset needs the module for the CLI" {
    dir=$(app_dir $DR laravel)
    v=$(php_version $DR)
    missing=$(v-list-app-process-presets $U $DR laravel json | jq -r '.PRESETS[] | select(.NAME == "octane-swoole") | .RUNTIME_MISSING')
    if /usr/bin/php"$v" -m | grep -qix swoole; then
        assert_equal "$missing" ""
    else
        assert_equal "$missing" "turn on swoole for PHP $v (CLI) in the PHP Manager"
        run v-add-app-process $U $DR web octane-swoole '' laravel
        assert_failure 2
        assert_output --partial "turn on swoole"
    fi
    run v-list-app-process-presets $U $DR laravel json
    echo "$output" | jq -e '.PRESETS[] | select(.NAME == "octane-roadrunner") | .RUNTIME_MISSING == ""'
}

@test "Render: Python presets use .venv and the module of wsgi.py or celery.py" {
    dir=$(app_dir $DR django)
    touch_as_user "$dir/manage.py"
    touch_as_user "$dir/shop/wsgi.py"
    touch_as_user "$dir/shop/celery.py"
    assert_equal "$(preset_start $DR django gunicorn)" "/usr/bin/gunicorn --bind 127.0.0.1:%port% --workers %workers% shop.wsgi"
    mkdir -p "$dir/.venv/bin"
    printf '#!/bin/sh\n' > "$dir/.venv/bin/python"
    chmod 755 "$dir/.venv/bin/python"
    chown -R $U:$U "$dir/.venv"
    assert_equal "$(preset_start $DR django gunicorn)" "$dir/.venv/bin/gunicorn --bind 127.0.0.1:%port% --workers %workers% shop.wsgi"
    assert_equal "$(preset_start $DR django celery)" "$dir/.venv/bin/celery -A shop worker --loglevel=info"
    assert_equal "$(preset_start $DR django uvicorn)" "$dir/.venv/bin/uvicorn --host 127.0.0.1 --port %port% main:app"
    assert_equal "$(preset_detected $DR django gunicorn)" "yes"
    assert_equal "$(preset_detected $DR django celery)" "no"
}

@test "Render: Go and Ruby presets" {
    dir=$(app_dir $DR rails)
    touch_as_user "$dir/Gemfile"
    touch_as_user "$dir/config.ru"
    assert_equal "$(preset_start $DR rails puma)" "bundle exec puma -b tcp://127.0.0.1:%port% -e production"
    assert_equal "$(preset_start $DR rails sidekiq)" "bundle exec sidekiq -e production"
    assert_equal "$(preset_detected $DR rails puma)" "yes"
    gdir=$(app_dir $DR go)
    touch_as_user "$gdir/go.mod"
    assert_equal "$(preset_start $DR go go-binary)" "./app"
    assert_equal "$(preset_detected $DR go go-binary)" "yes"
}

@test "Render: the port placeholder is filled when the process is added" {
    dir=$(app_dir $DR plain)
    printf 'import http.server, os, sys\nhttp.server.HTTPServer(("127.0.0.1", int(sys.argv[1])), http.server.SimpleHTTPRequestHandler).serve_forever()\n' > "$dir/srv.py"
    chown $U:$U "$dir/srv.py"
    run v-add-app-process $U $DR web custom 'python3 srv.py %port%' plain 64 1 web flask-fastapi
    assert_success
    port=$(v-list-app-process $U $DR web json | jq -r .PORT)
    v-list-app-process $U $DR web json | jq -e --arg p "$port" '.START == "python3 srv.py " + $p'
    run v-delete-app-process $U $DR web
    assert_success
}

#----------------------------------------------------------#
#                     Laravel Octane                       #
#----------------------------------------------------------#

# A Laravel skeleton with Octane in DIR, built once and cached
laravel_app() {
    local dir=$1 v=$2
    # The user cannot enter the working directory of bats (/root/...)
    chown -R $U:$U "$CACHE"
    if [ ! -f "$CACHE/laravel/artisan" ]; then
        rm -rf "$CACHE/laravel"
        (cd "$CACHE" && as_user /usr/bin/php"$v" /usr/local/bin/composer create-project --no-interaction --quiet laravel/laravel "$CACHE/laravel") || return 1
    fi
    if [ ! -d "$CACHE/laravel/vendor/laravel/octane" ] || [ ! -d "$CACHE/laravel/vendor/spiral/roadrunner-http" ]; then
        (cd "$CACHE/laravel" && as_user /usr/bin/php"$v" /usr/local/bin/composer require --no-interaction --quiet laravel/octane spiral/roadrunner-cli spiral/roadrunner-http) || return 1
    fi
    chown -R $U:$U "$CACHE"
    as_user cp -a "$CACHE/laravel/." "$dir/" || return 1
    printf '<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get("/", fn () => "octane-v1 " . getmypid());\n' > "$dir/routes/web.php"
    chown $U:$U "$dir/routes/web.php"
}

need_network() {
    curl -s -o /dev/null --max-time 8 https://repo.packagist.org/packages.json || skip "Packagist is not reachable"
    [ -x /usr/local/bin/composer ] || skip "composer is not installed"
}

@test "Octane: Swoole serves the app and a reload serves new code" {
    need_network
    v=$(php_version $DL)
    /usr/bin/php"$v" -m | grep -qix swoole || skip "swoole is not on for PHP $v (v-add-sys-php-extension $v swoole)"
    dir=$(app_dir $DL app)
    laravel_app "$dir" "$v" || skip "the Laravel skeleton could not be built"
    (cd "$dir" && as_user /usr/bin/php"$v" artisan octane:install --server=swoole --no-interaction > /dev/null)
    run v-add-app-process $U $DL web octane-swoole '' app 512 1
    assert_success
    run eventually_has "octane-v1" fetch $U $DL
    assert_success
    first=$output
    printf '<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get("/", fn () => "octane-v2 " . getmypid());\n' > "$dir/routes/web.php"
    chown $U:$U "$dir/routes/web.php"
    run fetch $U $DL
    assert_output --partial "octane-v1"
    run v-reload-app-process $U $DL web
    assert_success
    run eventually_has "octane-v2" fetch $U $DL
    assert_success
    pid=$(v-list-app-process $U $DL web json | jq -r .PID)
    [ -n "$pid" ] && [ "$(stat -c %U /proc/"$pid")" = "$U" ]
    grep -q "reload: /usr/bin/php$v artisan octane:reload" "/home/$U/web/$DL/app-process/logs/web.log"
    run v-add-app-process $U $DL queue laravel-queue '' app 128
    assert_success
    run eventually_has "running" bash -c "v-list-app-process $U $DL queue json | jq -r .STATUS"
    assert_success
    run v-start-app-process-task $U $DL web migrate
    assert_success
}

@test "Octane: RoadRunner serves the app" {
    need_network
    v=$(php_version $DRR)
    dir=$(app_dir $DRR app)
    laravel_app "$dir" "$v" || skip "the Laravel skeleton could not be built"
    if [ -x "$CACHE/rr" ]; then
        cp "$CACHE/rr" "$dir/rr" && chown $U:$U "$dir/rr"
    else
        (cd "$dir" && as_user ./vendor/bin/rr get-binary --no-interaction --quiet) || skip "the RoadRunner binary could not be downloaded"
        cp "$dir/rr" "$CACHE/rr"
    fi
    (cd "$dir" && as_user /usr/bin/php"$v" artisan octane:install --server=roadrunner --no-interaction > /dev/null)
    run v-add-app-process $U $DRR web octane-roadrunner '' app 512 1
    assert_success
    run eventually_has "octane-v1" fetch $U $DRR
    assert_success
    printf '<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get("/", fn () => "octane-rr2");\n' > "$dir/routes/web.php"
    chown $U:$U "$dir/routes/web.php"
    run v-reload-app-process $U $DRR web
    assert_success
    run eventually_has "octane-rr2" fetch $U $DRR
    assert_success
}

#----------------------------------------------------------#
#                         Python                           #
#----------------------------------------------------------#

venv_with() {
    local dir=$1
    shift
    python3 -c 'import ensurepip' 2> /dev/null || skip "python3-venv is not installed (v-add-sys-package python)"
    curl -s -o /dev/null --max-time 8 https://pypi.org/simple/ || skip "PyPI is not reachable"
    (cd "$dir" && as_user python3 -m venv .venv && as_user .venv/bin/pip install --quiet --disable-pip-version-check "$@") || skip "the packages could not be installed"
}

@test "Python: Gunicorn serves a WSGI app and HUP reloads it" {
    dir=$(app_dir $DG app)
    venv_with "$dir" gunicorn
    touch_as_user "$dir/manage.py"
    mkdir -p "$dir/site1"
    printf 'import os\ndef application(environ, start_response):\n    start_response("200 OK", [("Content-Type", "text/plain")])\n    return [b"gunicorn-v1 " + str(os.getpid()).encode()]\n' > "$dir/site1/wsgi.py"
    : > "$dir/site1/__init__.py"
    chown -R $U:$U "$dir"
    run v-add-app-process $U $DG web gunicorn '' app 256
    assert_success
    v-list-app-process $U $DG web json | jq -e --arg d "$dir" '.START | startswith($d + "/.venv/bin/gunicorn") and endswith(" site1.wsgi")'
    run eventually_has "gunicorn-v1" fetch $U $DG
    assert_success
    sed -i 's/gunicorn-v1/gunicorn-v2/' "$dir/site1/wsgi.py"
    run v-reload-app-process $U $DG web
    assert_success
    run eventually_has "gunicorn-v2" fetch $U $DG
    assert_success
    v-list-app-process $U $DG web json | jq -e '.RELOAD == "signal:HUP"'
}

@test "Python: Uvicorn serves an ASGI app" {
    dir=$(app_dir $DU app)
    venv_with "$dir" uvicorn
    printf 'async def app(scope, receive, send):\n    await send({"type": "http.response.start", "status": 200, "headers": [[b"content-type", b"text/plain"]]})\n    await send({"type": "http.response.body", "body": b"uvicorn-ok"})\n' > "$dir/main.py"
    chown $U:$U "$dir/main.py"
    run v-add-app-process $U $DU web uvicorn '' app 256
    assert_success
    run eventually_has "uvicorn-ok" fetch $U $DU
    assert_success
}

@test "Presets: the processes are listed with their status" {
    run v-list-app-processes $U json
    assert_success
    echo "$output" | jq -e '[.PROCESSES[] | select(.STATUS != "running")] | length == 0'
}
