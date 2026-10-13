<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A PHP extension of a version (`v-list-sys-php-extensions VERSION json`,
 * E41 §5): an entry of the extension catalog, or a module enabled on the
 * server that the catalog does not list (`catalog` false, read only).
 *
 * @property array{name: string, package: string, module: string, group: string, required: bool, conflicts: list<string>, note: string, catalog: bool, toggleOnly: bool, installed: bool, available: bool, fpm: bool, cli: bool} $resource
 */
final class PhpExtensionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string the name the paths take, for example `redis` */
            'name' => $this->resource['name'],
            /** @var string the module name of phpenmod */
            'module' => $this->resource['module'],
            /** @var string|null the Debian package; null for a module that ships with PHP (on and off only) */
            'package' => $this->resource['package'] !== '' ? $this->resource['package'] : null,
            /** @var string `cache`, `database`, `image`, `format`, `network`, `security`, `async`, `debug` or `other` */
            'group' => $this->resource['group'],
            /** @var bool required modules cannot be removed or turned off */
            'required' => $this->resource['required'],
            /** @var list<string> extensions that cannot be enabled at the same time */
            'conflicts' => $this->resource['conflicts'],
            /** @var string|null a warning, for example for xdebug */
            'note' => $this->resource['note'] !== '' ? $this->resource['note'] : null,
            /** @var bool false for a module of the server that the panel does not manage */
            'catalog' => $this->resource['catalog'],
            /** @var bool */
            'installed' => $this->resource['installed'],
            /** @var bool whether it can be installed for this version */
            'available' => $this->resource['available'],
            /** @var bool enabled for PHP-FPM */
            'fpm' => $this->resource['fpm'],
            /** @var bool enabled for the command line */
            'cli' => $this->resource['cli'],
        ];
    }
}
