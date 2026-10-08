#!/bin/bash
jq -nc --arg ARGS 'hello' '{ARGS: $ARGS}'
jq -nc --arg ARGS '' '{ARGS: $ARGS, kind: (type($ARGS))}'
jq -nc --arg task_args '' '{ARGS: $task_args}'
