#!/bin/bash

# ArcPanel 1.1.0 upgrade steps

# Resellers (E11): policies, example package and the daily usage totals job
echo "[ * ] Setting up resellers..."
$HESTIA/bin/v-add-sys-tenancy

# Port registry (E12): server range, reserved ports and the nightly check
echo "[ * ] Setting up the port registry..."
$HESTIA/bin/v-add-sys-port-registry
