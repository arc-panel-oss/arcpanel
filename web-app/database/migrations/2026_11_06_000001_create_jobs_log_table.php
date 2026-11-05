<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| jobs_log (E15 spec §6.1): the index of the job files data/jobs/ID.conf,
| rebuilt by arcpanel:jobs-sync. It holds nothing the files lack.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs_log', function (Blueprint $table) {
            // the 14-digit job ID (spec D10)
            $table->string('id', 14)->primary();
            $table->string('type', 64);
            $table->string('user', 64);
            $table->string('owner', 64);
            $table->string('actor', 64);
            $table->string('object', 255)->default('');
            // the command and its arguments, masked
            $table->text('args')->default('');
            $table->string('status', 16);
            $table->unsignedSmallInteger('exit_code')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('retry_of', 14)->nullable();
            $table->string('resource', 255)->nullable();
            $table->string('queued_at', 19);
            $table->string('started_at', 19)->nullable();
            $table->string('finished_at', 19)->nullable();
            $table->string('updated_at', 19);

            $table->index(['user', 'id']);
            $table->index(['owner', 'id']);
            $table->index(['status', 'id']);
            $table->index(['type', 'id']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs_log');
    }
};
