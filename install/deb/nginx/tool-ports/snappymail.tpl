	location ^~ /data {
		deny all;
		return 404;
	}

	location ~ ^/(README.md|config|temp|logs|bin|SQL|INSTALL|LICENSE|CHANGELOG|UPGRADING)$ {
		deny all;
		return 404;
	}

	location / {
		try_files $uri $uri/ =404;

		location ~* ^.+\.(ogg|ogv|svg|svgz|swf|eot|otf|woff|woff2|mov|mp3|mp4|webm|flv|ttf|rss|atom|jpg|jpeg|gif|png|webp|ico|bmp|mid|midi|wav|rtf|css|js|jar)$ {
			expires 7d;
			fastcgi_hide_header "Set-Cookie";
		}

		location ~ ^/(.*\.php)$ {
			include /etc/nginx/fastcgi_params;
			fastcgi_index index.php;
			fastcgi_param HTTP_EARLY_DATA $rfc_early_data if_not_empty;
			fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
			fastcgi_pass unix:/run/php/www.sock;
		}
	}
}
