#=========================================================================#
# ArcPanel Web Domain Template: Ruby on Rails app (Puma)
# DO NOT MODIFY THIS FILE! CHANGES WILL BE LOST WHEN REBUILDING DOMAINS   #
# https://docs.arcpanel.top/docs/server-administration/web-templates.html #
#=========================================================================#

server {
	listen      %ip%:%proxy_port%;
	server_name %domain_idn% %alias_idn%;
	root        %docroot%;
	index       index.html index.htm;
	access_log  /var/log/%web_system%/domains/%domain%.log combined;
	access_log  /var/log/%web_system%/domains/%domain%.bytes bytes;
	error_log   /var/log/%web_system%/domains/%domain%.error.log error;

	include %home%/%user%/conf/web/%domain%/nginx.forcessl.conf*;

	location ~ /\.(?!well-known\/) {
		deny all;
		return 404;
	}

	# arc:app-begin
	location ^~ /assets/ {
		gzip_static on;
		expires     max;
		try_files   $uri @app;
	}

	location ^~ /packs/ {
		gzip_static on;
		expires     max;
		try_files   $uri @app;
	}

	location / {
		try_files $uri $uri/index.html @app;
	}

	location @app {
		proxy_pass         http://127.0.0.1:%arc_backend_port%;
		proxy_http_version 1.1;
		proxy_set_header   Host $host;
		proxy_set_header   Early-Data $rfc_early_data;
		proxy_set_header   X-Real-IP $remote_addr;
		proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
		proxy_set_header   X-Forwarded-Proto $scheme;
		proxy_set_header   X-Forwarded-Host $host;
		proxy_set_header   Upgrade $http_upgrade;
		proxy_set_header   Connection $http_connection;
		proxy_read_timeout 300s;
		proxy_send_timeout 300s;
	}
	# arc:app-end

	location /error/ {
		alias %home%/%user%/web/%domain%/document_errors/;
	}

	location /vstats/ {
		proxy_pass http://%ip%:%web_port%;
	}

	include %home%/%user%/conf/web/%domain%/nginx.conf_*;
}
