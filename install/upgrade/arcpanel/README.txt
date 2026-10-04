ArcPanel upgrade steps
======================

Scripts in this directory are named <ARCPANEL_VERSION>.sh (for example 1.0.1.sh).

During a package upgrade, func/arcpanel-upgrade.sh sources every script that is
newer than the installed ARCPANEL_VERSION and not newer than the new arcpanel
package version, in `sort -V` order, then writes ARCPANEL_VERSION.

Upstream HestiaCP steps stay in ../versions/ and keep running against VERSION,
which still holds the upstream base version. See docs/adr/0006-rebranding-depth.md.
