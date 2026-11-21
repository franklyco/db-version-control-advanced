<?php

use Dbvc\AgencyControl\Release\PreparationService;
use Dbvc\Connected\Preparation\Preparer;
use Dbvc\Connected\Preparation\ReceiptRedactor;
use Dbvc\ConnectedProtocol\BodyDiff;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\Protocol;
use Dbvc\ConnectedProtocol\ReceiptValues;

/**
 * Change report CR-1: nested body diff, receipt values behind the target-side
 * opt-in, shared redaction/truncation, the receipt size bound and the paged
 * prepare-request response. Pure where possible; the loopback transport is
 * exercised by ConnectedReleaseTest.
 *
 * @group connected-environments
 */
class ConnectedChangeReceiptTest extends WP_UnitTestCase
{
    public function tear_down(): void
    {
        delete_option(ReceiptRedactor::OPTION);
        remove_all_filters('dbvc_connected_receipt_values');
        parent::tear_down();
    }

    public function test_body_diff_reports_nested_leaves_with_list_indexes_and_stable_order(): void
    {
        $before = [
            'post_title' => 'Services',
            'meta' => ['_bricks_page_content_2' => [
                ['id' => 'a1', 'name' => 'heading', 'settings' => ['text' => 'Slip rentals', 'tag' => 'h2']],
                ['id' => 'b2', 'name' => 'image', 'settings' => ['image' => ['id' => 'dbvc-bmedia:old', 'size' => 'full']]],
                ['id' => 'c3', 'name' => 'text', 'settings' => ['text' => 'gone']],
            ]],
            'tax_input' => ['category' => ['news']],
        ];
        $after = $before;
        $after['post_title'] = 'Services & Pricing';
        $after['meta']['_bricks_page_content_2'][0]['settings']['text'] = 'Slip rentals, moorings';
        $after['meta']['_bricks_page_content_2'][1]['settings']['image']['id'] = 'dbvc-bmedia:new';
        unset($after['meta']['_bricks_page_content_2'][2]);
        $after['meta']['_bricks_page_content_2'] = array_values($after['meta']['_bricks_page_content_2']);
        $after['tax_input']['category'][] = 'marina';
        $after['menu_order'] = 3;

        $diff = BodyDiff::paths($before, $after);
        $this->assertFalse($diff['truncated']);
        $this->assertSame([
            ['menu_order', 'add'],
            ['meta._bricks_page_content_2.0.settings.text', 'replace'],
            ['meta._bricks_page_content_2.1.settings.image.id', 'replace'],
            ['meta._bricks_page_content_2.2', 'remove'],
            ['post_title', 'replace'],
            ['tax_input.category.1', 'add'],
        ], array_map(static function ($row) {
            return [$row['path'], $row['operation']];
        }, $diff['changed_paths']));

        $heading = $diff['changed_paths'][1];
        $this->assertSame(Canonicalizer::hash(Canonicalizer::encode('Slip rentals')), $heading['before_hash']);
        $this->assertSame(Canonicalizer::hash(Canonicalizer::encode('Slip rentals, moorings')), $heading['after_hash']);
        $this->assertNull($diff['changed_paths'][0]['before_hash'], 'An added leaf has no before hash.');
        $this->assertNull($diff['changed_paths'][3]['after_hash'], 'A removed list entry has no after hash.');

        // Deterministic regardless of insertion order on either side.
        $shuffled_after = array_reverse($after, true);
        $this->assertSame($diff, BodyDiff::paths($before, $shuffled_after));
        $this->assertSame(['changed_paths' => [], 'truncated' => false], BodyDiff::paths($before, $before));
    }

