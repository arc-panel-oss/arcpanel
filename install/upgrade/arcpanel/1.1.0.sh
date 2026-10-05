#!/bin/bash

# ArcPanel 1.1.0 upgrade steps

# Resellers (E11): policies, example package and the daily usage totals job
echo "[ * ] Setting up resellers..."
$HESTIA/bin/v-add-sys-tenancy

# Port registry (E12): server range, reserved ports and the nightly check
echo "[ * ] Setting up the port registry..."
$HESTIA/bin/v-add-sys-port-registry

# Node.js manager (E13): download cache, release keys, developer package
echo "[ * ] Setting up the Node.js manager..."
$HESTIA/bin/v-add-sys-node

# Web template metadata and the batch 1 templates (E6)
echo "[ * ] Installing web template metadata..."
$HESTIA/bin/v-add-sys-web-templates-meta

# File manager (E3): staging tree and the panel app's scheduler
echo "[ * ] Setting up the file manager..."
$HESTIA/bin/v-add-sys-panel-files

# Docker apps (E4): the engine is optional; servers that have it get the
# cron lines and the developer package keys again (idempotent)
if grep -q "^DOCKER_SYSTEM='yes'" "$HESTIA/conf/hestia.conf"; then
	echo "[ * ] Updating the Docker apps setup..."
	$HESTIA/bin/v-add-sys-docker
fi
