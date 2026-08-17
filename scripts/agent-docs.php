#!/usr/bin/env php
<?php

declare(strict_types=1);

const DBVC_AGENT_DOCS_VERSION = '1.0.0';
const DBVC_AGENT_FACETS = [
    'cli' => ['label' => 'CLI and automation', 'path' => 'facets/cli-and-automation.md'],
    'import_export' => ['label' => 'Core import/export', 'path' => 'facets/core-import-export.md'],
    'proposals_media' => ['label' => 'Proposals and media', 'path' => 'facets/proposals-and-media.md'],
    'identity_storage' => ['label' => 'Identity and storage', 'path' => 'facets/identity-storage-and-observability.md'],
    'entity_editor' => ['label' => 'Entity Editor', 'path' => 'facets/entity-editor.md'],
    'settings_extensions' => ['label' => 'Settings and extensions', 'path' => 'facets/settings-hooks-and-extensions.md'],
    'bricks' => ['label' => 'Bricks add-on', 'path' => 'facets/bricks-addon.md'],
    'content_migration' => ['label' => 'Content Migration add-on', 'path' => 'facets/content-migration-addon.md'],
    'non_active' => ['label' => 'Staged/planned/absent', 'path' => 'facets/staged-planned-and-absent.md'],
];

$repositoryRoot = dirname(__DIR__);
$command = $argv[1] ?? '';

