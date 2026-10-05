<?php

namespace App\Services\WebDomains;

/**
 * One template with its metadata, as `v-list-web-templates-meta KIND json`
 * lists it (E6 spec §4.3). A template without a metadata file has its name
 * as title, category "other" and hasMeta false.
 */
final class TemplateMeta
{
    /**
     * @param  list<array{key: string, type: string, required: bool, label: string}>  $variables
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly string $category,
        public readonly string $runtime,
        public readonly bool $internal,
        public readonly array $variables,
        public readonly string $docs,
        public readonly bool $hasMeta,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(array $data): self
    {
        $value = fn (string $key) => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
        $variables = [];
        foreach (is_array($data['VARIABLES'] ?? null) ? $data['VARIABLES'] : [] as $variable) {
            if (! is_array($variable) || ! is_string($variable['KEY'] ?? null)) {
                continue;
            }
            $variables[] = [
                'key' => $variable['KEY'],
                'type' => is_string($variable['TYPE'] ?? null) ? $variable['TYPE'] : '',
                'required' => ($variable['REQUIRED'] ?? '') === 'yes',
                'label' => is_string($variable['LABEL'] ?? null) ? $variable['LABEL'] : $variable['KEY'],
            ];
        }
        $name = $value('NAME');

        return new self(
            name: $name,
            title: $value('TITLE') !== '' ? $value('TITLE') : $name,
            description: $value('DESCRIPTION'),
            category: $value('CATEGORY') !== '' ? $value('CATEGORY') : 'other',
            runtime: $value('RUNTIME') !== '' ? $value('RUNTIME') : 'none',
            internal: $value('INTERNAL') === 'yes',
            variables: $variables,
            docs: $value('DOCS'),
            hasMeta: $value('HAS_META') === 'yes',
        );
    }

    /** Key of the template's port variable, null when it takes no port. */
    public function portVariable(): ?string
    {
        foreach ($this->variables as $variable) {
            if ($variable['type'] === 'port') {
                return $variable['key'];
            }
        }

        return null;
    }

    /** Label of the port variable, translated. */
    public function portLabel(): string
    {
        foreach ($this->variables as $variable) {
            if ($variable['type'] === 'port') {
                return __($variable['label']);
            }
        }

        return __('App port');
    }
}
