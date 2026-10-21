# ArcPanel %tool% port (E46): rendered by v-update-sys-tool-ports, do not edit
server {
	listen      %port% ssl;
%listen_v6%
	server_name _;
	root        %root%;
	index       index.php index.html index.htm;
	access_log  /var/log/nginx/arcpanel-%tool%-port.log combined;
	error_log   /var/log/nginx/arcpanel-%tool%-port.error.log error;

	ssl_certificate     %cert%;
	ssl_certificate_key %key%;

	# Fix error "The plain HTTP request was sent to HTTPS port"
	error_page 497 https://$host:$server_port$request_uri;

	# Security headers (E39 W5 N21); no Content-Security-Policy: the tools
	# are third-party applications with inline scripts
	add_header X-Content-Type-Options "nosniff" always;
	add_header X-Frame-Options "SAMEORIGIN" always;
	add_header Referrer-Policy "same-origin" always;

	# TLS 1.3 0-RTT anti-replay
	if ($anti_replay = 307) { return 307 https://$host:$server_port$request_uri; }
	if ($anti_replay = 425) { return 425; }

	location ~ /\.(?!well-known\/) {
		deny all;
		return 404;
	}

	location /error/ {
		alias /var/www/document_errors/;
	}
