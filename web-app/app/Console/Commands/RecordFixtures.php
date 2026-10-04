<?php

namespace App\Console\Commands;

use App\Arc\ArcCommand;
use App\Arc\Options;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Records the output of a read-only v-* command on a real server as a test
 * fixture, e.g.
 *
 *     php artisan arcpanel:record-fixtures v-list-user admin json --as=admin.json
 *
 * The file lands in tests/Fixtures/commands/<command>/<name>. Review it for
 * secrets before committing.
 */
#[Signature('arcpanel:record-fixtures {command : v-list-* or v-get-* command} {args?* : Arguments} {--as= : File name}')]
#[Description('Store the output of a read-only v-* command as a test fixture')]
final class RecordFixtures extends Command
{
    public function handle(ArcCommand $arc): int
    {
        $name = (string) $this->argument('command');
        if (preg_match('/^v-(list|get)-[a-z0-9-]+$/', $name) !== 1) {
            $this->error('Only v-list-* and v-get-* commands can be recorded.');

            return self::FAILURE;
        }

        $args = array_values((array) $this->argument('args'));
        $result = $arc->run($name, $args, Options::allowFailure());

        $file = $this->option('as') ?: (implode('-', $args) ?: 'default').'.json';
        $dir = base_path('tests/Fixtures/commands/'.$name);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir.'/'.basename($file), $result->stdout);

        $this->info("exit {$result->exitCode}, saved tests/Fixtures/commands/{$name}/".basename($file));

        return self::SUCCESS;
    }
}
