#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel Control Panel - Backup Function Library                          #
#                                                                           #
# Since E48 archives and snapshots go to backup destinations through        #
# func/backup-driver.sh (rclone for every remote driver) and backup jobs    #
# (func/backup-job.sh). The FTP, SFTP, B2 and rclone transfer functions of  #
# earlier versions were removed with them; commands still source this file. #
#                                                                           #
#===========================================================================#

# Releases of a Git Deploy web folder DIR (E20 D14) that current does not
# point to, one name per line: backups keep only current and shared/. Prints
# nothing when DIR has no deploy or releases/ is a link.
backup_deploy_stale() {
	local dir="$1" live release
	if [ ! -L "$dir/current" ] || [ ! -d "$dir/releases" ] || [ -L "$dir/releases" ]; then
		return 0
	fi
	live=$(readlink "$dir/current")
	live=${live#releases/}
	for release in "$dir"/releases/*; do
		release=${release##*/}
		if [ "$release" != '*' ] && [ "$release" != "$live" ]; then
			echo "$release"
		fi
	done
}
