<?php
/**
 * Release-archive contents gate.
 *
 * A DBVC release is a tag. Client sites and the client blueprint install what
 * `git archive` makes of it, so the `export-ignore` rules in `.gitattributes`
 * decide what reaches them. The plugin folder is web-served: what ships is
 * fetchable from every site that runs it. This gate archives a ref and checks:
 *
 * 1. The archive holds exactly the tracked files this script calls shipped.
 *    An extra file is internal material; a missing one is a rule that matched
 *    runtime code, which fatals or 404s on every client site.
 * 2. No shipped file, by path or by content, names a client.
 * 3. No tracked file that shipped PHP or the built JavaScript names by path is
 *    left out, so the list in (1) cannot drop something runtime reads.
 *
 * Read-only; no WordPress. The archive is unpacked in the system temp folder
 * and removed again.
 *
 * Usage:
 *   php scripts/check-release-archive-contents.php [--ref=<git ref, default HEAD>]
 *                                                  [--worktree-attributes]
 *   php scripts/check-release-archive-contents.php --fingerprint=<client name>
 *
 * `--worktree-attributes` reads the uncommitted `.gitattributes`, which is how
 * a rule is tested before it is committed. `--fingerprint` prints the line to
 * add to DBVC_RELEASE_CLIENT_FINGERPRINTS.
 *
 * Exit codes: 0 pass, 1 fail, 2 could not run.
 *
 * @package DB_Version_Control
 */

if ('cli' !== PHP_SAPI) {
	exit(2);
}

/**
 * What a release archive may carry: whole directories (trailing slash) and
 * single files. Everything else at the repository root stays out, so a new
 * top-level path fails this gate until someone decides about it.
 */
const DBVC_RELEASE_SHIPPED = [
	'addons/',
	'admin/',
	'build/',
	'commands/',
	'includes/',
	'languages/',
	'vendor/',
	'CHANGELOG.md',
	'LICENSE',
	'db-version-control.php',
	'docs/DBVC_USER_DOCUMENTATION_LIBRARY.md',
	'docs/agents/generated/discovery-snapshot.json',
	'docs/agents/manifest.json',
	'docs/implementation/completed/meta-masking-plan.md',
	'docs/reference/meta-masking.md',
];

/**
 * What never ships from inside those directories, with the reason to report.
 */
const DBVC_RELEASE_INTERNAL = [
	'~(^|/)\.[^/]+$~'                            => 'a dotfile',
	'~\.log$~'                                   => 'a log',
	'~^addons/[^/]+/(docs|tests)/~'              => 'add-on docs or tests',
	'~^addons/[^/]+/[^/]+\.md$~'                 => 'add-on documentation',
	'~^addons/bricks/fixtures/~'                 => 'a test fixture',
	'~^addons/content-migration/tools/~'         => 'a developer tool',
	'~^addons/content-migration/v2/admin-app/~'  => 'uncompiled source (build/ holds the result)',
	'~^addons/visual-editor/boilerplates/~'      => 'agent boilerplate',
	'~^addons/visual-editor/curation/[^/]+\.md$~' => 'a review sheet (runtime reads the JSON)',
];

/**
 * Fingerprints of client names: the length of the normalised name (lowercase
 * letters and digits only) and the first 16 hex digits of its salted SHA-256.
 *
 * This repository is public, so the names themselves are not written here.
 * Add a client with --fingerprint when DBVC is first used on its site, and
 * never remove one. Use a name of six or more characters that is not an
 * ordinary word; a name of more than one word is matched with and without the
 * separators.
 */
const DBVC_RELEASE_CLIENT_FINGERPRINTS = [
	[7, 'd16c306fe0dec8c2'],
	[6, '1efa070e31189926'],
	[16, '0e72326d235a3157'],
	[16, 'eced4292ff8757b2'],
	[14, '9f80b6aea21dff0f'],
	[13, 'd876c07630d7a54e'],
	[8, '0c9d886625211942'],
	[15, 'e46b412ff4b50fac'],
	[20, 'ee21f698c4027a73'],
	[10, 'b83093db813cb277'],
	[13, 'a714b18e52dd0047'],
];

/**
 * Normalise a name for fingerprinting.
 *
 * @param string $name Client name.
 * @return string
 */
function dbvc_release_normalise($name)
{
	return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($name));
}

/**
 * Fingerprint a normalised string.
 *
 * @param string $normalised Lowercase letters and digits.
 * @return string
 */
function dbvc_release_fingerprint($normalised)
{
	return substr(hash('sha256', 'dbvc-release-client:' . $normalised), 0, 16);
}

/**
 * Run a command and return its stdout lines, or stop with exit code 2.
 *
 * @param array<int,string> $command Command and arguments.
 * @return array<int,string>
 */
