<?php

namespace App\Http\Requests\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Auth\Principal;
use App\Policies\ServerPolicy;
use App\Rules\ServerShells;
use App\Services\Server\ServerSettings;
use App\Services\Server\ServerSettingsApi;
use Illuminate\Validation\ValidationException;

/**
 * PATCH /system/settings/{tab} (E34 batch 6 spec §5.7, D58): the fields of
 * one tab, each optional; fields left out keep their value. Before any
 * validation, in this order: administrators only (`403`), a tab whose system
 * is not installed `404`, the read-only `dns` tab `403`, a field the API
 * never changes (the API switches and the protection group of D58,
 * phpMyAdmin single sign-on and the logo of D23) `403` naming it, a field
 * the tab does not take `422`.
 */
final class UpdateServerSettingsRequest extends ApiRequest
{
    private const HOST = 'regex:/^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/';

    private const TEXT = 'not_regex:/[\'"\n\r]/';

    private const NO_QUOTES = 'not_regex:/[\'"\s\\\\]/';

    /** The rules of each tab's fields (ServerSettingsApi::WRITE), as on the page. */
    public const RULES = [
        'general' => [
            'hostname' => ['sometimes', 'string', 'max:253', self::HOST],
            'timezone' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9_+\-\/]+$/'],
            'language' => ['sometimes', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[a-z]{2})?$/'],
            'language_all' => ['sometimes', 'boolean'],
            'theme' => ['sometimes', 'string', 'max:64', 'regex:/^[\w-]+$/'],
            'user_theme' => ['sometimes', 'boolean'],
            'debug' => ['sometimes', 'boolean'],
            'experimental' => ['sometimes', 'boolean'],
        ],
        'web' => [
            'php_default' => ['sometimes', 'string', 'regex:/^\d\.\d$/'],
        ],
        'mail' => [
            'webmail_alias' => ['sometimes', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
            /** The webmail port of every address (E46), null to turn it off. */
            'webmail_port' => ['sometimes', 'nullable', 'integer', 'between:1024,65535'],
            /** "port" (the webmail port of the panel host) or "domain" (the webmail alias of each mail domain). */
            'webmail_link' => ['sometimes', 'string', 'in:port,domain'],
            'relay' => ['sometimes', 'boolean'],
            'relay_host' => ['sometimes', 'string', 'max:253', 'regex:/^[A-Za-z0-9.-]+$/'],
            'relay_port' => ['sometimes', 'integer', 'between:1,65535'],
            'relay_user' => ['sometimes', 'nullable', 'string', 'max:128', self::NO_QUOTES],
            'relay_password' => ['sometimes', 'string', 'max:256', self::TEXT],
        ],
        'databases' => [
            'pma_alias' => ['sometimes', 'string', 'max:63', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'pga_alias' => ['sometimes', 'string', 'max:63', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            /** The phpMyAdmin and phpPgAdmin port of every address (E46), null to turn it off. */
            'db_admin_port' => ['sometimes', 'nullable', 'integer', 'between:1024,65535'],
        ],
        'backups' => [
            'local' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', 'string', 'in:gzip,zstd'],
            'level' => ['sometimes', 'integer', 'between:1,19'],
            'remote' => ['sometimes', 'boolean'],
            'remote_type' => ['sometimes', 'string', 'in:ftp,sftp,b2,rclone'],
            'host' => ['sometimes', 'nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9.:_-]+$/'],
            'port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'username' => ['sometimes', 'nullable', 'string', 'max:128', self::NO_QUOTES],
            'password' => ['sometimes', 'string', 'max:4096'],
            'path' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[\w\/.:@~+-]*$/'],
            'bucket' => ['sometimes', 'nullable', 'string', 'max:63', 'regex:/^[A-Za-z0-9-]+$/'],
            'key_id' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
            'incremental' => ['sometimes', 'boolean'],
            'repo' => ['sometimes', 'string', 'max:1024', self::NO_QUOTES],
            'snapshots' => ['sometimes', 'integer', 'min:-1', 'max:9999'],
            'keep_daily' => ['sometimes', 'integer', 'min:-1', 'max:9999'],
            'keep_weekly' => ['sometimes', 'integer', 'min:-1', 'max:9999'],
            'keep_monthly' => ['sometimes', 'integer', 'min:-1', 'max:9999'],
            'keep_yearly' => ['sometimes', 'integer', 'min:-1', 'max:9999'],
        ],
        'ssl' => [
            'certificate' => ['required', 'string', 'max:65536', 'regex:/-----BEGIN CERTIFICATE-----/'],
            'key' => ['required', 'string', 'max:65536', 'regex:/-----BEGIN [A-Z ]*PRIVATE KEY-----/'],
        ],
        'security' => [
            'LOGIN_STYLE' => ['sometimes', 'string', 'in:default,old'],
            'INACTIVE_SESSION_TIMEOUT' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'POLICY_CSRF_STRICTNESS' => ['sometimes', 'in:0,1,2'],
            'POLICY_SYSTEM_PASSWORD_RESET' => ['sometimes', 'string', 'in:yes,no'],
            'POLICY_USER_EDIT_DETAILS' => ['sometimes', 'string', 'in:yes,no'],
            'POLICY_USER_EDIT_WEB_TEMPLATES' => ['sometimes', 'string', 'in:yes,no'],
            'POLICY_USER_EDIT_DNS_TEMPLATES' => ['sometimes', 'string', 'in:yes,no'],
            'POLICY_USER_VIEW_LOGS' => ['sometimes', 'string', 'in:yes,no'],
            'POLICY_USER_DELETE_LOGS' => ['sometimes', 'string', 'in:yes,no'],
            'POLICY_USER_VIEW_SUSPENDED' => ['sometimes', 'string', 'in:yes,no'],
            'POLICY_BACKUP_SUSPENDED_USERS' => ['sometimes', 'string', 'in:yes,no'],
            'POLICY_SYNC_ERROR_DOCUMENTS' => ['sometimes', 'string', 'in:yes,no'],
            'POLICY_SYNC_SKELETON' => ['sometimes', 'string', 'in:yes,no'],
            'ENFORCE_SUBDOMAIN_OWNERSHIP' => ['sometimes', 'string', 'in:yes,no'],
            /** Comma list of shells of the server (`v-list-sys-shells`) that resellers may put into their packages. */
            'POLICY_RESELLER_PACKAGE_SHELLS' => ['sometimes', 'string', 'max:255', 'regex:/^[\w.-]+(,[\w.-]+)*$/'],
            /** Upload from URL in the File Manager and REST API v2 (`yes` by default). */
            'FILE_FETCH' => ['sometimes', 'string', 'in:yes,no'],
        ],
        'plugins' => [
            'app_installer' => ['sometimes', 'boolean'],
        ],
        'white-label' => [
            'APP_NAME' => ['sometimes', 'string', 'min:1', 'max:255', self::TEXT],
            'TITLE' => ['sometimes', 'string', 'min:1', 'max:255', self::TEXT],
            'SUBJECT_EMAIL' => ['sometimes', 'string', 'min:1', 'max:255', self::TEXT],
            'FROM_NAME' => ['sometimes', 'nullable', 'string', 'max:255', self::TEXT],
            'FROM_EMAIL' => ['sometimes', 'nullable', 'email', 'max:254'],
            'HIDE_DOCS' => ['sometimes', 'string', 'in:yes,no'],
        ],
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tab = $this->route('tab');
        if (is_string($tab) && isset(self::RULES[$tab])) {
            $rules = self::RULES[$tab];
            if ($tab === 'security') {
                $rules['POLICY_RESELLER_PACKAGE_SHELLS'][] = new ServerShells;
            }

            return $rules;
        }

        // Any tab (the OpenAPI document): every field, each optional
        $rules = [];
        foreach (self::RULES as $fields) {
            foreach ($fields as $field => $rule) {
                $rules[$field] ??= ['sometimes', ...array_values(array_diff($rule, ['sometimes', 'required']))];
            }
        }

        return $rules;
    }

    /** PEM texts span lines. */
    protected function multiLineFields(): array
    {
        return ['certificate', 'key'];
    }

    protected function prepareForValidation(): void
    {
        $tab = (string) $this->route('tab');
        abort_unless(app(ServerPolicy::class)->editSettings(app(Principal::class)), 403);
        abort_unless(app(ServerSettings::class)->available($tab), 404);
        if (! isset(ServerSettingsApi::WRITE[$tab])) {
            throw new ApiException(ErrorCode::Forbidden, __('This tab of the server settings is read-only.'), 403);
        }
        $body = $this->isJson() ? $this->json()->all() : $this->request->all();
        foreach (array_keys($body) as $field) {
            if (isset(ServerSettingsApi::REFUSED[(string) $field])) {
                throw new ApiException(ErrorCode::Forbidden, ServerSettingsApi::refusal((string) $field), 403);
            }
        }
        $unknown = array_values(array_diff(array_map('strval', array_keys($body)), ServerSettingsApi::WRITE[$tab]));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, sprintf(__('This field cannot be changed on the %s tab.'), $tab)));
        }
    }
}
