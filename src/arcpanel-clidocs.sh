#!/bin/bash

for file in /usr/local/arcpanel/bin/*; do
	echo "$file" >> ~/arcpanel_cli_help.txt
	[ -f "$file" ] && [ -x "$file" ] && "$file" >> ~/arcpanel_cli_help.txt
done

sed -i 's\/usr/local/arcpanel/bin/\\' ~/arcpanel_cli_help.txt
