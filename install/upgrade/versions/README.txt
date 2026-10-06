ArcPanel upgrade steps
======================

Scripts in this directory are named <VERSION>.sh (for example 2.0.1.sh).

During a package upgrade, upgrade_start_routine in func/upgrade.sh sources every
script that is newer than the installed VERSION in hestia.conf and not newer
than the version of the new arcpanel package, in `ls -v` order, then writes
VERSION.

ArcPanel 2.0 is installed fresh only, so the first step is the one of the
first release after 2.0.0. The HestiaCP steps up to 1.10.5 were removed with
the hard fork, see docs/adr/0007-full-rename-and-hard-fork.md.

Steps must be idempotent: setup commands (v-add-sys-*) may run again.
