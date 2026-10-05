#!/bin/bash
# Docker apps (E4): remove the containers, ports and records of the Docker
# apps of a user that is deleted.

"$HESTIA/bin/v-delete-user-docker-apps" "$1"
