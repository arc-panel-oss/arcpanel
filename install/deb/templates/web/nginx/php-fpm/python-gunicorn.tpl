#=========================================================================#
# ArcPanel Web Domain Template: Python app (Gunicorn)
# DO NOT MODIFY THIS FILE! CHANGES WILL BE LOST WHEN REBUILDING DOMAINS   #
# https://arcpanel.org/docs/web-domains                                   #
#=========================================================================#

server {
	listen      %ip%:%web_port%;
	server_name %domain_idn% %alias_idn%;
	root        %docroot%;
	index       index.html index.htm;
	access_log  /var/log/nginx/domains/%domain%.log combined;
	access_log  /var/log/nginx/domains/%domain%.bytes bytes;
	error_log   /var/log/nginx/domains/%domain%.error.log error;

	include %home%/%user%/conf/web/%domain%/nginx.forcessl.conf*;

	location ~ /\.(?!well-known\/) {
		deny all;
		return 404;
	}

	# arc:app-begin
	location /static/ {
		alias   %docroot%/static/;
		expires 30d;
	}

	location /media/ {
		alias   %docroot%/media/;
		expires 30d;
	}

	location / {
		proxy_pass         http://127.0.0.1:%arc_backend_port%;
		proxy_http_version 1.1;
		proxy_set_header   Host $host;
		proxy_set_header   Early-Data $rfc_early_data;
		proxy_set_header   X-Real-IP $remote_addr;
		proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
		proxy_set_header   X-Forwarded-Proto $scheme;
		proxy_set_header   X-Forwarded-Host $host;
		proxy_set_header   Connection "";
	}
	# arc:app-end

	location /error/ {
		alias %home%/%user%/web/%domain%/document_errors/;
	}

	location /vstats/ {
		alias   %home%/%user%/web/%domain%/stats/;
		include %home%/%user%/web/%domain%/stats/auth.conf*;
	}

	include /etc/nginx/conf.d/phpmyadmin.inc*;
	include /etc/nginx/conf.d/phppgadmin.inc*;
	include %home%/%user%/conf/web/%domain%/nginx.conf_*;
}
