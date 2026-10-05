#=========================================================================#
# ArcPanel Web Domain Template: Static site
# DO NOT MODIFY THIS FILE! CHANGES WILL BE LOST WHEN REBUILDING DOMAINS   #
# https://docs.arcpanel.top/docs/server-administration/web-templates.html #
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
	location / {
		try_files $uri $uri/ =404;
		add_header X-Content-Type-Options "nosniff" always;
		add_header X-Frame-Options "SAMEORIGIN" always;
		add_header Referrer-Policy "strict-origin-when-cross-origin" always;

		location ~* \.(?:css|js|mjs|map|jpe?g|png|webp|avif|gif|ico|svg|woff2?|ttf|otf|eot|mp4|webm)$ {
			expires    30d;
			add_header Cache-Control "public";
			add_header X-Content-Type-Options "nosniff" always;
			add_header X-Frame-Options "SAMEORIGIN" always;
			add_header Referrer-Policy "strict-origin-when-cross-origin" always;
		}
	}

	location ~ [^/]\.php(/|$) {
		return 404;
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
