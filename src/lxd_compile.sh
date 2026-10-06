#!/bin/bash

branch=${1-main}

apt -y install curl wget

curl https://raw.githubusercontent.com/arc-panel-oss/arcpanel/$branch/src/arcpanel-build.sh > /tmp/arcpanel-build.sh
chmod +x /tmp/arcpanel-build.sh

mkdir -p /opt/arcpanel

# Building ArcPanel
if bash /tmp/arcpanel-build.sh --arcpanel --noinstall --keepbuild $branch; then
	cp /tmp/arcpanel-src/deb/*.deb /opt/arcpanel/
fi

# Building PHP
if bash /tmp/arcpanel-build.sh --php --noinstall --keepbuild $branch; then
	cp /tmp/arcpanel-src/deb/*.deb /opt/arcpanel/
fi

# Building NGINX
if bash /tmp/arcpanel-build.sh --nginx --noinstall --keepbuild $branch; then
	cp /tmp/arcpanel-src/deb/*.deb /opt/arcpanel/
fi
