<?php

namespace App\I18n;

use RuntimeException;

/**
 * Reads gettext catalogues (.po source or compiled .mo) into msgid => text
 * pairs for Laravel JSON translations. Plural forms become one string with
 * the forms separated by "|", which trans_choice() understands. Untranslated
 * and fuzzy entries are skipped, so Laravel falls back to the msgid.
 */
final class GettextCatalog
{
    /** @return array<string, string> */
    public static function fromFile(string $file): array
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'po' => self::fromPo((string) file_get_contents($file)),
            'mo' => self::fromMo((string) file_get_contents($file)),
            default => throw new RuntimeException("Not a gettext catalogue: {$file}"),
        };
    }

    /** @return array<string, string> */
    public static function fromMo(string $data): array
    {
        if (strlen($data) < 28) {
            throw new RuntimeException('Truncated .mo file');
        }
        $magic = unpack('V', substr($data, 0, 4))[1];
        $format = match ($magic) {
            0x950412DE => 'V',
            0xDE120495 => 'N',
            default => throw new RuntimeException('Not a .mo file'),
        };
        [, $count, $originals, $translations] = array_values(unpack($format.'4', substr($data, 4, 16)));

        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $id = self::moString($data, $format, $originals + $i * 8);
            $text = self::moString($data, $format, $translations + $i * 8);
            if ($id === '') {
                continue; // header
            }
            $ids = explode("\0", $id);
            $msgid = $ids[0];
            if (str_contains($msgid, "\x04")) {
                $msgid = substr($msgid, strpos($msgid, "\x04") + 1); // drop msgctxt
            }
            $forms = explode("\0", $text);
            $entries[$msgid] = count($ids) > 1 ? implode('|', $forms) : $forms[0];
        }

        return self::clean($entries);
    }

    /** @return array<string, string> */
    public static function fromPo(string $data): array
    {
        $entries = [];
        $entry = self::emptyEntry();
        $current = null;

        $flush = function () use (&$entries, &$entry): void {
            if ($entry['msgid'] !== '' && ! $entry['fuzzy']) {
                $forms = $entry['msgstr'];
                ksort($forms);
                $entries[$entry['msgid']] = $entry['plural'] ? implode('|', $forms) : ($forms[0] ?? '');
            }
            $entry = self::emptyEntry();
        };

        foreach (preg_split('/\R/', $data) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
                $current = null;

                continue;
            }
            if (str_starts_with($line, '#,')) {
                $entry['fuzzy'] = str_contains($line, 'fuzzy');

                continue;
            }
            if (str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(msgctxt|msgid|msgid_plural|msgstr)(?:\[(\d+)\])?\s+"(.*)"$/', $line, $m) === 1) {
                if ($m[1] === 'msgid' && ($entry['msgid'] !== '' || $entry['msgstr'] !== [])) {
                    $flush();
                }
                $value = stripcslashes($m[3]);
                $current = match ($m[1]) {
                    'msgctxt' => null,
                    'msgid' => 'msgid',
                    'msgid_plural' => 'plural',
                    'msgstr' => (int) ($m[2] ?? 0),
                };
                if ($current === 'msgid') {
                    $entry['msgid'] = $value;
                } elseif ($current === 'plural') {
                    $entry['plural'] = true;
                } elseif (is_int($current)) {
                    $entry['msgstr'][$current] = $value;
                }

                continue;
            }
            if (preg_match('/^"(.*)"$/', $line, $m) === 1 && $current !== null) {
                $value = stripcslashes($m[1]);
                if ($current === 'msgid') {
                    $entry['msgid'] .= $value;
                } elseif (is_int($current)) {
                    $entry['msgstr'][$current] = ($entry['msgstr'][$current] ?? '').$value;
                }
            }
        }
        $flush();

        return self::clean($entries);
    }

    /** @return array{msgid: string, msgstr: array<int, string>, plural: bool, fuzzy: bool} */
    private static function emptyEntry(): array
    {
        return ['msgid' => '', 'msgstr' => [], 'plural' => false, 'fuzzy' => false];
    }

    private static function moString(string $data, string $format, int $offset): string
    {
        [$length, $start] = array_values(unpack($format.'2', substr($data, $offset, 8)));

        return substr($data, $start, $length);
    }

    /**
     * @param  array<string, string>  $entries
     * @return array<string, string>
     */
    private static function clean(array $entries): array
    {
        $entries = array_filter($entries, fn (string $text) => trim($text, '|') !== '');
        ksort($entries);

        return $entries;
    }
}
