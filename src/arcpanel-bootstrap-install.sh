#!/bin/bash

# Clean installation bootstrap for development purposes only
# Usage:    ./arcpanel-bootstrap-install.sh [fork] [branch] [os]
# Example:  ./arcpanel-bootstrap-install.sh arcpanel main ubuntu

# Define variables
fork=$1
branch=$2
os=$3

# Download specified installer and compiler
wget https://raw.githubusercontent.com/$fork/arcpanel/$branch/install/arcpanel-install-$os.sh
wget https://raw.githubusercontent.com/$fork/arcpanel/$branch/src/arcpanel-build.sh

# Execute compiler and build arcpanel core package
chmod +x arcpanel-build.sh
./arcpanel-build.sh --arcpanel $branch no

# Execute ArcPanel Control Panel installer with default dummy options for testing
bash arcpanel-install-$os.sh -f -y no -e admin@test.local -p P@ssw0rd -s arcpanel-$branch-$os.test.local --with-debs /tmp/arcpanel-src/debs
