<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| alerts_history (E16 spec §7, design 15): the fired, reminded and resolved
| alerts and the events of every scope with their deliveries, projected from
| data/alerts/history/ by arcpanel:alerts-sync. The id is the alert's own
| 14-digit id, so a sync updates the row of an alert that is still open. It
| holds nothing the history files lack.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts_history', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('rule', 64);
            // system or user (E16 §7); `domain` of design 15 is not used
            $table->string('scope', 16);
            $table->string('object', 255);
            // the user of a user scope, null for the server scope
            $table->string('user', 64)->nullable();
            $table->string('owner', 64)->nullable();
            $table->string('severity', 16);
            $table->string('state', 16)->default('firing');
            $table->string('value', 255)->nullable();
            $table->text('message');
            // the deliveries: [{time, event, channel, name, type, result, error}]
            $table->text('channels')->default('[]');
            $table->string('fired_at', 19);
            $table->string('resolved_at', 19)->nullable();
            $table->string('last_notified_at', 19)->nullable();
            $table->unsignedInteger('notify_count')->default(0);
            $table->string('created_at', 19);
            $table->string('updated_at', 19);

            $table->index(['state', 'fired_at']);
            $table->index(['user', 'fired_at']);
            $table->index(['owner', 'fired_at']);
            $table->index(['rule', 'fired_at']);
            $table->index('updated_at');
        });
        // One open row per rule, scope and object (design 15 uq_alerts_open,
        // with the scope: the server and a user may both see an object)
        DB::statement("CREATE UNIQUE INDEX uq_alerts_open ON alerts_history (rule, scope, user, object) WHERE state = 'firing'");
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts_history');
    }
};
