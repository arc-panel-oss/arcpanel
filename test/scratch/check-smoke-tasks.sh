#!/bin/bash
ARCPANEL=/usr/local/arcpanel
for U in arcsmoke10861 arcsmoke11360; do
	echo "USER=$U"
	ls "$ARCPANEL/data/users/$U/tasks/" 2>/dev/null || echo "(no tasks dir)"
	"$ARCPANEL/bin/v-list-user-tasks" "$U" json 2>/dev/null | jq -r 'to_entries[] | "\(.value.ID) \(.value.STATUS)"' | head -5
done
ps aux | grep -E 'v-backup|arcpanel-api-v2-smoke' | grep -v grep
