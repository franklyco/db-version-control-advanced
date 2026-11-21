<?php

use Dbvc\AgencyControl\Reports\ChangeReportService;
use Dbvc\Connected\Inspection\DesignReviewResolver;
use Dbvc\Connected\Inspection\FileComparison;
use Dbvc\ConnectedProtocol\DesignReviewAnnotation;

/**
 * CR-6: a `vf-design-review/1` export annotates the page and template rows of
 * a change report with pin counts and approval state. The theme is not
 * involved; the export is a fixture shaped like the theme writes it.
 *
 * @group connected-environments
 */
class ConnectedDesignReviewAnnotationTest extends WP_UnitTestCase
{
    /**
     * @var array<int, string>
     */
    private $temp_files = [];

    public function tear_down(): void
    {
        foreach ($this->temp_files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tear_down();
    }

    public function test_read_refuses_other_documents_and_counts_pins_and_approvals(): void
    {
        $this->assertNull(DesignReviewAnnotation::read(['format' => 'dbvc-connected-objects/1']));
        $this->assertNull(DesignReviewAnnotation::read(['format' => 'vf-design-review/2', 'round' => ['inventory' => []]]));
        $this->assertNull(DesignReviewAnnotation::read(['format' => 'vf-design-review/1', 'round' => ['label' => 'x']]), 'A round without its inventory is not readable.');

        $review = DesignReviewAnnotation::read($this->export());
        $this->assertIsArray($review);
        $this->assertSame('https://staging.example/', $review['site']);
        $this->assertSame(['id' => 44, 'label' => 'Design round 2', 'state' => 'approved', 'signed_off' => true], $review['round']);
        $this->assertSame(['post:19', 'post:23', 'post:31', 'design:ab12'], array_keys($review['entries']));

        $home = $review['entries']['post:19'];
        $this->assertSame('page', $home['kind']);
        $this->assertTrue($home['approved']);
        $this->assertSame('2026-11-18T10:00:00+00:00', $home['approved_at']);
        $this->assertSame(['total' => 3, 'open' => 1, 'resolved' => 1, 'orphaned' => 1], $home['pins'], 'A status outside the vocabulary counts as open.');
        $this->assertFalse($review['entries']['post:23']['approved'], 'An approval row without a time is not an approval.');
        $this->assertSame(['total' => 1, 'open' => 1, 'resolved' => 0, 'orphaned' => 0], $review['entries']['post:31']['pins'], 'A template-scoped pin also counts for the page it was placed on.');

        $this->assertSame([7], array_keys($review['templates']), 'Template-scoped pins are grouped by their template whichever page they sat on.');
        $this->assertSame('Header', $review['templates'][7]['title']);
        $this->assertSame(['total' => 2, 'open' => 2, 'resolved' => 0, 'orphaned' => 0], $review['templates'][7]['pins']);
    }

    public function test_annotate_matches_by_uid_then_unique_title_and_leaves_design_system_rows_alone(): void
    {
        $review = DesignReviewAnnotation::read($this->export());
        $items = [
            ['domain' => 'wp.post:page', 'instance_uid' => 'uid-home', 'display_name' => 'Welcome'],          // uid map wins over a title the review does not know
            ['domain' => 'wp.post:page', 'instance_uid' => 'uid-about', 'display_name' => 'About'],           // title
            ['domain' => 'wp.post:page', 'instance_uid' => 'uid-dup', 'display_name' => 'Services'],          // title carried by two entries → no match
            ['domain' => 'wp.post:bricks_template', 'instance_uid' => 'uid-header', 'display_name' => 'Header'], // template known only through its pins
            ['domain' => 'wp.post:bricks_template', 'instance_uid' => 'uid-footer', 'display_name' => 'Footer'], // template in the inventory
            ['domain' => 'wp.post:page', 'instance_uid' => 'uid-about-2', 'display_name' => 'Header'],         // a page named like a template is not the template
            ['domain' => 'wp.service', 'instance_uid' => 'uid-crowns', 'display_name' => 'Crowns & Bridges'],  // the service alias domain is a post domain; the export's title carries &#038;
            ['domain' => 'bricks.global_class', 'instance_uid' => 'cls', 'display_name' => 'Home'],              // not a post domain
        ];
        $export = $this->export();
        $export['round']['inventory'][] = ['key' => 'post:40', 'kind' => 'page', 'path' => '/services/', 'title' => 'Services'];
        $export['round']['inventory'][] = ['key' => 'post:41', 'kind' => 'page', 'path' => '/services-2/', 'title' => 'Services'];
        $export['round']['inventory'][] = ['key' => 'post:50', 'kind' => 'template', 'path' => '', 'title' => 'Footer'];
        $export['round']['inventory'][] = ['key' => 'post:60', 'kind' => 'post', 'path' => '/services/crowns-and-bridges/', 'title' => 'Crowns &#038;  Bridges'];
        $export['round']['approvals']['post:50'] = ['reviewer_key' => 'r1', 'at' => '2026-11-18T10:05:00+00:00'];
        $review = DesignReviewAnnotation::read($export);

        $result = DesignReviewAnnotation::annotate($items, $review, ['post:19' => 'uid-home', 'template:7' => 'uid-header']);
        $notes = array_column($result['items'], 'design_review', 'instance_uid');

        $this->assertSame('post:19', $notes['uid-home']['entry']);
        $this->assertSame(DesignReviewAnnotation::MATCHED_BY_UID, $notes['uid-home']['matched_by']);
        $this->assertSame('Home', $notes['uid-home']['title']);
        $this->assertTrue($notes['uid-home']['approved']);
        $this->assertSame('post:23', $notes['uid-about']['entry']);
        $this->assertSame(DesignReviewAnnotation::MATCHED_BY_TITLE, $notes['uid-about']['matched_by']);
        $this->assertNull($notes['uid-dup'], 'A title two entries share matches neither.');
        $this->assertSame('template:7', $notes['uid-header']['entry']);
        $this->assertSame(DesignReviewAnnotation::MATCHED_BY_UID, $notes['uid-header']['matched_by']);
        $this->assertArrayNotHasKey('approved', $notes['uid-header'], 'A template known only through pins has no approval to report.');
        $this->assertSame('post:50', $notes['uid-footer']['entry']);
        $this->assertTrue($notes['uid-footer']['approved']);
        $this->assertNull($notes['uid-about-2'], 'Page rows match page entries only.');
        $this->assertNull($notes['cls']);
        $this->assertSame('post:60', $notes['uid-crowns']['entry']);
        $this->assertSame('Crowns & Bridges', $notes['uid-crowns']['title'], 'Entities are decoded and whitespace folded before titles compare.');

        $summary = $result['summary'];
        $this->assertSame(8, $summary['entries']);
        $this->assertSame(5, $summary['matched']);
        $this->assertSame(['uid' => 2, 'title' => 3], $summary['matched_by']);
        $this->assertSame(['Contact', 'Services', 'Services'], $summary['unmatched'], 'Designs are not pages and are not listed as unmatched.');

        $this->assertSame('3 pins (1 open) · approved', DesignReviewAnnotation::describe($notes['uid-home']));
        $this->assertSame('1 pin (1 open) · not approved', DesignReviewAnnotation::describe($notes['uid-about']));
        $this->assertSame('2 pins (2 open)', DesignReviewAnnotation::describe($notes['uid-header']));
        $this->assertSame('', DesignReviewAnnotation::describe(null));

        $lines = DesignReviewAnnotation::markdown_lines($summary);
        $this->assertSame('## Design Review', $lines[1]);
        $this->assertSame('Design round 2 (approved, signed off) · 8 pages reviewed · 5 matched to a report row.', $lines[2]);
        $this->assertSame('Export from https://staging.example/ taken 2026-11-19T09:00:00+00:00.', $lines[3]);
        $this->assertSame('Reviewed but not in this report: Contact, Services, Services.', $lines[4]);

        // Both markdown renderers carry the note on the row and the section at the end.
        $hub = ChangeReportService::render_markdown([
            'source' => 'stg', 'target' => 'local',
            'summary' => ['text' => '1 change on staging.', 'since' => ''],
            'items' => [['domain' => 'wp.post:page', 'instance_uid' => 'uid-home', 'display_name' => 'Home', 'label' => 'updated', 'path_count' => 2, 'changed_at' => 'now', 'paths' => [], 'design_review' => $notes['uid-home']]],
            'handled' => [],
            'design_review' => $summary,
        ]);
        $this->assertStringContainsString('- **Home** (wp.post:page) · 2 paths · now · Design Review: 3 pins (1 open) · approved', $hub);
        $this->assertStringContainsString("## Design Review\nDesign round 2 (approved, signed off)", $hub);

        $file_mode = FileComparison::render_markdown([
            'file' => ['site' => 'https://staging.example/', 'exported_at' => ''], 'here' => 'http://local.test',
            'summary' => '1 object to look at.',
            'items' => [['domain' => 'wp.post:page', 'instance_uid' => 'uid-home', 'display_name' => 'Home', 'label' => FileComparison::LABEL_DIFFERS, 'matched_by' => FileComparison::MATCHED_BY_UID, 'here_complete' => true, 'path_count' => 1, 'paths' => [], 'truncated' => false, 'design_review' => $notes['uid-home']]],
            'notes' => [],
            'design_review' => $summary,
        ]);
        $this->assertStringContainsString('- **Home** (wp.post:page) · 1 path · Design Review: 3 pins (1 open) · approved', $file_mode);
        $this->assertStringContainsString('## Design Review', $file_mode);
    }

    public function test_the_connector_resolver_maps_ids_only_when_the_title_still_matches_else_by_path(): void
    {
        $home = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Home', 'post_name' => 'home']);
        update_post_meta($home, 'vf_object_uid', 'uid-home');
        $about = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'About', 'post_name' => 'about']);
        update_post_meta($about, 'vf_object_uid', 'uid-about');
        $drifted = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Something else', 'post_name' => 'something-else']);
        update_post_meta($drifted, 'vf_object_uid', 'uid-drifted');
        register_post_type('bricks_template', ['public' => true]);
        $header = self::factory()->post->create(['post_type' => 'bricks_template', 'post_title' => 'Header']);
        update_post_meta($header, 'vf_object_uid', 'uid-header');
        $this->set_permalink_structure('/%postname%/');

