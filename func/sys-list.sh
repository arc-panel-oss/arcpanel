#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel cross-user lists (E43 B2): the objects of one kind of every     #
# user, or of a reseller and its customers, read by func/sys-list.php in   #
# one process, so that REST API v2 indexes a server with one sudo call per #
# kind. See                                                                #
# docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md  #
# §5.                                                                      #
#                                                                           #
#===========================================================================#

SYS_LIST_READER="$ARCPANEL/func/sys-list.php"

# sys_list_print KIND OWNER UPDATED_SINCE FORMAT: the command body of
# v-list-sys-<kind>. json prints the reader's answer; plain, csv and shell
# print USER, NAME, DATE and TIME of each object.
sys_list_print() {
	local kind="$1" owner="$2" since="$3" format="$4" json user
	if [ -n "$owner" ]; then
		user="$owner"
		is_format_valid 'user'
		is_object_valid 'user' 'USER' "$owner"
	fi
	if [ -n "$since" ] && ! [[ "$since" =~ ^[0-9]{1,12}$ ]]; then
		check_result "$E_INVALID" "invalid updated since time :: $since"
	fi
	case "$format" in
		json | plain | csv | shell) ;;
		*) check_result "$E_INVALID" "invalid format :: $format" ;;
	esac

	json=$("$ARCPANEL_PHP" "$SYS_LIST_READER" "$kind" "$ARCPANEL/data/users" "$HOMEDIR" "$owner" "$since")
	check_result $? "the $kind lists could not be read" "$E_PARSING"

	local rows='.OBJECTS | to_entries[] | .key as $user | .value | to_entries[] | [$user, .key, .value.DATE, .value.TIME]'
	case "$format" in
		json) echo "$json" ;;
		plain) echo "$json" | jq -r "$rows | @tsv" ;;
		csv)
			echo 'USER,NAME,DATE,TIME'
			echo "$json" | jq -r "$rows | @csv"
			;;
		shell)
			{
				echo 'USER NAME DATE TIME'
				echo '---- ---- ---- ----'
				echo "$json" | jq -r "$rows | map(if . == \"\" then \"-\" else . end) | @tsv"
			} | column -t
			;;
	esac
}