try {
    if (! in_array($command, ['discover', 'build', 'check', 'query'], true)) {
        throw new RuntimeException('Usage: php scripts/agent-docs.php <discover|build|check|query> [facet terms]');
    }

    if ($command === 'discover') {
        $snapshot = discoverRepository($repositoryRoot);
        validateSnapshotSourceRefs($repositoryRoot, $snapshot);
        writeJsonFile(agentDocsPath($repositoryRoot, 'generated/discovery-snapshot.json'), $snapshot);
        printDiscoverySummary($snapshot, 'Wrote discovery snapshot');
        exit(0);
    }

    $manifest = loadAndValidateManifest($repositoryRoot);

    if ($command === 'query') {
        queryManifest($manifest, array_slice($argv, 2));
        exit(0);
    }

    if ($command === 'build') {
        $snapshot = writeGeneratedIndexes($repositoryRoot, $manifest);
        updateReadmeIndex($repositoryRoot, $manifest, $snapshot);
        printManifestSummary($manifest, 'Built generated agent indexes');
        exit(0);
    }

    $errors = [];
    $discoveredSnapshot = discoverRepository($repositoryRoot);
    validateSnapshotSourceRefs($repositoryRoot, $discoveredSnapshot);
    $expectedSnapshot = canonicalJson($discoveredSnapshot);
    $snapshotPath = agentDocsPath($repositoryRoot, 'generated/discovery-snapshot.json');
    compareGeneratedFile($snapshotPath, $expectedSnapshot, $errors);

    foreach (renderIndexes($manifest, $discoveredSnapshot) as $relativePath => $contents) {
        compareGeneratedFile(agentDocsPath($repositoryRoot, $relativePath), $contents, $errors);
    }

    $readmePath = agentDocsPath($repositoryRoot, 'README.md');
    $readme = readRequiredFile($readmePath);
    $expectedReadme = replaceGeneratedReadmeBlock($readme, renderReadmeSummary($manifest, $discoveredSnapshot));
    if ($readme !== $expectedReadme) {
        $errors[] = 'Generated README summary is stale. Run composer agent-docs:build.';
    }

    $snapshot = json_decode($expectedSnapshot, true, 512, JSON_THROW_ON_ERROR);
    $coverage = calculateCoverage($manifest, $snapshot);
    validateCoverageMappings($manifest, $snapshot, $errors);
    if ($manifest['coverage_enforcement'] === 'strict' && $coverage['unmapped_count'] > 0) {
        $errors[] = sprintf(
            'Strict coverage failed: %d discovered surfaces are neither mapped nor ignored.',
            $coverage['unmapped_count']
        );
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            fwrite(STDERR, '[agent-docs] ERROR: ' . $error . PHP_EOL);
        }
        exit(1);
    }

    printf(
        "Agent docs check passed: %d curated records; %d discovered surfaces; %d unmapped (%s enforcement).\n",
        count($manifest['records']),
        $coverage['surface_count'],
        $coverage['unmapped_count'],
        $manifest['coverage_enforcement']
    );
} catch (Throwable $exception) {
    fwrite(STDERR, '[agent-docs] ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function agentDocsPath(string $root, string $relative): string
{
    return $root . '/docs/agents/' . ltrim($relative, '/');
}

function discoverRepository(string $root): array
{
    $files = discoverablePhpFiles($root);
    $metadata = repositoryMetadata($root, $files);

    $bootstrap = discoverBootstrap($root);
    $cli = discoverCli($root);
    $restRoutes = [];
    $hookListeners = [];
    $extensionPoints = [];
    $adminHandlers = [];
    $settings = [];
    $databaseTables = [];
    $scheduledHooks = [];
    $adminMenus = [];

    foreach ($files as $relativePath) {
        $absolutePath = $root . '/' . $relativePath;
        $contents = readRequiredFile($absolutePath);
        $scope = scopeForPath($relativePath);

        foreach (discoverRestRoutes($contents, $relativePath, $scope) as $route) {
            $restRoutes[] = $route;
        }

        foreach (discoverHooks($contents, $relativePath, $scope) as $hook) {
            if ($hook['kind'] === 'listener') {
                $hookListeners[] = $hook;
                if (str_starts_with($hook['hook'], 'admin_post_') || str_starts_with($hook['hook'], 'wp_ajax_')) {
                    $adminHandlers[] = $hook;
                }
            } else {
                $extensionPoints[] = $hook;
            }
        }

        foreach (discoverSettings($contents, $relativePath, $scope) as $setting) {
            $key = $setting['key'];
            if (! isset($settings[$key])) {
                $settings[$key] = [
                    'discovery_id' => 'setting.' . $key,
                    'key' => $key,
                    'operations' => [],
                    'scopes' => [],
                    'source_refs' => [],
                ];
            }
            $settings[$key]['operations'][] = $setting['operation'];
            $settings[$key]['scopes'][] = $scope;
            $settings[$key]['source_refs'][] = $setting['source_ref'];
        }

        foreach (discoverDatabaseTables($contents, $relativePath, $scope) as $table) {
            $databaseTables[$table['name']] = $table;
        }

        foreach (discoverScheduledHooks($contents, $relativePath, $scope) as $scheduledHook) {
            $scheduledHooks[] = $scheduledHook;
        }

        foreach (discoverAdminMenus($contents, $relativePath, $scope) as $adminMenu) {
            $adminMenus[] = $adminMenu;
        }
    }

    foreach ($settings as &$setting) {
        $setting['operations'] = sortedUnique($setting['operations']);
        $setting['scopes'] = sortedUnique($setting['scopes']);
        $setting['source_refs'] = uniqueSourceRefs($setting['source_refs']);
    }
    unset($setting);

    $collections = [
        'bootstrap' => $bootstrap,
        'cli_namespaces' => $cli['namespaces'],
        'cli_commands' => $cli['commands'],
        'rest_routes' => sortedItems($restRoutes, ['namespace', 'route', 'method_expression', 'source_ref.path', 'source_ref.line']),
        'admin_menus' => sortedItems($adminMenus, ['function', 'source_ref.path', 'source_ref.line']),
        'admin_handlers' => sortedItems($adminHandlers, ['hook', 'source_ref.path', 'source_ref.line']),
        'hook_listeners' => sortedItems($hookListeners, ['hook', 'source_ref.path', 'source_ref.line']),
        'extension_points' => sortedItems($extensionPoints, ['hook', 'source_ref.path', 'source_ref.line']),
        'settings' => array_values($settings),
        'database_tables' => array_values($databaseTables),
        'scheduled_hooks' => sortedItems($scheduledHooks, ['hook', 'function', 'source_ref.path', 'source_ref.line']),
        'tests' => discoverFilesByPattern($root, 'tests', '/Test\.php$/'),
        'documentation' => discoverFilesByPattern($root, 'docs', '/\.(?:md|json)$/'),
        'source_reference_documentation' => discoverFilesByPattern($root, '_source/content-collector/docs', '/\.(?:md|json)$/'),
    ];

    ksort($collections);
    foreach ($collections as $name => $items) {
        if (is_array($items) && array_is_list($items)) {
            $collections[$name] = array_values($items);
        }
    }

    $counts = [];
    foreach ($collections as $name => $items) {
        if ($name === 'bootstrap') {
            $counts['bootstrap_includes'] = count($items['includes']);
            $counts['bootstrap_initializers'] = count($items['initializers']);
            continue;
        }
        $counts[$name] = is_array($items) ? count($items) : 0;
    }
    ksort($counts);

    return [
        'schema_version' => DBVC_AGENT_DOCS_VERSION,
        'snapshot_status' => 'observed_unreviewed',
        'repository' => $metadata,
        'counts' => $counts,
        'collections' => $collections,
        'limitations' => [
            'Static discovery records literal registrations and references; dynamically composed hooks, routes, settings keys, and table names may require manual research.',
            'Observed source presence does not establish runtime activation, safety, support level, or permission for autonomous use.',
            'Core and Bricks paths are classified from this checkout; live LocalWP activation and route availability have not been verified.',
            'Source files under _source/content-collector are classified as source_reference and must not be treated as runtime-loaded.',
            'Settings discovery covers literal dbvc_* keys passed to common WordPress option functions; class constants and dynamically assembled keys require manual reconciliation.',
        ],
    ];
}

function discoverablePhpFiles(string $root): array
{
    $targets = [
        'db-version-control.php',
        'commands',
        'admin',
        'includes',
        'addons',
        '_source/content-collector',
    ];
    $files = [];

    foreach ($targets as $target) {
        $absolute = $root . '/' . $target;
        if (is_file($absolute)) {
            $files[] = $target;
            continue;
        }
        if (! is_dir($absolute)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
                continue;
            }
            $relative = relativePath($root, $fileInfo->getPathname());
            if (preg_match('#/(?:vendor|node_modules|tests)/#', '/' . $relative . '/')) {
                continue;
            }
            $files[] = $relative;
        }
    }

    return sortedUnique($files);
}

function discoverBootstrap(string $root): array
{
    $path = 'db-version-control.php';
    $contents = readRequiredFile($root . '/' . $path);
    $includes = [];
    if (preg_match_all('/\brequire(?:_once)?\s+([^;]+);/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[1] as $match) {
            $expression = normalizeExpression($match[0]);
            $includes[] = [
                'discovery_id' => 'bootstrap.include.' . substr(sha1($expression), 0, 12),
                'expression' => $expression,
                'source_ref' => sourceRef($path, lineForOffset($contents, $match[1])),
            ];
        }
    }

    $initializers = [];
    if (preg_match_all('/\b([A-Z][A-Za-z0-9_\\\\]+)::(init|bootstrap|activate)\s*\(/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $index => $match) {
            $symbol = $matches[1][$index][0] . '::' . $matches[2][$index][0];
            $initializers[] = [
                'discovery_id' => 'bootstrap.initializer.' . strtolower(str_replace(['\\', '::', '_'], ['.', '.', '-'], $symbol)),
                'symbol' => $symbol,
                'source_ref' => sourceRef($path, lineForOffset($contents, $match[1])),
            ];
        }
    }

    return [
        'entrypoint' => $path,
        'includes' => sortedItems($includes, ['expression', 'source_ref.line']),
        'initializers' => sortedItems($initializers, ['symbol', 'source_ref.line']),
    ];
}

function discoverCli(string $root): array
{
    $registrations = [];
    $methodsByClass = [];
    $classPaths = [];
    $paths = discoverFilesByPattern($root, 'commands', '/\\.php$/');

    foreach ($paths as $path) {
        $contents = readRequiredFile($root . '/' . $path);
        if (preg_match_all("/WP_CLI::add_command\\(\\s*['\"]([^'\"]+)['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $contents, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $wholeMatch) {
                $name = trim($matches[1][$index][0]);
                $class = $matches[2][$index][0];
                $registrations[] = [
                    'discovery_id' => 'cli.namespace.' . str_replace(' ', '.', $name),
                    'namespace' => $name,
                    'class' => $class,
                    'source_ref' => sourceRef($path, lineForOffset($contents, $wholeMatch[1])),
                ];
            }
        }

        foreach (parsePublicClassMethods($contents) as $class => $methods) {
            $methodsByClass[$class] = array_merge($methodsByClass[$class] ?? [], $methods);
            $classPaths[$class] = $path;
        }
    }

    $commands = [];
    foreach ($registrations as $registration) {
        foreach ($methodsByClass[$registration['class']] ?? [] as $method) {
            $leaf = $method['subcommand'] !== '' ? $method['subcommand'] : $method['name'];
            $command = $registration['namespace'] . ' ' . $leaf;
            $commands[] = [
                'discovery_id' => 'cli.command.' . str_replace(' ', '.', $command),
                'command' => $command,
                'namespace' => $registration['namespace'],
                'class' => $registration['class'],
                'method' => $method['name'],
                'summary' => $method['summary'],
                'synopsis_tokens' => $method['synopsis_tokens'],
                'source_ref' => sourceRef($classPaths[$registration['class']] ?? $registration['source_ref']['path'], $method['line']),
            ];
        }
    }

    return [
        'namespaces' => sortedItems($registrations, ['namespace']),
        'commands' => sortedItems($commands, ['command']),
    ];
}

function parsePublicClassMethods(string $contents): array
{
    $tokens = token_get_all($contents);
    $classes = [];
    $currentClass = null;
    $pendingClass = null;
    $classDepth = null;
    $braceDepth = 0;
    $interpolationDepth = 0;
    $lastDocComment = '';
    $lastDocLine = 0;

    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_DOC_COMMENT) {
            $lastDocComment = $token[1];
            $lastDocLine = $token[2];
            continue;
        }

        if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $interpolationDepth++;
            continue;
        }

        if (is_array($token) && $token[0] === T_CLASS) {
            $previousToken = previousSignificantToken($tokens, $index);
            if (is_array($previousToken) && $previousToken[0] === T_DOUBLE_COLON) {
                continue;
            }
            for ($cursor = $index + 1; $cursor < count($tokens); $cursor++) {
                if (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_STRING) {
                    $pendingClass = $tokens[$cursor][1];
                    break;
                }
            }
            continue;
        }

        if ($token === '{') {
            $braceDepth++;
            if ($pendingClass !== null) {
                $currentClass = $pendingClass;
                $pendingClass = null;
                $classDepth = $braceDepth;
                $classes[$currentClass] = $classes[$currentClass] ?? [];
            }
            continue;
        }

        if ($token === '}') {
            if ($interpolationDepth > 0) {
                $interpolationDepth--;
                continue;
            }
            if ($currentClass !== null && $classDepth === $braceDepth) {
                $currentClass = null;
                $classDepth = null;
            }
            $braceDepth--;
            continue;
        }

        if ($currentClass === null || ! is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        $visibility = methodVisibility($tokens, $index);
        if ($visibility !== 'public') {
            continue;
        }

        $name = null;
        for ($cursor = $index + 1; $cursor < count($tokens); $cursor++) {
            if (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_STRING) {
                $name = $tokens[$cursor][1];
                break;
            }
            if ($tokens[$cursor] === '(') {
                break;
            }
        }
        if ($name === null || str_starts_with($name, '__')) {
            continue;
        }

        $line = $token[2];
        $doc = ($lastDocLine > 0 && $line - $lastDocLine < 80) ? $lastDocComment : '';
        $classes[$currentClass][] = [
            'name' => $name,
            'line' => $line,
            'summary' => docblockSummary($doc),
            'synopsis_tokens' => docblockSynopsisTokens($doc),
            'subcommand' => docblockSubcommand($doc),
        ];
    }

    return $classes;
}

