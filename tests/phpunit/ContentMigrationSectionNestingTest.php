<?php

/**
 * Section nesting must be expressible.
 *
 * The segmenter opens a section at every heading regardless of level. On a profile layout that put
 * a person's name in one section owning nothing and their role in a sibling section owning their
 * whole biography, so nothing connected the two.
 *
 * The fix is additive on purpose. Section boundaries are consumed by the mapping index, the
 * handoff bridge and the import executor, and a section count is asserted in fixture-locked
 * contracts, so moving a boundary would ripple. parent_section_id and heading_path record the
 * relationship without changing one.
 */
class ContentMigrationSectionNestingTest extends WP_UnitTestCase
{
    /**
     * @param string $tag
     * @param string $text
     * @param int    $sequence
     * @return array<string, mixed>
     */
    private function element(string $tag, string $text, int $sequence): array
    {
        return [
            'element_id' => 'el_' . $sequence,
            'tag' => $tag,
            'text' => $text,
            'sequence_index' => $sequence,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile_page_elements(): array
    {
        return ['elements' => [
            $this->element('h1', 'Meet the Team', 1),
            $this->element('h2', 'Nicole Langford', 2),
            $this->element('h3', 'Registered Dental Hygienist', 3),
            $this->element('p', 'Nicole earned her dental hygiene degree in 2013.', 4),
            $this->element('h2', 'Nancy Gezymalla', 5),
            $this->element('h3', 'Certified Dental Assistant', 6),
            $this->element('p', 'Nancy became interested in dentistry many years ago.', 7),
        ]];
    }

    public function test_a_deeper_heading_records_the_heading_above_it_as_its_parent(): void
    {
        $artifact = DBVC_CC_Section_Segmenter_Service::build_artifact(
            $this->profile_page_elements(),
            'https://nesting.test/dental-team'
        );

        $by_label = [];
        foreach ($artifact['sections'] as $section) {
            $by_label[$section['section_label_candidate']] = $section;
        }

        $this->assertNull($by_label['Meet the Team']['parent_section_id'], 'an h1 has no parent');
        $this->assertSame(
            $by_label['Meet the Team']['section_id'],
            $by_label['Nicole Langford']['parent_section_id'],
            'an h2 nests under the preceding h1'
        );
        $this->assertSame(
            $by_label['Nicole Langford']['section_id'],
            $by_label['Registered Dental Hygienist']['parent_section_id'],
            "the role in an h3 must point at the person's name in the h2 above it"
        );
        $this->assertSame(
            $by_label['Nancy Gezymalla']['section_id'],
            $by_label['Certified Dental Assistant']['parent_section_id'],
            'the stack pops correctly, so the second role does not attach to the first person'
        );
    }

    public function test_heading_path_carries_the_ancestor_labels(): void
    {
        $artifact = DBVC_CC_Section_Segmenter_Service::build_artifact(
            $this->profile_page_elements(),
            'https://nesting.test/dental-team'
        );

        foreach ($artifact['sections'] as $section) {
            if ($section['section_label_candidate'] === 'Registered Dental Hygienist') {
                $this->assertSame(['Meet the Team', 'Nicole Langford'], $section['heading_path']);

                return;
            }
        }

        $this->fail('the role section was not produced');
    }

    public function test_the_biography_is_reachable_from_the_person(): void
    {
        $artifact = DBVC_CC_Section_Segmenter_Service::build_artifact(
            $this->profile_page_elements(),
            'https://nesting.test/dental-team'
        );

        $sections = [];
        foreach ($artifact['sections'] as $section) {
            $sections[$section['section_id']] = $section;
        }

        $person = null;
        $role = null;
        foreach ($sections as $section) {
            if ($section['section_label_candidate'] === 'Nicole Langford') {
                $person = $section;
            }
            if ($section['section_label_candidate'] === 'Registered Dental Hygienist') {
                $role = $section;
            }
        }

        $this->assertNotNull($person);
        $this->assertNotNull($role);

        // This is the defect restated as an assertion: the person's own section owns no body copy,
        // and the biography lives in the child. Walking parent_section_id is what connects them.
        $this->assertSame(0, (int) $person['signals']['text_element_count']);
        $this->assertSame(1, (int) $role['signals']['text_element_count']);
        $this->assertSame($person['section_id'], $role['parent_section_id']);
    }

    public function test_section_boundaries_are_unchanged(): void
    {
        $artifact = DBVC_CC_Section_Segmenter_Service::build_artifact(
            $this->profile_page_elements(),
            'https://nesting.test/dental-team'
        );

        // One section per heading, exactly as before: the fix adds fields, it does not merge or
        // split anything. Downstream consumers and fixture-locked counts depend on this.
        // Five headings in the fixture, so five sections and no intro.
        $this->assertSame(5, (int) $artifact['section_count']);
        $this->assertCount(5, $artifact['sections']);

        $element_ids = [];
        foreach ($artifact['sections'] as $section) {
            foreach ($section['element_ids'] as $element_id) {
                $element_ids[] = $element_id;
            }
        }
        $this->assertSame(7, count($element_ids), 'every element is still assigned exactly once');
        $this->assertSame($element_ids, array_values(array_unique($element_ids)));
    }

    public function test_a_leading_body_element_still_opens_an_unparented_intro_section(): void
    {
        $artifact = DBVC_CC_Section_Segmenter_Service::build_artifact(
            ['elements' => [
                $this->element('p', 'Copy before any heading on the page.', 1),
                $this->element('h2', 'A heading that follows it', 2),
            ]],
            'https://nesting.test/'
        );

        $this->assertSame('Intro', $artifact['sections'][0]['section_label_candidate']);
        $this->assertNull($artifact['sections'][0]['parent_section_id']);
        $this->assertSame([], $artifact['sections'][0]['heading_path']);
        // An intro has no heading level, so a following h2 must not try to nest under it.
        $this->assertNull($artifact['sections'][1]['parent_section_id']);
    }
}