function dbvc_release_run(array $command)
{
	exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
	if (0 !== $code) {
		fwrite(STDERR, "Could not run: " . implode(' ', $command) . "\n" . implode("\n", $lines) . "\n");
		exit(2);
	}
	return $lines;
}

/**
 * Say why a tracked path must not ship, or return an empty string when it must.
 *
 * @param string $path Repository-relative path.
 * @return string
 */
function dbvc_release_internal_reason($path)
{
	$listed = false;
	foreach (DBVC_RELEASE_SHIPPED as $shipped) {
		if ($path === $shipped || ('/' === substr($shipped, -1) && 0 === strpos($path, $shipped))) {
			$listed = true;
			break;
		}
	}
	if (! $listed) {
		return 'not on the shipped list';
	}
	foreach (DBVC_RELEASE_INTERNAL as $pattern => $reason) {
		if (preg_match($pattern, $path)) {
			return $reason;
		}
	}
	return '';
}

/**
 * Whether a single word holds a client name.
 *
 * @param string $word Lowercase letters and digits.
 * @return bool
 */
function dbvc_release_word_names_client($word)
{
	static $seen = [];
	if (isset($seen[$word])) {
		return $seen[$word];
	}
	$length = strlen($word);
	$found  = false;
	foreach (DBVC_RELEASE_CLIENT_FINGERPRINTS as [$size, $fingerprint]) {
		for ($offset = 0; $offset + $size <= $length; $offset++) {
			if (dbvc_release_fingerprint(substr($word, $offset, $size)) === $fingerprint) {
				$found = true;
				break 2;
			}
		}
	}
	return $seen[$word] = $found;
}

/**
 * Find the first line of a text that names a client.
 *
 * Names are looked for inside every word, and across two or three consecutive
 * words for a name written with separators.
 *
 * @param string $text File contents or a path.
 * @return int Line number, or 0.
 */
function dbvc_release_client_line($text)
{
	static $phrases = null, $sizes = [];
	if (null === $phrases) {
		$phrases = [];
		foreach (DBVC_RELEASE_CLIENT_FINGERPRINTS as [$size, $fingerprint]) {
			$phrases[$fingerprint] = true;
			$sizes[$size]          = true;
		}
	}

	preg_match_all('/[a-z0-9]+/', strtolower($text), $matches, PREG_OFFSET_CAPTURE);
	$words = $matches[0];
	foreach ($words as $index => [$word, $offset]) {
		$hit    = dbvc_release_word_names_client($word);
		$phrase = $word;
		for ($next = 1; ! $hit && $next <= 2 && isset($words[$index + $next]); $next++) {
			$phrase .= $words[$index + $next][0];
			$hit     = isset($sizes[strlen($phrase)]) && isset($phrases[dbvc_release_fingerprint($phrase)]);
		}
		if ($hit) {
			return 1 + substr_count($text, "\n", 0, $offset);
		}
	}
	return 0;
}

/**
 * Tracked files that a PHP string literal names by path.
 *
 * A literal is resolved against the directory of its file and each parent up
 * to the plugin root, nearest first, which is how `__DIR__ . '/x'`,
 * `dirname(__DIR__) . '/x'` and `DBVC_PLUGIN_PATH . 'x'` are written here.
 *
 * @param string             $path    Path of the PHP file.
 * @param string             $source  Its contents.
 * @param array<string,bool> $tracked Tracked paths and directories as keys.
 * @return array<int,string>
 */
function dbvc_release_named_paths($path, $source, array $tracked)
{
	$named = [];
	foreach (token_get_all($source) as $token) {
		if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
			continue;
		}
		$literal = trim($token[1], "'\"");
		$target  = trim($literal, '/');
		if (strlen($literal) > 200 || ! preg_match('~^[\w.-]+(/[\w.-]+)*$~', $target) || false === strpos($literal, '/')) {
			continue;
		}
		$directory = $path;
		do {
			$directory = dirname($directory);
			$candidate = ('.' === $directory ? '' : $directory . '/') . $target;
			if (isset($tracked[$candidate])) {
				// A one-word directory such as '/bin' is too often a system path.
				if (false === $tracked[$candidate] || false !== strpos($target, '/')) {
					$named[] = $candidate;
				}
				break;
			}
		} while ('.' !== $directory);
	}
	return array_unique($named);
}

$options = [];
foreach (array_slice($argv, 1) as $argument) {
	if (! preg_match('/^--(ref|worktree-attributes|fingerprint)(?:=(.+))?$/', $argument, $match)) {
		fwrite(STDERR, "Unknown argument: {$argument}\n");
		exit(2);
	}
	$options[$match[1]] = $match[2] ?? '';
}