function docblockSubcommand(string $doc): string
{
    if (preg_match('/@subcommand\s+([^\s*]+)/', $doc, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

function methodVisibility(array $tokens, int $functionIndex): string
{
    $visibility = 'public';
    for ($cursor = $functionIndex - 1; $cursor >= 0 && $cursor >= $functionIndex - 20; $cursor--) {
        $token = $tokens[$cursor];
        if ($token === ';' || $token === '{' || $token === '}') {
            break;
        }
        if (! is_array($token)) {
            continue;
        }
        if ($token[0] === T_PRIVATE) {
            return 'private';
        }
        if ($token[0] === T_PROTECTED) {
            return 'protected';
        }
        if ($token[0] === T_PUBLIC) {
            $visibility = 'public';
        }
    }
    return $visibility;
}

function previousSignificantToken(array $tokens, int $index): mixed
{
    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $token;
    }
    return null;
}

function docblockSummary(string $doc): string
{
    if ($doc === '') {
        return '';
    }
    $lines = preg_split('/\R/', $doc) ?: [];
    foreach ($lines as $line) {
        $line = trim(preg_replace('/^\s*\/\*\*?|\*\/\s*$|^\s*\*\s?/', '', $line));
        if ($line === '' || str_starts_with($line, '@') || str_starts_with($line, '##')) {
            continue;
        }
        return rtrim($line, '.');
    }
    return '';
}

function docblockSynopsisTokens(string $doc): array
{
    if ($doc === '') {
        return [];
    }

    $tokens = [];
    foreach (preg_split('/\R/', $doc) ?: [] as $line) {
        $line = trim((string) preg_replace('/^\s*\*\s?/', '', $line));
        if (preg_match('/^<[^>]+>(?:\.\.\.)?$/', $line) === 1) {
            $tokens[] = $line;
            continue;
        }
        if (str_starts_with($line, '[--') && str_ends_with($line, ']')) {
            $tokens[] = $line;
        }
    }

    return sortedUnique($tokens);
}

function discoverRestRoutes(string $contents, string $path, string $scope): array
{
    $routes = [];
    $pattern = "/register_rest_route\\(\\s*(['\"])([^'\"]+)\\1\\s*,\\s*(['\"])([^'\"]+)\\3/";
    if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    foreach ($matches[0] as $index => $wholeMatch) {
        $namespace = $matches[2][$index][0];
        $route = $matches[4][$index][0];
        $offset = $wholeMatch[1];
        $nextOffset = $matches[0][$index + 1][1] ?? min(strlen($contents), $offset + 2400);
        $callSlice = substr($contents, $offset, min($nextOffset - $offset, 2400));
        $methodExpression = extractArrayValueExpression($callSlice, 'methods');
        $callbackExpression = extractArrayValueExpression($callSlice, 'callback');
        $permissionExpression = extractArrayValueExpression($callSlice, 'permission_callback');
        $signature = $namespace . $route . '|' . $methodExpression . '|' . $path . ':' . lineForOffset($contents, $offset);
        $routes[] = [
            'discovery_id' => 'rest.' . substr(sha1($signature), 0, 16),
            'scope' => $scope,
            'namespace' => $namespace,
            'route' => $route,
            'method_expression' => $methodExpression,
            'callback_expression' => $callbackExpression,
            'permission_expression' => $permissionExpression,
            'source_ref' => sourceRef($path, lineForOffset($contents, $offset)),
        ];
    }

    return $routes;
}

function extractArrayValueExpression(string $slice, string $key): string
{
    $pattern = "/['\"]" . preg_quote($key, '/') . "['\"]\\s*=>\\s*([^,\n]+(?:,[^\n\]]+\])?)/";
    if (! preg_match($pattern, $slice, $match)) {
        return '';
    }
    return normalizeExpression($match[1]);
}

function discoverHooks(string $contents, string $path, string $scope): array
{
    $hooks = [];
    $pattern = "/\\b(add_action|add_filter|do_action|apply_filters)\\s*\\(\\s*['\"]([^'\"]+)['\"]/";
    if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    foreach ($matches[0] as $index => $wholeMatch) {
        $function = $matches[1][$index][0];
        $hook = $matches[2][$index][0];
        $kind = in_array($function, ['do_action', 'apply_filters'], true) ? 'extension_point' : 'listener';
        if ($kind === 'extension_point' && ! str_starts_with($hook, 'dbvc_')) {
            continue;
        }
        $hooks[] = [
            'discovery_id' => 'hook.' . $kind . '.' . sanitizeDiscoveryPart($hook) . '.' . substr(sha1($path . ':' . $wholeMatch[1]), 0, 8),
            'kind' => $kind,
            'scope' => $scope,
            'function' => $function,
            'hook' => $hook,
            'source_ref' => sourceRef($path, lineForOffset($contents, $wholeMatch[1])),
        ];
    }
    return $hooks;
}

function discoverSettings(string $contents, string $path, string $scope): array
{
    $settings = [];
    $pattern = "/\\b(get_option|update_option|add_option|delete_option)\\s*\\(\\s*['\"](dbvc_[A-Za-z0-9_]+)['\"]/";
    if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    foreach ($matches[0] as $index => $wholeMatch) {
        $settings[] = [
            'operation' => $matches[1][$index][0],
            'key' => $matches[2][$index][0],
            'scope' => $scope,
            'source_ref' => sourceRef($path, lineForOffset($contents, $wholeMatch[1])),
        ];
    }
    return $settings;
}

function discoverDatabaseTables(string $contents, string $path, string $scope): array
{
    $tables = [];
    $patterns = [
        '/\\$wpdb->prefix\\s*\\.\\s*[\'\"](dbvc_[A-Za-z0-9_]+)[\'\"]/',
        '/\\{\\$wpdb->prefix\\}(dbvc_[A-Za-z0-9_]+)/',
    ];
    foreach ($patterns as $pattern) {
        if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($matches[0] as $index => $wholeMatch) {
            $name = $matches[1][$index][0];
            $tables[] = [
                'discovery_id' => 'database.table.' . $name,
                'scope' => $scope,
                'name' => $name,
                'source_ref' => sourceRef($path, lineForOffset($contents, $wholeMatch[1])),
            ];
        }
    }
    return $tables;
}

function discoverScheduledHooks(string $contents, string $path, string $scope): array
{
    $hooks = [];
    $pattern = "/\\b(wp_schedule_event|wp_schedule_single_event|wp_next_scheduled|wp_clear_scheduled_hook)\\s*\\([^;]{0,500}?['\"](dbvc_[^'\"]+)['\"]/s";
    if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    foreach ($matches[0] as $index => $wholeMatch) {
        $function = $matches[1][$index][0];
        $hook = $matches[2][$index][0];
        $hooks[] = [
            'discovery_id' => 'cron.' . sanitizeDiscoveryPart($hook) . '.' . sanitizeDiscoveryPart($function) . '.' . substr(sha1($path . ':' . $wholeMatch[1]), 0, 8),
            'scope' => $scope,
            'function' => $function,
            'hook' => $hook,
            'source_ref' => sourceRef($path, lineForOffset($contents, $wholeMatch[1])),
        ];
    }
    return $hooks;
}

function discoverAdminMenus(string $contents, string $path, string $scope): array
{
    $menus = [];
    if (! preg_match_all('/\b(add_menu_page|add_submenu_page)\s*\(/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    foreach ($matches[0] as $index => $wholeMatch) {
        $function = $matches[1][$index][0];
        $offset = $wholeMatch[1];
        $slice = substr($contents, $offset, 1000);
        preg_match_all("/['\"]([^'\"]+)['\"]/", $slice, $stringMatches);
        $menus[] = [
            'discovery_id' => 'admin.menu.' . substr(sha1($path . ':' . $offset), 0, 12),
            'scope' => $scope,
            'function' => $function,
            'observed_string_literals' => array_slice($stringMatches[1] ?? [], 0, 8),
            'source_ref' => sourceRef($path, lineForOffset($contents, $offset)),
        ];
    }
    return $menus;
}

function discoverFilesByPattern(string $root, string $relativeDirectory, string $pattern): array
{
    $directory = $root . '/' . $relativeDirectory;
    if (! is_dir($directory)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && preg_match($pattern, $fileInfo->getFilename())) {
            $relative = relativePath($root, $fileInfo->getPathname());
            if ($relativeDirectory === 'docs' && str_starts_with($relative, 'docs/agents/generated/')) {
                continue;
            }
            $files[] = $relative;
        }
    }
    return sortedUnique($files);
}

function scopeForPath(string $path): string
{
    if (str_starts_with($path, '_source/content-collector/')) {
        return 'source_reference';
    }
    if (str_starts_with($path, 'addons/bricks/')) {
        return 'addon:bricks';
    }
    if (str_starts_with($path, 'addons/content-migration/')) {
        return 'addon:content_migration_guard';
    }
    return 'core';
}

function repositoryMetadata(string $root, array $sourceFiles): array
{
    $hash = hash_init('sha256');
    foreach ($sourceFiles as $relativePath) {
        hash_update($hash, $relativePath . "\0");
        if (! hash_update_file($hash, $root . '/' . $relativePath)) {
            throw new RuntimeException('Unable to fingerprint discovery source: ' . $relativePath);
        }
    }

    return [
        'scope' => 'repository_only',
        'source_file_count' => count($sourceFiles),
        'source_fingerprint' => 'sha256:' . hash_final($hash),
        'live_runtime_verified' => false,
    ];
}

function loadAndValidateManifest(string $root): array
{
    foreach (DBVC_AGENT_FACETS as $facet) {
        if (! is_file(agentDocsPath($root, $facet['path']))) {
            throw new RuntimeException('Missing agent facet: ' . $facet['path']);
        }
    }
    $schemaPath = agentDocsPath($root, 'manifest.schema.json');
    json_decode(readRequiredFile($schemaPath), true, 512, JSON_THROW_ON_ERROR);
    $manifestPath = agentDocsPath($root, 'manifest.json');
    $manifest = json_decode(readRequiredFile($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($manifest)) {
        throw new RuntimeException('Manifest root must be an object.');
    }

    $required = ['schema_version', 'library_status', 'coverage_enforcement', 'baseline', 'records', 'ignored_discovery'];
    foreach ($required as $key) {
        if (! array_key_exists($key, $manifest)) {
            throw new RuntimeException('Manifest is missing required key: ' . $key);
        }
    }
    if ($manifest['schema_version'] !== DBVC_AGENT_DOCS_VERSION) {
        throw new RuntimeException('Unsupported manifest schema version.');
    }
    if (! in_array($manifest['coverage_enforcement'], ['advisory', 'strict'], true)) {
        throw new RuntimeException('Invalid coverage_enforcement value.');
    }
    if (! in_array($manifest['library_status'], ['discovery_pending', 'research_in_progress', 'review_pending', 'current'], true)) {
        throw new RuntimeException('Invalid library_status value.');
    }
    if (! is_array($manifest['baseline'] ?? null)) {
        throw new RuntimeException('Manifest baseline must be an object.');
    }
    if (! in_array($manifest['baseline']['repository_scope'] ?? '', ['current_checkout', 'verified_live_checkout'], true)) {
        throw new RuntimeException('Invalid baseline.repository_scope value.');
    }
    if (! is_bool($manifest['baseline']['live_runtime_verified'] ?? null)) {
        throw new RuntimeException('baseline.live_runtime_verified must be boolean.');
    }
    if (isset($manifest['baseline']['repository_commit']) && ! preg_match('/^[0-9a-f]{7,40}$/', (string) $manifest['baseline']['repository_commit'])) {
        throw new RuntimeException('Invalid baseline.repository_commit value.');
    }
    if (! is_array($manifest['records']) || ! array_is_list($manifest['records'])) {
        throw new RuntimeException('Manifest records must be a JSON array.');
    }
    if (! is_array($manifest['ignored_discovery']) || ! array_is_list($manifest['ignored_discovery'])) {
        throw new RuntimeException('Manifest ignored_discovery must be a JSON array.');
    }

    $ids = [];
    foreach ($manifest['records'] as $index => $record) {
        validateRecord($root, $record, $index, $ids);
        if (isset($manifest['baseline']['repository_commit']) && $record['verification']['repository_commit'] !== $manifest['baseline']['repository_commit']) {
            throw new RuntimeException('Record verification commit differs from the manifest baseline: ' . $record['id']);
        }
    }
    validateRelatedIds($manifest['records'], $ids);
    validateRecipeReferences($root, $ids);

    return $manifest;
}

function validateSnapshotSourceRefs(string $root, array $snapshot): void
{
    $lineCountCache = [];
    $walk = function (mixed $value) use (&$walk, $root, &$lineCountCache): void {
        if (! is_array($value)) {
            return;
        }
        if (isset($value['discovery_id'])) {
            $hasSource = isset($value['source_ref']) || (! empty($value['source_refs']) && is_array($value['source_refs']));
            if (! $hasSource) {
                throw new RuntimeException('Discovered item lacks source evidence: ' . $value['discovery_id']);
            }
        }
        if (isset($value['source_ref']) && is_array($value['source_ref'])) {
            $ref = $value['source_ref'];
            $path = $root . '/' . ltrim((string) ($ref['path'] ?? ''), '/');
            $line = (int) ($ref['line'] ?? 0);
            if (! is_file($path) || $line < 1) {
                throw new RuntimeException('Invalid discovery source reference: ' . json_encode($ref));
            }
            if (! isset($lineCountCache[$path])) {
                $lineCountCache[$path] = substr_count(readRequiredFile($path), "\n") + 1;
            }
            if ($line > $lineCountCache[$path]) {
                throw new RuntimeException('Discovery source line is outside file: ' . json_encode($ref));
            }
        }
        foreach ($value as $child) {
            $walk($child);
        }
    };
    $walk($snapshot['collections'] ?? []);
}

function validateRecord(string $root, mixed $record, int $index, array &$ids): void
{
    if (! is_array($record)) {
        throw new RuntimeException(sprintf('Manifest record %d must be an object.', $index));
    }
    $required = [
        'id', 'title', 'summary', 'status', 'primary_category', 'tags', 'aliases', 'addon_or_owner',
        'surfaces', 'requirements', 'inputs', 'outputs', 'artifacts', 'storage_touched', 'settings', 'hooks',
        'safety', 'source_refs', 'test_refs', 'doc_refs', 'related', 'known_gaps', 'verification',
    ];
    foreach ($required as $key) {
        if (! array_key_exists($key, $record)) {
            throw new RuntimeException(sprintf('Manifest record %d is missing %s.', $index, $key));
        }
    }
    if (! is_string($record['id']) || ! preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $record['id'])) {
        throw new RuntimeException(sprintf('Manifest record %d has an invalid ID.', $index));
    }
    if (isset($ids[$record['id']])) {
        throw new RuntimeException('Duplicate manifest record ID: ' . $record['id']);
    }
    $ids[$record['id']] = true;
    $allowedStatuses = ['active', 'experimental', 'planned', 'source_reference', 'deprecated', 'absent_current_checkout', 'unknown_requires_verification'];
    if (! in_array($record['status'], $allowedStatuses, true)) {
        throw new RuntimeException('Invalid status for ' . $record['id']);
    }
    $allowedCategories = ['import_export', 'cli_automation', 'proposal_review', 'media_resolver', 'identity_entities', 'snapshots_backups', 'entity_editor', 'settings_configuration', 'api_extensions', 'addon_bricks', 'addon_content_migration', 'observability', 'internal_foundation'];
    if (! in_array($record['primary_category'], $allowedCategories, true)) {
        throw new RuntimeException('Invalid primary_category for ' . $record['id']);
    }
    $arrayFields = ['tags', 'aliases', 'surfaces', 'requirements', 'inputs', 'outputs', 'artifacts', 'storage_touched', 'settings', 'hooks', 'source_refs', 'test_refs', 'doc_refs', 'related', 'known_gaps'];
    foreach ($arrayFields as $field) {
        if (! is_array($record[$field]) || ! array_is_list($record[$field])) {
            throw new RuntimeException(sprintf('%s must be an array for %s.', $field, $record['id']));
        }
    }
    if (! is_array($record['tags']) || count($record['tags']) !== count(array_unique($record['tags']))) {
        throw new RuntimeException('Tags must be a unique array for ' . $record['id']);
    }
    foreach ($record['tags'] as $tag) {
        if (! is_string($tag) || ! preg_match('/^[a-z_]+:[a-z0-9_:-]+$/', $tag)) {
            throw new RuntimeException('Invalid tag for ' . $record['id'] . ': ' . (string) $tag);
        }
    }
    $allowedSurfaceTypes = ['cli', 'rest', 'admin', 'php', 'hook', 'ajax', 'admin_post', 'cron', 'filesystem', 'database'];
    foreach ($record['surfaces'] as $surface) {
        if (! is_array($surface) || ! in_array($surface['type'] ?? '', $allowedSurfaceTypes, true)) {
            throw new RuntimeException('Invalid surface type for ' . $record['id']);
        }
        if (! is_string($surface['identifier'] ?? null) || $surface['identifier'] === '') {
            throw new RuntimeException('Surface identifier is required for ' . $record['id']);
        }
        if (! is_array($surface['discovery_ids'] ?? null) || ! array_is_list($surface['discovery_ids'])) {
            throw new RuntimeException('Surface discovery_ids must be an array for ' . $record['id']);
        }
        if (count($surface['discovery_ids']) !== count(array_unique($surface['discovery_ids']))) {
            throw new RuntimeException('Surface discovery_ids must be unique for ' . $record['id']);
        }
    }
    $allowedSafety = ['read_only', 'filesystem_write', 'wordpress_write', 'remote_write', 'destructive', 'mixed', 'unknown'];
    if (! is_array($record['safety']) || ! in_array($record['safety']['classification'] ?? '', $allowedSafety, true)) {
        throw new RuntimeException('Invalid safety classification for ' . $record['id']);
    }
    if (! is_bool($record['safety']['human_reviewed'] ?? null)) {
        throw new RuntimeException('safety.human_reviewed must be boolean for ' . $record['id']);
    }
    if (! is_array($record['verification']) || ! is_string($record['verification']['repository_commit'] ?? null)) {
        throw new RuntimeException('verification.repository_commit is required for ' . $record['id']);
    }
    if (! is_array($record['verification']['evidence_types'] ?? null) || ! is_bool($record['verification']['live_runtime_verified'] ?? null)) {
        throw new RuntimeException('Invalid verification contract for ' . $record['id']);
    }
    if (isset($record['opportunity'])) {
        validateOpportunity($record['opportunity'], $record['id']);
    }
    foreach ($record['source_refs'] as $sourceRef) {
        if (! is_array($sourceRef) || empty($sourceRef['path'])) {
            throw new RuntimeException('Invalid source_ref for ' . $record['id']);
        }
        if (! file_exists($root . '/' . ltrim((string) $sourceRef['path'], '/'))) {
            throw new RuntimeException('Missing source_ref for ' . $record['id'] . ': ' . $sourceRef['path']);
        }
    }
    foreach (['test_refs', 'doc_refs'] as $referenceField) {
        foreach ($record[$referenceField] as $referencePath) {
            if (! is_string($referencePath) || $referencePath === '' || ! file_exists($root . '/' . ltrim($referencePath, '/'))) {
                throw new RuntimeException(sprintf('Missing %s for %s: %s', $referenceField, $record['id'], (string) $referencePath));
            }
        }
    }
}

function validateOpportunity(mixed $opportunity, string $recordId): void
{
    if (! is_array($opportunity)) {
        throw new RuntimeException('Opportunity metadata must be an object for ' . $recordId);
    }
    $required = ['disposition', 'priority', 'effort', 'recommended_surface', 'rationale', 'reviewed_date'];
    foreach ($required as $key) {
        if (! isset($opportunity[$key]) || ! is_string($opportunity[$key]) || $opportunity[$key] === '') {
            throw new RuntimeException(sprintf('opportunity.%s is required for %s.', $key, $recordId));
        }
    }
    if (! in_array($opportunity['disposition'], ['candidate', 'covered_elsewhere', 'deferred', 'not_recommended', 'needs_review'], true)) {
        throw new RuntimeException('Invalid opportunity disposition for ' . $recordId);
    }
    if (! in_array($opportunity['priority'], ['high', 'medium', 'low', 'none'], true)) {
        throw new RuntimeException('Invalid opportunity priority for ' . $recordId);
    }
    if (! in_array($opportunity['effort'], ['small', 'medium', 'large', 'unknown'], true)) {
        throw new RuntimeException('Invalid opportunity effort for ' . $recordId);
    }
    if (! in_array($opportunity['recommended_surface'], ['cli', 'rest', 'admin', 'php', 'docs', 'none'], true)) {
        throw new RuntimeException('Invalid opportunity recommended_surface for ' . $recordId);
    }
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $opportunity['reviewed_date'])) {
        throw new RuntimeException('Invalid opportunity reviewed_date for ' . $recordId);
    }
    if ($opportunity['disposition'] === 'candidate' && $opportunity['priority'] === 'none') {
        throw new RuntimeException('Candidate opportunity priority cannot be none for ' . $recordId);
    }
    if ($opportunity['disposition'] === 'candidate') {
        if (! isset($opportunity['candidate_scope']) || ! is_string($opportunity['candidate_scope']) || $opportunity['candidate_scope'] === '') {
            throw new RuntimeException('Candidate opportunity scope is required for ' . $recordId);
        }
        if (! isset($opportunity['excluded_operations']) || ! is_array($opportunity['excluded_operations']) || empty($opportunity['excluded_operations'])) {
            throw new RuntimeException('Candidate excluded_operations are required for ' . $recordId);
        }
    }
    if (isset($opportunity['excluded_operations'])) {
        if (! is_array($opportunity['excluded_operations']) || ! array_is_list($opportunity['excluded_operations'])) {
            throw new RuntimeException('Invalid opportunity excluded_operations for ' . $recordId);
        }
        foreach ($opportunity['excluded_operations'] as $excludedOperation) {
            if (! is_string($excludedOperation) || $excludedOperation === '') {
                throw new RuntimeException('Opportunity excluded_operations must contain non-empty strings for ' . $recordId);
            }
        }
    }
    if (isset($opportunity['related_record']) && (! is_string($opportunity['related_record']) || $opportunity['related_record'] === $recordId)) {
        throw new RuntimeException('Invalid opportunity related_record for ' . $recordId);
    }
}

