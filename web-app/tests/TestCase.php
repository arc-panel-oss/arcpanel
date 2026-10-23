<?php

namespace Tests;

use App\Audit\AuditWriterFake;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    private string $requestJournal = '';

    protected function setUp(): void
    {
        parent::setUp();
        // The writes of API v2 tests are journaled (E43 B4), never into the panel's storage
        $this->requestJournal = sys_get_temp_dir().'/arc-pest-journal-'.bin2hex(random_bytes(6));
        config(['arc.request_journal' => $this->requestJournal]);
        // Audit events (E23) are kept in memory; AuditWriterFake::events() reads them
        AuditWriterFake::install();
    }

    protected function tearDown(): void
    {
        if ($this->requestJournal !== '') {
            File::deleteDirectory($this->requestJournal);
        }
        parent::tearDown();
    }
}
