quoteshellarg: shell argument quoting that does a better job than
escapeshellarg (rejects null bytes, keeps the locale out of the quoting).

Copied from arcpanel/phpquoteshellarg v1.1.0
(commit 3499670c48aed5f04f2656e9cd06d6e2709e8e08, Unlicense, see LICENSE)
so that installs do not fetch it from the ArcPanel organisation.
lib/composer.json loads it through "autoload": {"files": [...]}.