function validateRelatedIds(array $records, array $ids): void
{
    foreach ($records as $record) {
        foreach ($record['related'] as $relatedId) {
            if (! isset($ids[$relatedId])) {
                throw new RuntimeException(sprintf('Record %s has dangling related ID %s.', $record['id'], $relatedId));
            }
        }
        $opportunityRelated = $record['opportunity']['related_record'] ?? '';
        if ($opportunityRelated !== '' && ! isset($ids[$opportunityRelated])) {
            throw new RuntimeException(sprintf('Record %s has dangling opportunity related_record %s.', $record['id'], $opportunityRelated));
        }
    }
}

function validateRecipeReferences(string $root, array $ids): void
{
    $path = agentDocsPath($root, 'RECIPES.md');
    $contents = readRequiredFile($path);
    $pattern = '/<!-- recipe:\s*([a-z0-9-]+)\s*-->\s*<!-- safety:\s*([a-z_]+)\s*-->\s*<!-- capability-records:\s*([^>]+?)\s*-->/';
    $matchCount = preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER);
    if ($matchCount === false || $matchCount < 1) {
        throw new RuntimeException('RECIPES.md must contain at least one valid recipe metadata block.');
    }
    if (substr_count($contents, '<!-- recipe:') !== $matchCount) {
        throw new RuntimeException('RECIPES.md contains malformed or incomplete recipe metadata.');
    }

    $recipeIds = [];
    foreach ($matches as $match) {
        $recipeId = $match[1];
        if (isset($recipeIds[$recipeId])) {
            throw new RuntimeException('Duplicate recipe ID: ' . $recipeId);
        }
        $recipeIds[$recipeId] = true;
        if ($match[2] !== 'read_only') {
            throw new RuntimeException('Phase 11 recipe must remain read_only: ' . $recipeId);
        }

        $recordIds = array_values(array_filter(array_map('trim', explode(',', $match[3]))));
        if (count($recordIds) < 2 || ! in_array('cli.core.capabilities.inspect', $recordIds, true)) {
            throw new RuntimeException('Recipe must reference the capability preflight record and at least one task record: ' . $recipeId);
        }
        if (count($recordIds) !== count(array_unique($recordIds))) {
            throw new RuntimeException('Recipe capability records must be unique: ' . $recipeId);
        }
        foreach ($recordIds as $recordId) {
            if (! isset($ids[$recordId])) {
                throw new RuntimeException(sprintf('Recipe %s references unknown capability record %s.', $recipeId, $recordId));
            }
        }
    }
}

