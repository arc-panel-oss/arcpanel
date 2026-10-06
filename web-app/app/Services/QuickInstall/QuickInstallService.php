<?php

namespace App\Services\QuickInstall;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Arc\Secret;
use App\Services\Support\ObjectLookup;

/**
 * Quick Install of web applications through the commands of E7 batch 2
 * spec §10.1. The panel never runs installer code; the option values go to
 * v-start-user-quick-install in a Secret file.
 */
final class QuickInstallService
{
    public function __construct(
        private readonly ArcCommand $arc,
        private readonly ObjectLookup $lookup,
    ) {}

    /** @return array<string, array{name: string, group: string, version: string, thumbnail: string, php: string, installable: bool}> */
    public function apps(): array
    {
        $apps = [];
        foreach ($this->arc->json('v-list-quick-install-apps') as $name => $row) {
            if (is_array($row)) {
                $apps[(string) $name] = [
                    'name' => (string) ($row['NAME'] ?? $name),
                    'group' => (string) ($row['GROUP'] ?? ''),
                    'version' => (string) ($row['VERSION'] ?? ''),
                    'thumbnail' => (string) ($row['THUMBNAIL'] ?? ''),
                    'php' => (string) ($row['PHP_VERSIONS'] ?? ''),
                    'installable' => ($row['INSTALLABLE'] ?? '') === 'yes',
                ];
            }
        }

        return $apps;
    }

    /**
     * The installer form for the user's domain; 404 when the domain or the
     * installer is missing.
     *
     * @return array{app: string, version: string, rootClean: bool, fields: list<array{key: string, type: string, label: string, value: string, placeholder: string, options: array<string, string>, required: bool}>}
     */
    public function form(string $user, string $domain, string $app): array
    {
        $data = $this->lookup->decode('v-list-quick-install-app-options', [$user, $domain, $app]);
        $fields = [];
        foreach ((array) ($data['FIELDS'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $options = [];
            foreach ((array) ($row['OPTIONS'] ?? []) as $option) {
                if (is_array($option)) {
                    $options[(string) ($option['VALUE'] ?? '')] = (string) ($option['LABEL'] ?? '');
                }
            }
            $fields[] = [
                'key' => (string) ($row['KEY'] ?? ''),
                'type' => (string) ($row['TYPE'] ?? 'text'),
                'label' => (string) ($row['LABEL'] ?? ''),
                'value' => (string) ($row['VALUE'] ?? ''),
                'placeholder' => (string) ($row['PLACEHOLDER'] ?? ''),
                'options' => $options,
                'required' => ($row['REQUIRED'] ?? '') === 'yes',
            ];
        }

        return [
            'app' => (string) ($data['APP'] ?? $app),
            'version' => (string) ($data['VERSION'] ?? ''),
            'rootClean' => ($data['ROOT_CLEAN'] ?? 'yes') === 'yes',
            'fields' => $fields,
        ];
    }

    /**
     * `v-start-user-quick-install USER DOMAIN APP <secret file>`
     *
     * @param  array<string, string>  $values  option values by field key
     */
    public function start(string $user, string $domain, string $app, array $values): CommandResult
    {
        $json = json_encode((object) $values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->arc->run('v-start-user-quick-install', [$user, $domain, $app, Secret::file($json)], Options::allowFailure());
    }

    /**
     * The current or last install of the user (`v-list-user-quick-install-task`).
     *
     * @return array{id: string, domain: string, app: string, status: string, exit: string, start: string, end: string, log: string}
     */
    public function task(string $user): array
    {
        $row = $this->arc->json('v-list-user-quick-install-task', [$user, '200']);

        return [
            'id' => (string) ($row['ID'] ?? ''),
            'domain' => (string) ($row['DOMAIN'] ?? ''),
            'app' => (string) ($row['APP'] ?? ''),
            'status' => (string) ($row['STATUS'] ?? ''),
            'exit' => (string) ($row['EXIT'] ?? ''),
            'start' => (string) ($row['START'] ?? ''),
            'end' => (string) ($row['END'] ?? ''),
            'log' => (string) ($row['LOG'] ?? ''),
        ];
    }
}
