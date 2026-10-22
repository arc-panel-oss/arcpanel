<?php

namespace App\Http\Requests\Api\V2;

/** Body of `startFileFetch` (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md §8). */
final class StartFileFetchRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The http or https URL, without user information (use `auth`). */
            'url' => ['required', 'string', 'max:8192'],
            /** Folder relative to the home directory; the home directory when left out. */
            'path' => ['sometimes', 'nullable', 'string'],
            /** File name; when left out, the name the server sends (Content-Disposition) or the last segment of the URL. */
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            /** Request headers, at most 50; `Host`, `Content-Length`, `Transfer-Encoding`, `Connection` and `Range` are refused. They are dropped when a redirect leaves the URL's host. */
            'headers' => ['sometimes', 'array', 'max:50'],
            'headers.*.name' => ['required', 'string', 'max:256'],
            'headers.*.value' => ['present', 'nullable', 'string', 'max:8000'],
            /** Authentication: `{type: basic, username, password}` or `{type: bearer, token}`; dropped when a redirect leaves the URL's host. */
            'auth' => ['sometimes', 'array'],
            'auth.type' => ['required_with:auth', 'string', 'in:none,basic,bearer'],
            'auth.username' => ['sometimes', 'string', 'max:256'],
            'auth.password' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'auth.token' => ['sometimes', 'string', 'max:8000'],
            /** When the name exists: keep both with the first free `name_(n).ext` (`rename`, default), `overwrite` a file, or `skip`. */
            'on_conflict' => ['sometimes', 'string', 'in:rename,overwrite,skip'],
            /** Allow private and local addresses; administrator keys only. */
            'allow_private' => ['sometimes', 'boolean'],
        ];
    }
}