function renderIndexes(array $manifest, array $snapshot): array
{
    $records = $manifest['records'];
    usort($records, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

    return [
        'generated/index-by-category.md' => renderGroupedIndex('Category', groupRecords($records, static fn(array $record): array => [$record['primary_category']])),
        'generated/index-by-tag.md' => renderGroupedIndex('Tag', groupRecords($records, static fn(array $record): array => $record['tags'])),
        'generated/index-by-surface.md' => renderGroupedIndex('Surface', groupRecords($records, static function (array $record): array {
            $values = [];
            foreach ($record['surfaces'] as $surface) {
                $values[] = $surface['type'];
            }
            return sortedUnique($values);
        })),
        'generated/index-by-operation.md' => renderGroupedIndex('Operation', groupRecords($records, static fn(array $record): array => tagValues($record['tags'], 'operation:'))),
        'generated/index-by-risk.md' => renderGroupedIndex('Risk', groupRecords($records, static function (array $record): array {
            return sortedUnique(array_merge(
                tagValues($record['tags'], 'risk:'),
                [$record['safety']['classification'] ?? 'unknown']
            ));
        })),
        'generated/index-by-alias.md' => renderAliasIndex($records),
        'generated/index-by-command.md' => renderCommandIndex($records, $snapshot),
        'generated/index-by-opportunity.md' => renderOpportunityIndex($records),
    ];
}

function renderOpportunityIndex(array $records): string
{
    $lines = [
        '# DBVC Agent Capability Opportunity Index',
        '',
        '> Generated by `scripts/agent-docs.php` from reviewed manifest opportunity metadata. Direct edits will be overwritten.',
        '',
        '| Record | Disposition | Priority | Effort | Recommended surface | Candidate boundary | Rationale |',
        '|---|---|---|---|---|---|---|',
    ];
    foreach ($records as $record) {
        $opportunity = $record['opportunity'] ?? null;
        $lines[] = sprintf(
            '| [`%s`](../manifest.json) | `%s` | `%s` | `%s` | `%s` | %s | %s |',
            escapeMarkdownTable($record['id']),
            escapeMarkdownTable($opportunity['disposition'] ?? 'unreviewed'),
            escapeMarkdownTable($opportunity['priority'] ?? 'none'),
            escapeMarkdownTable($opportunity['effort'] ?? 'unknown'),
            escapeMarkdownTable($opportunity['recommended_surface'] ?? 'none'),
            escapeMarkdownTable($opportunity['candidate_scope'] ?? 'Not applicable.'),
            escapeMarkdownTable($opportunity['rationale'] ?? 'Not yet reviewed for a concrete automation or parity opportunity.')
        );
    }
    $lines[] = '';
    return implode("\n", $lines);
}

function renderCommandIndex(array $records, array $snapshot): string
{
    $owners = [];
    foreach ($records as $record) {
        foreach ($record['surfaces'] as $surface) {
            foreach ($surface['discovery_ids'] ?? [] as $discoveryId) {
                $owners[$discoveryId] = $record;
            }
        }
    }

    $lines = [
        '# DBVC WP-CLI Command Index',
        '',
        '> Generated by `scripts/agent-docs.php` from source-discovered command signatures and curated manifest ownership. Direct edits will be overwritten.',
        '',
        '| Command | Arguments | Record | Status | Safety | Facets |',
        '|---|---|---|---|---|---|',
    ];

    foreach ($snapshot['collections']['cli_commands'] ?? [] as $command) {
        $record = $owners[$command['discovery_id']] ?? null;
        $arguments = implode(' ', $command['synopsis_tokens'] ?? []);
        $lines[] = sprintf(
            '| `%s` | %s | %s | `%s` | `%s` | %s |',
            escapeMarkdownTable('wp ' . $command['command']),
            $arguments === '' ? '_none_' : '`' . escapeMarkdownTable($arguments) . '`',
            $record === null ? '_unmapped_' : sprintf('[`%s`](../manifest.json)', escapeMarkdownTable($record['id'])),
            $record['status'] ?? 'unknown',
            $record['safety']['classification'] ?? 'unknown',
            $record === null ? '' : renderFacetLinks($record, '../')
        );
    }
    $lines[] = '';

    return implode("\n", $lines);
}

function groupRecords(array $records, callable $groupResolver): array
{
    $groups = [];
    foreach ($records as $record) {
        foreach ($groupResolver($record) as $group) {
            if ($group === '') {
                continue;
            }
            $groups[$group][] = $record;
        }
    }
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
    return $groups;
}

function tagValues(array $tags, string $prefix): array
{
    $values = [];
    foreach ($tags as $tag) {
        if (str_starts_with($tag, $prefix)) {
            $values[] = substr($tag, strlen($prefix));
        }
    }
    return sortedUnique($values);
}

function facetKeysForRecord(array $record): array
{
    $categoryMap = [
        'cli_automation' => 'cli',
        'import_export' => 'import_export',
        'proposal_review' => 'proposals_media',
        'media_resolver' => 'proposals_media',
        'identity_entities' => 'identity_storage',
        'snapshots_backups' => 'identity_storage',
        'internal_foundation' => 'identity_storage',
        'observability' => 'identity_storage',
        'entity_editor' => 'entity_editor',
        'settings_configuration' => 'settings_extensions',
        'api_extensions' => 'settings_extensions',
        'addon_bricks' => 'bricks',
        'addon_content_migration' => 'content_migration',
    ];
    $keys = [];
    if (isset($categoryMap[$record['primary_category']])) {
        $keys[] = $categoryMap[$record['primary_category']];
    }
    if (($record['status'] ?? '') !== 'active') {
        $keys[] = 'non_active';
    }
    return array_values(array_unique($keys));
}

function renderFacetLinks(array $record, string $pathPrefix): string
{
    $links = [];
    foreach (facetKeysForRecord($record) as $key) {
        $facet = DBVC_AGENT_FACETS[$key];
        $links[] = sprintf(
            '[%s](%s%s)',
            escapeMarkdownTable($facet['label']),
            $pathPrefix,
            $facet['path']
        );
    }
    return implode('<br>', $links);
}

function queryManifest(array $manifest, array $terms): void
{
    $terms = array_values(array_filter(array_map('trim', $terms), static fn(string $term): bool => $term !== ''));
    if ($terms === []) {
        throw new RuntimeException('Usage: php scripts/agent-docs.php query <term> [<term> ...]');
    }

    $matches = [];
    foreach ($manifest['records'] as $record) {
        $tokens = array_merge(
            $record['tags'],
            [
                'status:' . $record['status'],
                'category:' . $record['primary_category'],
                'safety:' . $record['safety']['classification'],
                'id:' . $record['id'],
                'opportunity:' . ($record['opportunity']['disposition'] ?? 'unreviewed'),
                'priority:' . ($record['opportunity']['priority'] ?? 'none'),
                'effort:' . ($record['opportunity']['effort'] ?? 'unknown'),
                'recommended:' . ($record['opportunity']['recommended_surface'] ?? 'none'),
            ]
        );
        $tokens = array_map('strtolower', $tokens);
        $matched = true;
        foreach ($terms as $term) {
            $needle = strtolower($term);
            $aliasMatch = false;
            foreach ($record['aliases'] as $alias) {
                if (str_contains(strtolower($alias), $needle)) {
                    $aliasMatch = true;
                    break;
                }
            }
            if (! in_array($needle, $tokens, true) && ! $aliasMatch && ! str_contains(strtolower($record['id']), $needle)) {
                $matched = false;
                break;
            }
        }
        if ($matched) {
            $matches[] = $record;
        }
    }

    usort($matches, static fn(array $left, array $right): int => strcmp($left['id'], $right['id']));
    printf("Query: %s\n", implode(' + ', $terms));
    if ($matches === []) {
        echo "No capability records matched.\n";
        return;
    }
    foreach ($matches as $record) {
        $facets = array_map(
            static fn(string $key): string => DBVC_AGENT_FACETS[$key]['path'],
            facetKeysForRecord($record)
        );
        printf(
            "%s\t%s\t%s\t%s\t%s\n",
            $record['id'],
            $record['status'],
            $record['safety']['classification'],
            implode(',', $facets),
            $record['summary']
        );
    }
}

function renderGroupedIndex(string $dimension, array $groups): string
{
    $lines = [
        '# DBVC Agent Capability Index By ' . $dimension,
        '',
        '> Generated by `scripts/agent-docs.php`. Direct edits will be overwritten.',
        '',
    ];
    if ($groups === []) {
        $lines[] = '_No curated capability records exist yet._';
        $lines[] = '';
        return implode("\n", $lines);
    }
    foreach ($groups as $name => $records) {
        $lines[] = '## `' . $name . '`';
        $lines[] = '';
        $lines[] = '| Record | Status | Safety | Facets | Summary |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($records as $record) {
            $lines[] = sprintf(
                '| [`%s`](../manifest.json) | `%s` | `%s` | %s | %s |',
                escapeMarkdownTable($record['id']),
                escapeMarkdownTable($record['status']),
                escapeMarkdownTable($record['safety']['classification'] ?? 'unknown'),
                renderFacetLinks($record, '../'),
                escapeMarkdownTable($record['summary'])
            );
        }
        $lines[] = '';
    }
    return implode("\n", $lines);
}