        $export = $this->export();
        $export['round']['inventory'] = [
            ['key' => 'post:' . $home, 'kind' => 'page', 'path' => '/', 'title' => 'Home'],                 // id and title agree
            ['key' => 'post:' . $drifted, 'kind' => 'page', 'path' => '/about/', 'title' => 'About'],      // id drifted → path
            ['key' => 'post:999999', 'kind' => 'page', 'path' => '/nowhere/', 'title' => 'Contact'],       // nothing here
            ['key' => 'home', 'kind' => 'home', 'path' => '/', 'title' => 'Front'],                        // no static front page set
            ['key' => 'home:' . $home, 'kind' => 'home', 'path' => '/', 'title' => 'Home'],              // the theme's real front-page key: id + title agree
            ['key' => 'home:' . $drifted, 'kind' => 'home', 'path' => '/', 'title' => 'Front'],          // id drifted, no static front page → unresolved
        ];
        $export['round']['pins'] = [['number' => 1, 'entry' => 'post:' . $home, 'scope' => 'template', 'template' => $header, 'template_title' => 'Header', 'status' => 'open']];
        $review = DesignReviewAnnotation::read($export);

        $map = (new DesignReviewResolver())->uid_map($review);
        $this->assertSame(['post:' . $home => 'uid-home', 'post:' . $drifted => 'uid-about', 'home:' . $home => 'uid-home', 'template:' . $header => 'uid-header'], $map);

