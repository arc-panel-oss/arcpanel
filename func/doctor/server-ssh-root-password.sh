#!/bin/bash
# E17 check server.ssh-root-password (spec §4.1): root may sign in over SSH
# with a password (effective configuration of sshd -T).
# shellcheck disable=SC2034
DOCTOR_ID=server.ssh-root-password
DOCTOR_SCOPE=server
DOCTOR_TITLE='Root cannot sign in over SSH with a password'
DOCTOR_DOCS='/docs/guides/doctor#server-ssh-root-password'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local out root password
	if ! command -v sshd > /dev/null 2>&1 && ! doctor_has_fixture sshd; then
		dr_skip 'The SSH server is not installed.'
		return
	fi
	if ! out=$(doctor_src sshd sshd -T 2> /dev/null) || [ -z "$out" ]; then
		dr_skip 'The configuration of the SSH server could not be read.'
		return
	fi
	root=$(awk 'tolower($1) == "permitrootlogin" { print tolower($2); exit }' <<< "$out")
	password=$(awk 'tolower($1) == "passwordauthentication" { print tolower($2); exit }' <<< "$out")
	if [ "$root" = 'yes' ] && [ "$password" = 'yes' ]; then
		dr_fail 'Root can sign in over SSH with a password. Use SSH keys for root and set PermitRootLogin to prohibit-password.' \
			"PermitRootLogin $root, PasswordAuthentication $password"
		return
	fi
	dr_ok 'Root cannot sign in over SSH with a password.' "PermitRootLogin ${root:-unknown}, PasswordAuthentication ${password:-unknown}"
}