function renderAliasIndex(array $records): string
{
    $rows = [];
    foreach ($records as $record) {
        foreach ($record['aliases'] as $alias) {
            $rows[] = ['alias' => $alias, 'record' => $record];
        }
    }
    usort($rows, static fn(array $left, array $right): int => strnatcasecmp($left['alias'], $right['alias']));

    $lines = [
        '# DBVC Agent Capability Index By Alias',
        '',
        '> Generated by `scripts/agent-docs.php`. Direct edits will be overwritten.',
        '',
        '| Alias | Record | Status | Safety | Facets |',
        '|---|---|---|---|---|',
    ];
    foreach ($rows as $row) {
        $record = $row['record'];
        $lines[] = sprintf(
            '| %s | [`%s`](../manifest.json) | `%s` | `%s` | %s |',
            escapeMarkdownTable($row['alias']),
            escapeMarkdownTable($record['id']),
            escapeMarkdownTable($record['status']),
            escapeMarkdownTable($record['safety']['classification'] ?? 'unknown'),
            renderFacetLinks($record, '../')
        );
    }
    $lines[] = '';
    return implode("\n", $lines);
}

function renderReadmeSummary(array $manifest, array $snapshot): string
{
    if ($manifest['records'] === []) {
        return '_No curated capability records exist yet. Run the approved manifest research phase before using this index for capability decisions._';
    }
    $coverage = calculateCoverage($manifest, $snapshot);
    $opportunityCounts = [
        'candidate' => 0,
        'needs_review' => 0,
        'covered_elsewhere' => 0,
        'deferred' => 0,
        'not_recommended' => 0,
        'unreviewed' => 0,
    ];
    $counts = [];
    foreach ($manifest['records'] as $record) {
        $counts[$record['primary_category']] = ($counts[$record['primary_category']] ?? 0) + 1;
        $disposition = (string) ($record['opportunity']['disposition'] ?? 'unreviewed');
        if (array_key_exists($disposition, $opportunityCounts)) {
            ++$opportunityCounts[$disposition];
        }
    }
    ksort($counts);
    $lines = [
        '### Current inventory',
        '',
        sprintf(
            '- **%d** curated records cover **%d** enforced discovery surfaces; **%d** are unmapped.',
            count($manifest['records']),
            $coverage['surface_count'],
            $coverage['unmapped_count']
        ),
        sprintf(
            '- Source discovery identifies **%d** WP-CLI leaf commands and **%d** REST registrations.',
            (int) ($snapshot['counts']['cli_commands'] ?? 0),
            (int) ($snapshot['counts']['rest_routes'] ?? 0)
        ),
        sprintf(
            '- Opportunity dispositions: **%d** candidate, **%d** needs review, **%d** covered elsewhere, **%d** deferred, **%d** not recommended for further parity, and **%d** unreviewed.',
            $opportunityCounts['candidate'],
            $opportunityCounts['needs_review'],
            $opportunityCounts['covered_elsewhere'],
            $opportunityCounts['deferred'],
            $opportunityCounts['not_recommended'],
            $opportunityCounts['unreviewed']
        ),
        '',
        '### Records by category',
        '',
        '| Category | Records |',
        '|---|---:|',
    ];
    foreach ($counts as $category => $count) {
        $lines[] = sprintf('| `%s` | %d |', $category, $count);
    }
    $lines[] = '';
    $lines[] = sprintf('Total curated records: **%d**.', count($manifest['records']));
    return implode("\n", $lines);
}

