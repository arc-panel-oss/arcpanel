<?php

/**
 * ArcPanel webmail single sign-on (E46,
 * docs/features/specs/2026-10-20-002-e46-webmail-db-admin-ports-design.md §7.6).
 *
 * The panel posts a one-time ticket to ?_task=login&_action=arcpanel-sso.
 * This plugin exchanges it on the loopback address for the address and a
 * session password of the mail account (POST /sso/mail of the panel), which
 * Dovecot accepts from the loopback address only, and logs in with them.
 * Any failure leaves the login page; the ticket is used up either way.
 */
class arcpanel_sso extends rcube_plugin {
	public $task = "login";

	public function init() {
		$this->load_config();
		$this->add_hook("startup", [$this, "startup"]);
		$this->add_hook("authenticate", [$this, "authenticate"]);
	}

	public function startup($args) {
		if (
			$args["task"] === "login" &&
			$this->ticket() !== "" &&
			rcube_utils::get_input_string("_action", rcube_utils::INPUT_GET) === "arcpanel-sso"
		) {
			$args["action"] = "login";
		}

		return $args;
	}

	public function authenticate($args) {
		$ticket = $this->ticket();
		if (
			$ticket === "" ||
			rcube_utils::get_input_string("_action", rcube_utils::INPUT_GET) !== "arcpanel-sso"
		) {
			return $args;
		}

		$answer = $this->exchange($ticket, $this->clientIp());
		if ($answer === null) {
			$args["abort"] = true;

			return $args;
		}

		// A sign-on replaces any session of this browser
		rcmail::get_instance()->kill_session();
		$args["user"] = $answer["user"];
		$args["pass"] = $answer["password"];
		$args["valid"] = true;
		$args["cookiecheck"] = false;

		return $args;
	}

	private function ticket() {
		$ticket = (string) rcube_utils::get_input_string("_ticket", rcube_utils::INPUT_POST);

		return preg_match('/^[A-Za-z0-9_-]{43}$/', $ticket) === 1 ? $ticket : "";
	}

	/** The browser's address as the panel saw it (IPv4-mapped IPv6 as IPv4). */
	private function clientIp() {
		$ip = (string) ($_SERVER["REMOTE_ADDR"] ?? "");
		if (strpos($ip, ":") === 0 && strpos($ip, ".") > 0) {
			$ip = substr($ip, strrpos($ip, ":") + 1);
		}

		return $ip;
	}

	/** @return array{user: string, password: string}|null */
	private function exchange($ticket, $clientIp) {
		$rcmail = rcmail::get_instance();
		// config.inc.php of the plugin, written by v-add-sys-mail-sso and v-change-sys-port
		$port = $rcmail->config->get("arcpanel_sso_port") ?: "8083";

		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL => "https://localhost:{$port}/sso/mail",
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query(["ticket" => $ticket, "client_ip" => $clientIp]),
			CURLOPT_HTTPHEADER => ["Accept: application/json"],
			// The panel on this server, over the loopback address (as the password plugin)
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_TIMEOUT => 15,
		]);
		$body = curl_exec($curl);
		$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if ($body === false || $status !== 200) {
			rcube::write_log(
				"errors",
				"arcpanel_sso: the panel refused the sign-on (HTTP " . $status . ")",
			);

			return null;
		}
		$data = json_decode($body, true);
		if (
			!is_array($data) ||
			!is_string($data["user"] ?? null) ||
			!is_string($data["password"] ?? null)
		) {
			return null;
		}

		return ["user" => $data["user"], "password" => $data["password"]];
	}
}
