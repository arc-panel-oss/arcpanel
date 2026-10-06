#!/bin/bash
# Applies the per-domain template variables (E6): replaces the %arc_*%
# tokens of the rendered NGINX configs, or serves a 503 page when a value is
# missing. Arguments from add_web_config: USER DOMAIN IP HOMEDIR DOCROOT.

# shellcheck source=/usr/local/arcpanel/func/arc-template.sh
source /usr/local/arcpanel/func/arc-template.sh
arc_apply_tpl_vars "$1" "$2" "$4"