function writeGeneratedIndexes(string $root, array $manifest): array
{
    $snapshot = discoverRepository($root);
    validateSnapshotSourceRefs($root, $snapshot);
    foreach (renderIndexes($manifest, $snapshot) as $relativePath => $contents) {
        writeTextFile(agentDocsPath($root, $relativePath), $contents);
    }
    return $snapshot;
}

function updateReadmeIndex(string $root, array $manifest, array $snapshot): void
{
    $path = agentDocsPath($root, 'README.md');
    $readme = readRequiredFile($path);
    writeTextFile($path, replaceGeneratedReadmeBlock($readme, renderReadmeSummary($manifest, $snapshot)));
}

function replaceGeneratedReadmeBlock(string $readme, string $replacement): string
{
    $start = '<!-- BEGIN GENERATED AGENT INDEX -->';
    $end = '<!-- END GENERATED AGENT INDEX -->';
    $pattern = '/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . '/s';
    if (preg_match($pattern, $readme) !== 1) {
        throw new RuntimeException('README generated index markers are missing or malformed.');
    }
    return preg_replace($pattern, $start . "\n" . $replacement . "\n" . $end, $readme, 1) ?? $readme;
}

function calculateCoverage(array $manifest, array $snapshot): array
{
    $surfaceIds = [];
    foreach (['cli_commands', 'rest_routes', 'admin_menus', 'admin_handlers', 'extension_points', 'settings', 'database_tables', 'scheduled_hooks'] as $collection) {
        foreach ($snapshot['collections'][$collection] ?? [] as $item) {
            if (isset($item['discovery_id'])) {
                $surfaceIds[$item['discovery_id']] = true;
            }
        }
    }

    $mapped = [];
    foreach ($manifest['records'] as $record) {
        foreach ($record['surfaces'] as $surface) {
            foreach ($surface['discovery_ids'] ?? [] as $discoveryId) {
                $mapped[$discoveryId] = true;
            }
        }
    }
    foreach ($manifest['ignored_discovery'] as $ignored) {
        $mapped[$ignored['discovery_id']] = true;
    }

    return [
        'surface_count' => count($surfaceIds),
        'mapped_count' => count(array_intersect_key($surfaceIds, $mapped)),
        'unmapped_count' => count(array_diff_key($surfaceIds, $mapped)),
    ];
}

