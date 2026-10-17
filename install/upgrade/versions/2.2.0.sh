#!/bin/bash
# ArcPanel 2.2.0 upgrade step (E43 API v2 additions for ArcPanel Fleet,
# docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md).
# Sourced by upgrade_start_routine (func/upgrade.sh); safe to run again.

# The server identifier that API clients use to tell servers apart (B1)
"$ARCPANEL/bin/v-update-sys-server-id" > /dev/null
