#=========================================================================#
# ArcPanel Web Domain Template: object storage endpoint (E44)             #
# DO NOT MODIFY THIS FILE! CHANGES WILL BE LOST WHEN REBUILDING DOMAINS   #
# https://docs.arcpanel.top/docs/server-administration/web-templates.html #
#=========================================================================#

server {
	listen      %ip%:%proxy_port%;
	server_name %domain_idn% %alias_idn%;
	root        %docroot%;
	access_log  /var/log/%web_system%/domains/%domain%.log combined;
	access_log  /var/log/%web_system%/domains/%domain%.bytes bytes;
	error_log   /var/log/%web_system%/domains/%domain%.error.log error;

	include %home%/%user%/conf/web/%domain%/nginx.forcessl.conf*;

	# The S3 API of Silo on 127.0.0.1:9310: any object size, streamed both
	# ways, the client's Host kept for its signatures
	ignore_invalid_headers off;
	client_max_body_size   0;
	proxy_buffering        off;
	proxy_request_buffering off;

	location / {
		proxy_pass                http://127.0.0.1:9310;
		proxy_http_version        1.1;
		proxy_set_header          Host $http_host;
		proxy_set_header          X-Real-IP $remote_addr;
		proxy_set_header          X-Forwarded-For $proxy_add_x_forwarded_for;
		proxy_set_header          X-Forwarded-Proto $scheme;
		proxy_set_header          Connection "";
		proxy_connect_timeout     300s;
		proxy_read_timeout        3600s;
		proxy_send_timeout        3600s;
		chunked_transfer_encoding off;
	}

	include %home%/%user%/conf/web/%domain%/nginx.conf_*;
}
