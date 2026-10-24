#=========================================================================#
# ArcPanel Web Domain Template: WordPress (hardened)
# DO NOT MODIFY THIS FILE! CHANGES WILL BE LOST WHEN REBUILDING DOMAINS   #
# https://arcpanel.org/docs/web-domains                                   #
#=========================================================================#

server {
	listen      %ip%:%proxy_port%;
	server_name %domain_idn% %alias_idn%;
	error_log   /var/log/%web_system%/domains/%domain%.error.log error;

	include %home%/%user%/conf/web/%domain%/nginx.forcessl.conf*;

	location ~ /\.(?!well-known\/|file) {
		deny all;
		return 404;
	}

	location = /xmlrpc.php {
		deny all;
		return 403;
	}

	location = /wp-login.php {
		limit_req zone=arcpanel_wp_login burst=5 nodelay;
		proxy_pass http://%ip%:%web_port%;
	}

	location / {
		proxy_pass http://%ip%:%web_port%;

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
		location ~* ^.+\.(%proxy_extensions%)$ {
			try_files  $uri @fallback;

			root       %docroot%;
			access_log /var/log/%web_system%/domains/%domain%.log combined;
			access_log /var/log/%web_system%/domains/%domain%.bytes bytes;

			expires    max;
		}
	}

	location @fallback {
		proxy_pass http://%ip%:%web_port%;
	}

	location /error/ {
		alias %home%/%user%/web/%domain%/document_errors/;
	}

	include %home%/%user%/conf/web/%domain%/nginx.conf_*;
}
