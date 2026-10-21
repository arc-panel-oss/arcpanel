<?php

namespace App\Console\Commands;

use App\I18n\GettextCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Converts the gettext catalogues of lib/locale (Crowdin is the source of
 * truth) into Laravel JSON translations keyed by the English msgid, so
 * __('Add Web Domain') matches the ArcPanel _('Add Web Domain').
 * Run at package build; the generated lang/*.json files are not committed.
 */
#[Signature('arcpanel:i18n-import {--locale-dir= : Directory with <lang>/LC_MESSAGES (default: ../lib/locale)} {--stdout : Print every language as one JSON object instead of writing lang/*.json}')]
#[Description('Import lib/locale gettext catalogues into lang/<lang>.json')]
final class I18nImport extends Command
{
    public function handle(): int
    {
        $localeDir = $this->option('locale-dir') ?: base_path('../lib/locale');
        if (! is_dir($localeDir)) {
            $this->error("No locale directory at {$localeDir}");

            return self::FAILURE;
        }
        // v-add-sys-arcpanel runs this as the web user and writes the files
        // itself: Laravel never boots as root (E39 W5 L15)
        $stdout = (bool) $this->option('stdout');
        if (! $stdout && ! is_dir(lang_path())) {
            mkdir(lang_path(), 0755, true);
        }

        $count = 0;
        $all = [];
        foreach (glob($localeDir.'/*/LC_MESSAGES') ?: [] as $dir) {
            $lang = basename(dirname($dir));
            // .po from Crowdin when present, else the compiled .mo in the repository
            $file = is_file($dir.'/arcpanel.po') ? $dir.'/arcpanel.po' : $dir.'/arcpanel.mo';
            if (! is_file($file) || $lang === 'en') {
                continue;
            }

            $entries = GettextCatalog::fromFile($file);
            if ($stdout) {
                $all[$lang] = $entries;

                continue;
            }
            file_put_contents(
                lang_path($lang.'.json'),
                json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            );
            $this->line(sprintf('%-6s %5d strings from %s', $lang, count($entries), basename($file)));
            $count++;
        }

        if ($stdout) {
            $this->output->write(json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            return self::SUCCESS;
        }
        $this->info("{$count} languages imported into lang/");

        return self::SUCCESS;
    }
}