if (isset($options['fingerprint'])) {
	$normalised = dbvc_release_normalise($options['fingerprint']);
	if (strlen($normalised) < 6) {
		fwrite(STDERR, "Use a name of six or more letters and digits; a shorter one matches ordinary words.\n");
		exit(2);
	}
	printf("\t[%d, '%s'],\n", strlen($normalised), dbvc_release_fingerprint($normalised));
	exit(0);
}

$root = dirname(__DIR__);
$ref  = empty($options['ref']) ? 'HEAD' : $options['ref'];

$work = sys_get_temp_dir() . '/dbvc-release-' . bin2hex(random_bytes(6));
mkdir($work . '/files', 0700, true);
register_shutdown_function(static function () use ($work) {
	exec('rm -rf ' . escapeshellarg($work));
});

$archive = ['git', '-C', $root, 'archive', '--format=tar', '--output=' . $work . '/release.tar'];
if (isset($options['worktree-attributes'])) {
	$archive[] = '--worktree-attributes';
}
$archive[] = $ref;
dbvc_release_run($archive);
dbvc_release_run(['tar', '-xf', $work . '/release.tar', '-C', $work . '/files']);

$tracked = dbvc_release_run(['git', '-C', $root, '-c', 'core.quotePath=off', 'ls-tree', '-r', '--name-only', $ref]);
$shipped = [];
$files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work . '/files', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
	$shipped[substr((string) $file, strlen($work . '/files/'))] = true;
}
if (! $shipped || ! $tracked) {
	fwrite(STDERR, "The archive of {$ref} is empty.\n");
	exit(2);
}

$failures = ['internal' => [], 'dropped' => [], 'client' => [], 'named' => []];

foreach ($tracked as $path) {
	$reason = dbvc_release_internal_reason($path);
	if ('' !== $reason && isset($shipped[$path])) {
		$failures['internal'][] = "{$path} ({$reason})";
	} elseif ('' === $reason && ! isset($shipped[$path])) {
		$failures['dropped'][] = $path;
	}
}

// Directories as keys too (true), so a literal naming a directory resolves.
$known = array_fill_keys($tracked, false);
foreach ($tracked as $path) {
	for ($directory = dirname($path); '.' !== $directory; $directory = dirname($directory)) {
		$known[$directory] = true;
	}
}
$shipped_dirs = [];
foreach (array_keys($shipped) as $path) {
	for ($directory = dirname($path); '.' !== $directory; $directory = dirname($directory)) {
		$shipped_dirs[$directory] = true;
	}
}
$unshipped_docs = array_filter($tracked, static function ($path) use ($shipped) {
	return ! isset($shipped[$path]) && 0 === strpos($path, 'docs/');
});

ksort($shipped);
foreach (array_keys($shipped) as $path) {
	$contents = (string) file_get_contents($work . '/files/' . $path);

	if (dbvc_release_client_line($path)) {
		$failures['client'][] = "{$path} (in its path)";
	} elseif ($line = dbvc_release_client_line($contents)) {
		$failures['client'][] = "{$path}:{$line}";
	}

	// Judged on the files that should ship, so internal code that ships by
	// mistake does not add its own references to the report.
	if ('' !== dbvc_release_internal_reason($path) || 0 === strpos($path, 'vendor/')) {
		continue;
	}
	if ('.php' === substr($path, -4)) {
		foreach (dbvc_release_named_paths($path, $contents, $known) as $named) {
			if (! isset($shipped[$named]) && ! isset($shipped_dirs[$named])) {
				$failures['named'][] = "{$named} (named in {$path})";
			}
		}
	} elseif (0 === strpos($path, 'build/') && '.js' === substr($path, -3)) {
		foreach ($unshipped_docs as $doc) {
			if (false !== strpos($contents, $doc)) {
				$failures['named'][] = "{$doc} (named in {$path})";
			}
		}
	}
}

$headings = [
	'internal' => 'ships but must not; add an export-ignore rule to .gitattributes, or list it here if runtime reads it',
	'dropped'  => 'is runtime code but does not ship; an export-ignore rule is too broad',
	'client'   => 'ships and names a client',
	'named'    => 'is named by runtime code but does not ship; re-include it in .gitattributes and DBVC_RELEASE_SHIPPED',
];
$failed = 0;
foreach ($headings as $kind => $heading) {
	foreach ($failures[$kind] as $detail) {
		echo "FAIL {$detail}: {$heading}\n";
		++$failed;
	}
}

printf("%s %s: %d of %d tracked files ship", $failed ? 'FAILED' : 'PASSED', $ref, count($shipped), count($tracked));
if ($failed) {
	printf(
		'; %d internal, %d dropped, %d naming a client, %d named but missing',
		count($failures['internal']),
		count($failures['dropped']),
		count($failures['client']),
		count($failures['named'])
	);
}
echo ".\n";
exit($failed ? 1 : 0);