function validateCoverageMappings(array $manifest, array $snapshot, array &$errors): void
{
    $surfaceIds = [];
    foreach (['cli_commands', 'rest_routes', 'admin_menus', 'admin_handlers', 'extension_points', 'settings', 'database_tables', 'scheduled_hooks'] as $collection) {
        foreach ($snapshot['collections'][$collection] ?? [] as $item) {
            if (isset($item['discovery_id'])) {
                $surfaceIds[$item['discovery_id']] = $collection;
            }
        }
    }

    $owners = [];
    foreach ($manifest['records'] as $record) {
        foreach ($record['surfaces'] as $surface) {
            foreach ($surface['discovery_ids'] ?? [] as $discoveryId) {
                $owners[$discoveryId][] = $record['id'];
            }
        }
    }
    foreach ($manifest['ignored_discovery'] as $ignored) {
        $owners[$ignored['discovery_id']][] = 'ignored_discovery';
    }

    foreach ($owners as $discoveryId => $mappedOwners) {
        if (! isset($surfaceIds[$discoveryId])) {
            $errors[] = 'Manifest maps an unknown discovery ID: ' . $discoveryId;
            continue;
        }
        if (count($mappedOwners) > 1) {
            $errors[] = sprintf(
                'Discovery ID has multiple owners (%s): %s',
                implode(', ', $mappedOwners),
                $discoveryId
            );
        }
    }
}

function compareGeneratedFile(string $path, string $expected, array &$errors): void
{
    if (! file_exists($path)) {
        $errors[] = 'Missing generated file: ' . $path;
        return;
    }
    if (readRequiredFile($path) !== $expected) {
        $errors[] = 'Generated file is stale: ' . $path;
    }
}

function printDiscoverySummary(array $snapshot, string $label): void
{
    printf(
        "%s (%s): %d CLI commands, %d REST registrations, %d settings, %d extension points.\n",
        $label,
        substr($snapshot['repository']['source_fingerprint'], 7, 12),
        $snapshot['counts']['cli_commands'],
        $snapshot['counts']['rest_routes'],
        $snapshot['counts']['settings'],
        $snapshot['counts']['extension_points']
    );
}

function printManifestSummary(array $manifest, string $label): void
{
    printf("%s: %d curated records (%s).\n", $label, count($manifest['records']), $manifest['library_status']);
}

function sourceRef(string $path, int $line): array
{
    return ['path' => $path, 'line' => $line];
}

function uniqueSourceRefs(array $refs): array
{
    $unique = [];
    foreach ($refs as $ref) {
        $unique[$ref['path'] . ':' . $ref['line']] = $ref;
    }
    return sortedItems(array_values($unique), ['path', 'line']);
}

function lineForOffset(string $contents, int $offset): int
{
    return substr_count(substr($contents, 0, $offset), "\n") + 1;
}

function normalizeExpression(string $expression): string
{
    return trim(preg_replace('/\s+/', ' ', $expression) ?? $expression);
}

function sanitizeDiscoveryPart(string $value): string
{
    $value = strtolower(preg_replace('/[^A-Za-z0-9_:-]+/', '-', $value) ?? $value);
    return trim($value, '-');
}

function sortedUnique(array $values): array
{
    $values = array_values(array_unique($values));
    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    return $values;
}

function sortedItems(array $items, array $paths): array
{
    usort($items, static function (array $left, array $right) use ($paths): int {
        foreach ($paths as $path) {
            $leftValue = nestedValue($left, $path);
            $rightValue = nestedValue($right, $path);
            $comparison = strnatcasecmp((string) $leftValue, (string) $rightValue);
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return 0;
    });
    return array_values($items);
}

function nestedValue(array $item, string $path): mixed
{
    $value = $item;
    foreach (explode('.', $path) as $part) {
        if (! is_array($value) || ! array_key_exists($part, $value)) {
            return '';
        }
        $value = $value[$part];
    }
    return $value;
}

function relativePath(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    return ltrim(substr($path, strlen($root)), '/');
}

function readRequiredFile(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read file: ' . $path);
    }
    return $contents;
}

function writeJsonFile(string $path, array $data): void
{
    writeTextFile($path, canonicalJson($data));
}

function canonicalJson(array $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

function writeTextFile(string $path, string $contents): void
{
    $directory = dirname($path);
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create directory: ' . $directory);
    }
    $contents = rtrim($contents) . "\n";
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Unable to write file: ' . $path);
    }
}

function escapeMarkdownTable(string $value): string
{
    return str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $value);
}
