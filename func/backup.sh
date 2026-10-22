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
