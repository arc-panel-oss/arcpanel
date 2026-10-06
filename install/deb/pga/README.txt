phppgadmin/ is phpPgAdmin 7.14.6 as released by the HestiaCP fork
(github.com/hestiacp/phppgadmin, release v7.14.6, GPL-2.0, see
phppgadmin/LICENSE). The installers and func/upgrade.sh copy it to
/usr/share/phppgadmin instead of downloading it, so installs do not depend
on the HestiaCP organisation.

To update: replace phppgadmin/ with the files of the new release (the
contents of its tarball, without a top-level folder) and set pga_v in
install/upgrade/upgrade.conf to the same version. func/upgrade.sh compares
pga_v with the appVersion of libraries/lib.inc.php on the server.
