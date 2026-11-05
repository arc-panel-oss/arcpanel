#!/bin/bash
# ArcPanel: run one Doctor check (E17,
# docs/features/specs/2026-11-05-003-e17-doctor-design.md §3.1) and print its
# result as one JSON object. Called by doctor_run_check (func/doctor.sh)
# under the check's time budget:
#
#   bash func/doctor-check.sh FILE USER OBJECT
#
# FILE is a check of func/doctor/, USER the hosting user (system for the
# server checks), OBJECT the domain (system for the server checks).

file=$1
user=$2
object=$3

# shellcheck source=/etc/arcpanel/arcpanel.conf
source /etc/arcpanel/arcpanel.conf
# shellcheck source=/usr/local/arcpanel/func/main.sh
source "$ARCPANEL/func/main.sh"
# shellcheck source=/usr/local/arcpanel/func/doctor.sh
source "$ARCPANEL/func/doctor.sh"
source_conf "$ARCPANEL/conf/arcpanel.conf"

case "$file" in
	"$DOCTOR_CHECK_DIR"/*.sh) ;;
	*) exit 1 ;;
esac
[ -f "$file" ] || exit 1

DOCTOR_ID='' DOCTOR_TITLE='' DOCTOR_DOCS='' DOCTOR_FIX_ALLOWED=''
DR_STATUS='' DR_MESSAGE='' DR_VALUE='' DR_FIX_LABEL='' DR_LINK=''
DR_FIX=()
# shellcheck disable=SC1090
source "$file" || exit 1
declare -F doctor_check > /dev/null || exit 1

doctor_check "$user" "$object" > /dev/null 2>&1 < /dev/null

case "$DR_STATUS" in
	ok | warn | fail | skip) ;;
	*)
		DR_STATUS=skip
		DR_MESSAGE='The check gave no result.'
		;;
esac

# A fix only on a check that does not pass, and only an allow-listed command
fix=null
if [ "$DR_STATUS" != 'ok' ] && [ "$DR_STATUS" != 'skip' ] && [ "${#DR_FIX[@]}" -gt 0 ] \
	&& [[ " $DOCTOR_FIX_ALLOWED " == *" ${DR_FIX[0]} "* ]]; then
	command=''
	for word in "${DR_FIX[@]}"; do
		if [ -z "$word" ]; then
			command="$command ''"
		elif [[ "$word" =~ ^[A-Za-z0-9_.,:@/=+-]+$ ]]; then
			command="$command $word"
		else
			command="$command '${word//\'/}'"
		fi
	done
	fix=$(jq -nc --arg label "$DR_FIX_LABEL" --arg command "${command# }" \
		'{label: $label, command: $command, argv: $ARGS.positional}' --args "${DR_FIX[@]}")
fi

jq -nc --arg id "$DOCTOR_ID" --arg title "$DOCTOR_TITLE" --arg docs "$DOCTOR_DOCS" \
	--arg status "$DR_STATUS" --arg message "$DR_MESSAGE" --arg value "$DR_VALUE" \
	--arg link "$DR_LINK" --argjson fix "$fix" \
	'{id: $id, status: $status, title: $title, message: $message, value: $value, docs: $docs, fix: $fix, link: $link}'
