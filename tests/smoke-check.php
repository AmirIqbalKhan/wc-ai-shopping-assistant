<?php
/**
 * Offline smoke checks for Phases 2–4 structure.
 *
 * Run: php tests/smoke-check.php
 */

declare(strict_types=1);

$failures = 0;
$root     = dirname(__DIR__);

$required = array(
	'wc-ai-shopping-assistant.php',
	'includes/class-installer.php',
	'includes/class-session.php',
	'includes/class-settings.php',
	'includes/class-admin.php',
	'includes/class-openai-client.php',
	'includes/class-indexer.php',
	'includes/class-hooks.php',
	'includes/class-retrieval.php',
	'includes/class-agent.php',
	'includes/class-rest.php',
	'includes/class-widget.php',
	'includes/class-analytics.php',
	'includes/class-insights.php',
	'includes/class-usage.php',
	'includes/class-public-api.php',
	'includes/class-providers.php',
	'includes/class-local-embeddings.php',
	'includes/class-privacy.php',
	'uninstall.php',
	'assets/js/widget.js',
	'assets/js/admin.js',
	'assets/css/widget.css',
	'assets/css/admin.css',
	'blocks/ai-assistant-block/block.json',
	'blocks/ai-assistant-block/editor.js',
	'docs/README.md',
);

foreach ($required as $rel) {
	$path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
	if (!is_file($path)) {
		echo "FAIL missing {$rel}\n";
		$failures++;
	} else {
		echo "PASS exists {$rel}\n";
	}
}

$map = array(
	'WCAI_Installer'  => 'class-installer.php',
	'WCAI_Session'    => 'class-session.php',
	'WCAI_Public_API' => 'class-public-api.php',
	'WCAI_Analytics'  => 'class-analytics.php',
);
foreach ($map as $class => $file) {
	$slug     = strtolower(str_replace('_', '-', $class));
	$expected = 'class-' . substr($slug, 5) . '.php';
	if ($expected !== $file) {
		echo "FAIL autoload map {$class} => {$expected}\n";
		$failures++;
	} else {
		echo "PASS autoload map {$class}\n";
	}
}

$widget = file_get_contents($root . '/assets/js/widget.js');
foreach (array('session_token', 'SpeechRecognition', 'clickUrl', 'sessionStorage', 'add_to_cart', "setAttribute('role', 'dialog')") as $needle) {
	if (strpos($widget, $needle) === false) {
		echo "FAIL widget missing {$needle}\n";
		$failures++;
	} else {
		echo "PASS widget has {$needle}\n";
	}
}

$retrieval = file_get_contents($root . '/includes/class-retrieval.php');
if (strpos($retrieval, 'FULLTEXT') === false && strpos($retrieval, 'MATCH(summary_text)') === false) {
	echo "FAIL retrieval missing hybrid FULLTEXT path\n";
	$failures++;
} else {
	echo "PASS retrieval hybrid FULLTEXT\n";
}

exit($failures > 0 ? 1 : 0);
