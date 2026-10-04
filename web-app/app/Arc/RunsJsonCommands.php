<?php

namespace App\Arc;

use JsonException;

trait RunsJsonCommands
{
    public function json(string $command, array $args = [], ?Options $options = null): array
    {
        $result = $this->run($command, [...$args, 'json'], $options);

        if (! $result->ok()) {
            return [];
        }

        try {
            $data = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ArcCommandException($command.' returned invalid JSON: '.$e->getMessage(), $result);
        }

        return is_array($data) ? $data : [];
    }
}
