<?php

namespace App\Http\Requests\Server;

/** Databases tab of the server settings (E7 batch 3 spec §8.2); ServerPolicy authorizes. */
final class DatabaseSettingsRequest extends ServerSettingsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $alias = ['nullable', 'string', 'max:63', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'];

        return ['pma_alias' => $alias, 'pga_alias' => $alias, 'db_admin_port' => ['nullable', 'integer', 'between:1024,65535']];
    }

    /**
     * Fields the page did not show are null and never change anything; an
     * empty database tools port turns it off (E46).
     *
     * @return array{pma_alias: ?string, pga_alias: ?string, pma_sso: ?bool, pma_restrict: ?bool, db_admin_port: ?string}
     */
    public function values(): array
    {
        return [
            'db_admin_port' => $this->has('db_admin_port') ? ($this->filled('db_admin_port') ? (string) (int) $this->input('db_admin_port') : '') : null,
            'pma_alias' => $this->has('pma_alias') ? (string) $this->input('pma_alias') : null,
            'pga_alias' => $this->has('pga_alias') ? (string) $this->input('pga_alias') : null,
            'pma_sso' => $this->has('pma_sso_shown') ? $this->boolean('pma_sso') : null,
            'pma_restrict' => $this->has('pma_restrict_shown') ? $this->boolean('pma_restrict') : null,
        ];
    }
}