    public function test_body_diff_treats_shape_changes_as_one_leaf_and_honours_its_bounds(): void
    {
        // scalar → array, list → map: one replace row each, never a walk into mismatched shapes.
        $diff = BodyDiff::paths(['a' => 'x', 'b' => [1, 2]], ['a' => ['k' => 'v'], 'b' => ['k' => 'v']]);
        $this->assertSame([['a', 'replace'], ['b', 'replace']], array_map(static function ($row) {
            return [$row['path'], $row['operation']];
        }, $diff['changed_paths']));
        $this->assertArrayNotHasKey('collapsed', $diff['changed_paths'][0]);

        // Depth bound: a differing subtree at the limit collapses into one row.
        $deep_before = ['l1' => ['l2' => ['l3' => ['l4' => 'a', 'other' => 'same']]]];
        $deep_after = ['l1' => ['l2' => ['l3' => ['l4' => 'b', 'other' => 'same']]]];
        $diff = BodyDiff::paths($deep_before, $deep_after, ['max_depth' => 2]);
        $this->assertSame('l1.l2', $diff['changed_paths'][0]['path']);
        $this->assertTrue($diff['changed_paths'][0]['collapsed']);
        $this->assertCount(1, $diff['changed_paths']);
        $this->assertSame('l1.l2.l3.l4', BodyDiff::paths($deep_before, $deep_after)['changed_paths'][0]['path']);

        // Row bound: the sorted list is cut and flagged.
        $many_before = [];
        $many_after = [];
        for ($i = 0; $i < 30; $i++) {
            $many_before['k' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)] = $i;
            $many_after['k' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)] = $i + 1;
        }
        $diff = BodyDiff::paths($many_before, $many_after, ['max_paths' => 5]);
        $this->assertTrue($diff['truncated']);
        $this->assertSame(['k00', 'k01', 'k02', 'k03', 'k04'], array_column($diff['changed_paths'], 'path'));

        // value_at distinguishes an absent key from a null value and indexes lists.
        $this->assertSame([true, null], BodyDiff::value_at(['a' => null], 'a'));
        $this->assertSame([false, null], BodyDiff::value_at(['a' => null], 'b'));
        $this->assertSame([true, 'two'], BodyDiff::value_at(['list' => ['one', 'two']], 'list.1'));
        $this->assertSame([false, null], BodyDiff::value_at(['list' => 'scalar'], 'list.1'));
    }

    public function test_receipt_values_render_identically_for_both_sides(): void
    {
        $this->assertSame('[redacted]', ReceiptValues::format('hunter2', 'meta._wp_password'));
        $this->assertSame('[redacted]', ReceiptValues::format('x', 'settings.apiKey'));
        $this->assertSame('[redacted]', ReceiptValues::format('x', 'auth.0'));
        $this->assertSame('(absent)', ReceiptValues::format(null, 'post_title'));
        $this->assertSame('(absent)', ReceiptValues::format('ignored', 'post_title', false));
        $this->assertSame('true', ReceiptValues::format(true, 'flag'));
        $this->assertSame('{"id":"x","size":"full"}', ReceiptValues::format(['id' => 'x', 'size' => 'full'], 'settings.image'));
        $long = str_repeat('é', 250);
        $rendered = ReceiptValues::format($long, 'post_content');
        $this->assertSame(str_repeat('é', 200) . '… (250 chars)', $rendered);
        $this->assertSame('a b c', ReceiptValues::format("a \n\t b   c", 'post_content'));
    }

    public function test_patch_carries_values_only_when_the_target_opted_in_and_within_the_size_bound(): void
    {
        $before = ['post_title' => 'Services', 'meta' => ['_token' => 'abc', 'hero' => ['text' => 'Old']]];
        $after = ['post_title' => 'Services & Pricing', 'meta' => ['_token' => 'xyz', 'hero' => ['text' => 'New', 'size' => 44]]];

        $patch = Preparer::patch($before, $after);
        $this->assertSame('replace_object', $patch['strategy']);
        $this->assertSame(['meta._token', 'meta.hero.size', 'meta.hero.text', 'post_title'], array_column($patch['changed_paths'], 'path'));
        $this->assertArrayNotHasKey('before_value', $patch['changed_paths'][0]);

        $this->assertFalse(ReceiptRedactor::enabled(), 'Off by default.');
        update_option(ReceiptRedactor::OPTION, '1');
        $this->assertTrue(ReceiptRedactor::enabled());
        add_filter('dbvc_connected_receipt_values', '__return_false');
        $this->assertFalse(ReceiptRedactor::enabled(), 'The filter can veto the option.');
        remove_all_filters('dbvc_connected_receipt_values');

        $valued = ReceiptRedactor::attach_values($patch, $before, $after);
        $by_path = [];
        foreach ($valued['changed_paths'] as $row) {
            $by_path[$row['path']] = [$row['before_value'], $row['after_value']];
        }
        $this->assertSame(['[redacted]', '[redacted]'], $by_path['meta._token'], 'Secret-looking keys never leave the target in clear.');
        $this->assertSame(['(absent)', '44'], $by_path['meta.hero.size']);
        $this->assertSame(['Old', 'New'], $by_path['meta.hero.text']);
        $this->assertSame(['Services', 'Services & Pricing'], $by_path['post_title']);

        // A creation has no before side.
        $created = ReceiptRedactor::attach_values(Preparer::patch([], $after), [], $after);
        $this->assertSame('(absent)', $created['changed_paths'][0]['before_value']);

        // finalize: values kept under the bound, dropped (with the reason) over it.
        $receipt = ['items' => [['patch' => $valued]]];
        $kept = ReceiptRedactor::finalize($receipt, true);
        $this->assertSame(['included' => true, 'omitted' => null], $kept['values']);
        $this->assertSame('Old', $kept['items'][0]['patch']['changed_paths'][2]['before_value']);
        $off = ReceiptRedactor::finalize($receipt, false);
        $this->assertSame(['included' => false, 'omitted' => 'disabled'], $off['values']);

        // A rendered value is itself truncated to 200 chars, so inflate the receipt with many rows.
        $rows = [];
        for ($i = 0; $i < 6000; $i++) {
            $rows[] = ['path' => 'p' . $i, 'operation' => 'replace', 'before_hash' => null, 'after_hash' => null, 'before_value' => str_repeat('v', 100), 'after_value' => str_repeat('w', 100)];
        }
        $huge = ['items' => [['patch' => ['strategy' => 'replace_object', 'changed_paths' => $rows]]]];
        $this->assertGreaterThan(Protocol::MAX_RECEIPT_BYTES, strlen((string) json_encode($huge)));
        $capped = ReceiptRedactor::finalize($huge, true);
        $this->assertSame(['included' => false, 'omitted' => 'size'], $capped['values']);
        $this->assertArrayNotHasKey('before_value', $capped['items'][0]['patch']['changed_paths'][0]);
        $this->assertSame('p0', $capped['items'][0]['patch']['changed_paths'][0]['path'], 'Paths and hashes survive; only values are dropped.');
        $this->assertLessThanOrEqual(Protocol::MAX_RECEIPT_BYTES, strlen((string) json_encode($capped)));
    }

    public function test_hub_validates_the_nested_patch_shape(): void
    {
        $hash = str_repeat('a', 64);
        $this->assertTrue(PreparationService::valid_patch(['strategy' => 'replace_object', 'changed_paths' => []]));
        $this->assertTrue(PreparationService::valid_patch(['strategy' => 'replace_object', 'changed_paths' => [['path' => 'a.b.0', 'operation' => 'replace', 'before_hash' => $hash, 'after_hash' => $hash, 'before_value' => 'x', 'after_value' => 'y']]]));
        $this->assertTrue(PreparationService::valid_patch(['strategy' => 'add_object', 'changed_paths' => [['path' => 'a', 'operation' => 'add', 'before_hash' => null, 'after_hash' => $hash]]]));
        $this->assertTrue(PreparationService::valid_patch(['strategy' => 'remove_object', 'changed_paths' => []]));

        $this->assertFalse(PreparationService::valid_patch('replace_object'));
        $this->assertFalse(PreparationService::valid_patch(['strategy' => 'merge', 'changed_paths' => []]));
        $this->assertFalse(PreparationService::valid_patch(['strategy' => 'replace_object', 'changed_paths' => ['a' => ['path' => 'a', 'operation' => 'add']]]), 'Rows must be a list.');
        $this->assertFalse(PreparationService::valid_patch(['strategy' => 'replace_object', 'changed_paths' => [['path' => 'a', 'operation' => 'rename']]]));
        $this->assertFalse(PreparationService::valid_patch(['strategy' => 'replace_object', 'changed_paths' => [['path' => 'a', 'operation' => 'add', 'after_hash' => 'nothex']]]));
        $this->assertFalse(PreparationService::valid_patch(['strategy' => 'replace_object', 'changed_paths' => [['path' => 'a', 'operation' => 'add', 'after_value' => ['not' => 'string']]]]));

        $release = ['release_uid' => 'rel', 'digest' => $hash, 'item_count' => 1];
        $target = ['environment_id' => 'env', 'current_epoch' => 'ep'];
        $receipt = [
            'release_uid' => 'rel', 'release_digest' => $hash, 'target' => ['environment_id' => 'env', 'epoch' => 'ep'], 'outcome' => 'ready',
            'prepared_at' => '2026-11-08T10:00:00Z', 'expires_at' => '2026-11-08T11:00:00Z', 'counts' => ['ready' => 1, 'noop' => 0, 'blocked' => 0],
            'items' => [['outcome' => 'ready', 'domain' => 'wp.page', 'instance_uid' => 'u', 'profile' => 'p', 'after_hash' => $hash, 'patch' => ['strategy' => 'replace_object', 'changed_paths' => [['path' => 'x', 'operation' => 'replace']]]]],
        ];
        $this->assertSame([], PreparationService::validate_receipt($receipt, $release, $target));
        $receipt['items'][0]['patch']['changed_paths'][0]['operation'] = 'bogus';
        $this->assertSame(['items.0.patch'], PreparationService::validate_receipt($receipt, $release, $target));
        $receipt['items'][0]['patch'] = null;
        $this->assertSame([], PreparationService::validate_receipt($receipt, $release, $target), 'A null patch (noop/blocked item) is fine.');
    }

    public function test_prepare_requests_are_paged_by_item_count_as_whole_requests(): void
    {
        $request = static function ($id, $items) {
            return ['operation_id' => $id, 'release' => ['items' => array_fill(0, $items, ['domain' => 'd'])]];
        };
        $page = PreparationService::page_requests([$request('a', 10), $request('b', 10), $request('c', 10)], 25);
        $this->assertSame(['a', 'b'], array_column($page['requests'], 'operation_id'), 'c would push the page over 25 items.');
        $this->assertTrue($page['has_more']);

        $page = PreparationService::page_requests([$request('big', 200), $request('b', 1)], 25);
        $this->assertSame(['big'], array_column($page['requests'], 'operation_id'), 'A request larger than the page is returned alone, never split.');
        $this->assertTrue($page['has_more']);

        $page = PreparationService::page_requests([$request('a', 5), $request('b', 5)], 25);
        $this->assertSame(['a', 'b'], array_column($page['requests'], 'operation_id'));
        $this->assertFalse($page['has_more']);

        $this->assertSame(['requests' => [], 'has_more' => false], PreparationService::page_requests([], 25));
        $this->assertSame(Protocol::MAX_RECEIPT_BYTES, Protocol::describe()['limits']['max_receipt_bytes']);
    }
}
