#=========================================================================#
# ArcPanel Web Domain Template: WordPress (hardened)
# DO NOT MODIFY THIS FILE! CHANGES WILL BE LOST WHEN REBUILDING DOMAINS   #
# https://docs.arcpanel.top/docs/server-administration/web-templates.html #
#=========================================================================#

server {
	listen      %ip%:%web_port%;
	server_name %domain_idn% %alias_idn%;
	root        %docroot%;
	index       index.php index.html index.htm;
	access_log  /var/log/nginx/domains/%domain%.log combined;
	access_log  /var/log/nginx/domains/%domain%.bytes bytes;
	error_log   /var/log/nginx/domains/%domain%.error.log error;

	include %home%/%user%/conf/web/%domain%/nginx.forcessl.conf*;

	location = /favicon.ico {
		log_not_found off;
		access_log off;
	}

	location = /robots.txt {
		try_files $uri $uri/ /index.php?$args;
		log_not_found off;
		access_log off;
	}

	location ~ /\.(?!well-known\/) {
		deny all;
		return 404;
	}

	location = /xmlrpc.php {
		deny all;
		return 403;
	}

	location = /wp-login.php {
		limit_req zone=arcpanel_wp_login burst=5 nodelay;
		try_files $uri =404;

		include /etc/nginx/fastcgi_params;

		fastcgi_index index.php;
		fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
		fastcgi_param HTTP_HOST $host;

		fastcgi_pass %backend_lsnr%;
	}

	location / {
		try_files $uri $uri/ /index.php?$args;

		# arc:wordpress-secure-begin
		location ~* ^/(?:wp-config\.php|readme\.html|license\.txt|wp-admin/install\.php)$ {
			deny all;
			return 403;
		}

		location ~* ^/wp-content/uploads/.*\.php$ {
			deny all;
			return 403;
		}

		location ~* ^/wp-includes/(?!js/tinymce/wp-tinymce\.php$).+\.php$ {
			deny all;
			return 403;
		}
		# arc:wordpress-secure-end
		location ~* ^.+\.(ogg|ogv|svg|svgz|swf|eot|otf|woff|woff2|mov|mp3|mp4|webm|flv|ttf|rss|atom|jpg|jpeg|gif|png|webp|ico|bmp|mid|midi|wav|rtf|css|js|jar)$ {
			expires 30d;
			fastcgi_hide_header "Set-Cookie";
		}

		location ~* /(?:uploads|files)/.*.php$ {
			deny all;
			return 404;
		}

		location ~ [^/]\.php(/|$) {
			try_files $uri =404;

			include /etc/nginx/fastcgi_params;

			fastcgi_index index.php;
			fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
			fastcgi_param HTTP_HOST $host;

			fastcgi_pass %backend_lsnr%;

			include %home%/%user%/conf/web/%domain%/nginx.fastcgi_cache.conf*;

			if ($request_uri ~* "/wp-admin/|/wp-json/|wp-.*.php|xmlrpc.php|index.php|/store.*|/cart.*|/my-account.*|/checkout.*") {
				set $no_cache 1;
			}

			if ($http_cookie ~* "comment_author|wordpress_[a-f0-9]+|wp-postpass|wordpress_no_cache|wordpress_logged_in|woocommerce_items_in_cart|woocommerce_cart_hash|PHPSESSID") {
				set $no_cache 1;
			}
		}
	}

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
