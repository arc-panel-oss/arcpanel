<?php

require_once __DIR__ . "/../../lib/vendor/autoload.php";
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

if (isset($argv[1])) {
	$html = $argv[1];
	$config = (new HtmlSanitizerConfig())
		->allowSafeElements() // sane default allowlist
		->allowRelativeLinks()
		->forceHttpsUrls()
		// ArcPanel's own notifications style headings/labels via this class
		->allowAttribute("class", ["p", "span"]);

	echo (new HtmlSanitizer($config))->sanitize($html);
} else {
	return;
}
