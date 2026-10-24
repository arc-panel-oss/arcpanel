#=========================================================================#
# ArcPanel Web Domain Template: Single-page app
# DO NOT MODIFY THIS FILE! CHANGES WILL BE LOST WHEN REBUILDING DOMAINS   #
# https://arcpanel.org/docs/web-domains                                   #
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
	location / {
		try_files $uri $uri/ /index.html;
		add_header X-Content-Type-Options "nosniff" always;
		add_header X-Frame-Options "SAMEORIGIN" always;
		add_header Referrer-Policy "strict-origin-when-cross-origin" always;

		location ~* \.(?:css|js|mjs|map|jpe?g|png|webp|avif|gif|ico|svg|woff2?|ttf|otf|eot|mp4|webm)$ {
			try_files  $uri =404;
			expires    30d;
			add_header Cache-Control "public";
			add_header X-Content-Type-Options "nosniff" always;
			add_header X-Frame-Options "SAMEORIGIN" always;
			add_header Referrer-Policy "strict-origin-when-cross-origin" always;
		}
	}

	location = /index.html {
		add_header Cache-Control "no-cache";
		add_header X-Content-Type-Options "nosniff" always;
		add_header X-Frame-Options "SAMEORIGIN" always;
		add_header Referrer-Policy "strict-origin-when-cross-origin" always;
	}

	location ~ [^/]\.php(/|$) {
		return 404;
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
