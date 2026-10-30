# ArcPanel Git Deploy port (E20): rendered by func/deploy.sh, do not edit
limit_req_zone $binary_remote_addr zone=arcpanel_deploy:1m rate=30r/m;

server {
	listen      %port% ssl;
%listen_v6%
	server_name _;
	access_log  /var/log/nginx/arcpanel-deploy-port.log combined;
	error_log   /var/log/nginx/arcpanel-deploy-port.error.log error;

	ssl_certificate     %cert%;
	ssl_certificate_key %key%;

	# Fix error "The plain HTTP request was sent to HTTPS port"
	error_page 497 https://$host:$server_port$request_uri;

	add_header X-Content-Type-Options "nosniff" always;
	add_header Referrer-Policy "no-referrer" always;

	limit_req        zone=arcpanel_deploy burst=10 nodelay;
	limit_req_status 429;

	proxy_set_header Host 127.0.0.1;
	proxy_set_header X-Forwarded-For $remote_addr;
	proxy_set_header X-Forwarded-Proto https;
	proxy_set_header X-Arcpanel-Deploy-Port %port%;
	proxy_ssl_verify off;
	proxy_http_version 1.1;
	proxy_read_timeout 300s;

	# Webhooks of pull mode (§6.2)
	location ~ "^/hooks/[a-z0-9]{12}$" {
		client_max_body_size 1m;
		rewrite ^ /_deploy$uri break;
		proxy_pass https://127.0.0.1:%panel_port%;
	}

	# Archives of push mode (§6.3)
	location ~ "^/upload/[a-z0-9]{12}$" {
		client_max_body_size %max_artifact%;
		rewrite ^ /_deploy$uri break;
		proxy_pass https://127.0.0.1:%panel_port%;
	}

	location ~ "^/status/[a-z0-9]{12}/[0-9]{14}$" {
		client_max_body_size 1k;
		rewrite ^ /_deploy$uri break;
		proxy_pass https://127.0.0.1:%panel_port%;
	}

	location / {
		return 404;
	}
}
