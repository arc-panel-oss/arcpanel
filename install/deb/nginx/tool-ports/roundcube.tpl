	location ~ ^/(README.md|config|temp|logs|bin|SQL|INSTALL|LICENSE|CHANGELOG|UPGRADING)$ {
		deny all;
		return 404;
	}

	location / {
		try_files $uri $uri/ =404;

		location ~ ^(.+\.php)(.*)$ {
			include /etc/nginx/fastcgi_params;
			fastcgi_split_path_info       ^(.+\.php)(.*)$;
			fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
			fastcgi_param PATH_INFO       $fastcgi_path_info;
			fastcgi_param HTTP_EARLY_DATA $rfc_early_data if_not_empty;
			# Without the panel's session cookies (E39 W5 N32)
			fastcgi_param HTTP_COOKIE     $arcpanel_tool_cookie;
			fastcgi_index index.php;
			fastcgi_pass unix:/run/php/www.sock;
		}
	}
}
