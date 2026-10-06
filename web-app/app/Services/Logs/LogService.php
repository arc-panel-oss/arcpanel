<?php

namespace App\Services\Logs;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;

/**
 * The action log and the login history of an account, as web/list/log and
 * web/list/log/auth read them and web/delete/log(/auth) clear them.
 */
final class LogService
{
    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * `v-list-user-log USER json`, newest first. An unreadable log is one
     * error row, as legacy shows it.
     *
     * @return list<array{date: string, time: string, level: string, category: string, message: string}>
     */
    public function actions(string $user): array
    {
        $result = $this->arc->run('v-list-user-log', [$user, 'json'], Options::allowFailure());
        $data = $result->ok() ? json_decode($result->stdout, true) : null;
        if (! is_array($data)) {
            return [['date' => '', 'time' => '', 'level' => 'Error', 'category' => 'System', 'message' => __('Unable to load logs')]];
        }

        $rows = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $rows[] = [
                    'date' => (string) ($row['DATE'] ?? ''),
                    'time' => (string) ($row['TIME'] ?? ''),
                    'level' => (string) ($row['LEVEL'] ?? ''),
                    'category' => (string) ($row['CATEGORY'] ?? ''),
                    'message' => (string) ($row['MESSAGE'] ?? ''),
                ];
            }
        }

        return array_reverse($rows);
    }

    /**
     * `v-list-user-auth-log USER json`, newest first.
     *
     * @return list<array{date: string, time: string, ip: string, success: bool, browser: string}>
     */
    public function logins(string $user): array
    {
        $rows = [];
        foreach ($this->arc->json('v-list-user-auth-log', [$user]) as $row) {
            if (is_array($row)) {
                $rows[] = [
                    'date' => (string) ($row['DATE'] ?? ''),
                    'time' => (string) ($row['TIME'] ?? ''),
                    'ip' => (string) ($row['IP'] ?? ''),
                    'success' => ($row['STATUS'] ?? '') === 'success',
                    'browser' => (string) ($row['USER_AGENT'] ?? ''),
                ];
            }
        }

        return array_reverse($rows);
    }

    /** `v-delete-user-log USER` */
    public function clearActions(string $user): CommandResult
    {
        return $this->arc->run('v-delete-user-log', [$user], Options::allowFailure());
    }

    /** `v-delete-user-auth-log USER` */
    public function clearLogins(string $user): CommandResult
    {
        return $this->arc->run('v-delete-user-auth-log', [$user], Options::allowFailure());
    }
}