        update_option('page_on_front', $home);
        $this->assertSame('uid-home', (new DesignReviewResolver())->uid_map($review)['home']);
        $this->assertSame('uid-home', (new DesignReviewResolver())->uid_map($review)['home:' . $drifted], 'A drifted home id falls back to the static front page.');

        // Through the CLI: the review file is validated and the result carries the annotation.
        $missing = DBVC_Connected_CLI_Inspector::read_design_review('/nonexistent/design-review.json');
        $this->assertSame('dbvc_connected_design_review_missing', $missing->get_error_code());
        $wrong = $this->temp_file('wrong.json', '{"format":"dbvc-connected-objects/1","objects":[]}');
        $this->assertSame('dbvc_connected_design_review_format', DBVC_Connected_CLI_Inspector::read_design_review($wrong)->get_error_code());
        $good = $this->temp_file('review.json', (string) wp_json_encode($export));
        $this->assertSame('Design round 2', DBVC_Connected_CLI_Inspector::read_design_review($good)['round']['label']);

        unregister_post_type('bricks_template');
        delete_option('page_on_front');
    }

    /**
     * An export shaped as the theme's export.php writes it, trimmed to what the annotation reads.
     *
     * @return array<string, mixed>
     */
    private function export()
    {
        return [
            'format' => 'vf-design-review/1',
            'generated' => '2026-11-19T09:00:00+00:00',
            'site' => 'https://staging.example/',
            'round' => [
                'id' => 44,
                'label' => 'Design round 2',
                'state' => 'approved',
                'inventory' => [
                    ['key' => 'post:19', 'kind' => 'page', 'path' => '/', 'title' => 'Home', 'template_hint' => ''],
                    ['key' => 'post:23', 'kind' => 'page', 'path' => '/about/', 'title' => 'About', 'template_hint' => ''],
                    ['key' => 'post:31', 'kind' => 'page', 'path' => '/contact/', 'title' => 'Contact', 'template_hint' => ''],
                    ['key' => 'design:ab12', 'kind' => 'design', 'path' => '', 'title' => 'Mockup v3'],
                ],
                'reviewers' => [['key' => 'r1', 'name' => 'Reviewer', 'role' => 'approver']],
                'approvals' => [
                    'post:19' => ['reviewer_key' => 'r1', 'at' => '2026-11-18T10:00:00+00:00', 'approved_hash' => 'abc'],
                    'post:23' => ['reviewer_key' => 'r1', 'at' => ''],
                    '_round' => ['reviewer_key' => 'r1', 'at' => '2026-11-18T10:10:00+00:00', 'mode' => 'client', 'reason' => ''],
                ],
                'signed_off' => true,
                'counts' => ['open' => 3, 'resolved' => 1, 'orphaned' => 1],
                'pins' => [
                    ['number' => 1, 'entry' => 'post:19', 'scope' => 'element', 'template' => 0, 'template_title' => '', 'status' => 'open', 'thread' => []],
                    ['number' => 2, 'entry' => 'post:19', 'scope' => 'page', 'template' => 0, 'template_title' => '', 'status' => 'resolved', 'thread' => []],
                    ['number' => 3, 'entry' => 'post:19', 'scope' => 'element', 'template' => 0, 'template_title' => '', 'status' => 'orphaned', 'thread' => []],
                    ['number' => 4, 'entry' => 'post:23', 'scope' => 'template', 'template' => 7, 'template_title' => 'Header', 'status' => 'open', 'thread' => []],
                    ['number' => 5, 'entry' => 'post:31', 'scope' => 'template', 'template' => 7, 'template_title' => 'Header', 'status' => 'weird', 'thread' => []],
                ],
                'include_contacts' => false,
                'include_internal' => false,
            ],
        ];
    }

    /**
     * @param string $name
     * @param string $contents
     * @return string
     */
    private function temp_file($name, $contents)
    {
        $path = get_temp_dir() . 'dbvc-cr6-' . wp_generate_password(8, false) . '-' . $name;
        file_put_contents($path, $contents);
        $this->temp_files[] = $path;

        return $path;
    }
}
