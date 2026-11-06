<?php

/**
 * docs/agents/manifest.json is curated, so agent-docs:build never rewrites it and nothing used to
 * hold its serialization steady. An editor or script that saves it with a different indent rewrites
 * all ~9,000 lines, and git then cannot align anyone else's record edit against it: every open
 * branch carrying a manifest edit conflicts in full, even when no two branches touched the same
 * record. composer agent-docs:check enforces this; the assertion is repeated here so the suite
 * catches the drift too, rather than only whoever remembers to run the check.
 */
class AgentDocsManifestSerializationTest extends WP_UnitTestCase
{
    /**
     * The encoder every generated file in docs/agents/ is written with, in
     * scripts/agent-docs.php::canonicalJson(). PHP's JSON_PRETTY_PRINT is fixed at four spaces.
     *
     * @param array<string, mixed> $data
     * @return string
     */
    private function canonical_json(array $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * @return string
     */
    private function manifest_path(): string
    {
        return DBVC_PLUGIN_PATH . 'docs/agents/manifest.json';
    }

    public function test_the_committed_manifest_is_in_the_canonical_serialization(): void
    {
        $path = $this->manifest_path();
        $this->assertFileExists($path);

        $contents = (string) file_get_contents($path);
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        $this->assertSame(
            $this->canonical_json($decoded),
            $contents,
            'docs/agents/manifest.json is not in the canonical serialization. '
                . 'Run composer agent-docs:normalize.'
        );
    }

    /**
     * The normalizer is only allowed to touch whitespace and escaping. If it could reorder keys or
     * records it would rewrite curated authority, and nobody could safely run it on a conflict.
     */
    public function test_normalizing_preserves_every_record_and_its_key_order(): void
    {
        $contents = (string) file_get_contents($this->manifest_path());
        $before = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $after = json_decode($this->canonical_json($before), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(array_keys($before), array_keys($after));
        $this->assertSame(
            array_column($before['records'], 'id'),
            array_column($after['records'], 'id'),
            'Record order changed.'
        );

        foreach ($before['records'] as $index => $record) {
            $this->assertSame(
                array_keys($record),
                array_keys($after['records'][$index]),
                sprintf('Key order changed in record %s.', (string) $record['id'])
            );
        }
    }

    /**
     * Four spaces is the contract, not an accident of whichever writer last saved the file.
     */
    public function test_the_manifest_uses_a_four_space_indent(): void
    {
        $lines = explode("\n", (string) file_get_contents($this->manifest_path()));

        $indented = null;
        foreach ($lines as $line) {
            if (preg_match('/^( +)\S/', $line, $matches) === 1) {
                $indented = $matches[1];
                break;
            }
        }

        $this->assertNotNull($indented, 'No indented line found in the manifest.');
        $this->assertSame(4, strlen($indented));
    }
}
