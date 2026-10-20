<?php

/**
 * The include-trash setting cannot deliver a trashed form: WS Form's db_read()
 * refuses one outright. These cover the reporting that replaced the silent drop.
 */
class WSFormExportSkipReportTest extends WP_UnitTestCase
{
    public function test_trashed_form_is_classified_as_trash_unreadable(): void
    {
        $row = DBVC_Third_Party_Portability::describe_wsform_export_skip(4, 'trash', 'Patient Request Appointment (Copy)');

        $this->assertSame(4, $row['form_id']);
        $this->assertSame('trash', $row['status']);
        $this->assertSame('trash_unreadable', $row['reason']);
        $this->assertStringContainsString('trashed form', $row['message']);
        $this->assertStringContainsString('Restore it first', $row['message']);
    }

    public function test_non_trashed_unreadable_form_keeps_the_generic_reason(): void
    {
        $row = DBVC_Third_Party_Portability::describe_wsform_export_skip(12, 'draft', 'OpenAI Calculator');

        $this->assertSame('unreadable', $row['reason']);
        $this->assertSame('draft', $row['status']);
        $this->assertStringNotContainsString('trashed', $row['message']);
    }

    public function test_explicit_reasons_are_preserved_and_described(): void
    {
        foreach (['unsafe_path', 'encode_failed', 'write_failed'] as $reason) {
            $row = DBVC_Third_Party_Portability::describe_wsform_export_skip(5, 'publish', 'Request Appointment', $reason);
            $this->assertSame($reason, $row['reason'], $reason . ' must survive classification');
            $this->assertNotSame('', $row['message'], $reason . ' must carry an operator-facing message');
        }
    }

    public function test_only_an_unreadable_trashed_form_is_reclassified(): void
    {
        // A trashed form that failed to WRITE is a write failure, not the
        // WS Form limitation, and must not be mislabelled as such.
        $row = DBVC_Third_Party_Portability::describe_wsform_export_skip(6, 'trash', 'Quiz', 'write_failed');

        $this->assertSame('write_failed', $row['reason']);
    }

    public function test_label_and_status_are_sanitized(): void
    {
        $row = DBVC_Third_Party_Portability::describe_wsform_export_skip(7, 'TRASH', "<b>Quiz</b>\n(Copy)");

        $this->assertSame('trash', $row['status'], 'status is normalised through sanitize_key');
        $this->assertStringNotContainsString('<b>', $row['label']);
    }

    public function test_unknown_reason_falls_back_to_a_message(): void
    {
        $row = DBVC_Third_Party_Portability::describe_wsform_export_skip(8, 'publish', 'Quiz', 'something_new');

        $this->assertSame('something_new', $row['reason']);
        $this->assertNotSame('', $row['message']);
    }

    public function test_report_accessor_has_a_stable_shape_before_any_export(): void
    {
        $report = DBVC_Third_Party_Portability::get_last_wsform_export_report();

        foreach (['forms_considered', 'forms_written', 'forms_skipped', 'settings_written'] as $key) {
            $this->assertArrayHasKey($key, $report);
        }
        $this->assertIsArray($report['forms_skipped']);
        $this->assertIsBool($report['settings_written']);
    }

    public function test_export_resets_the_report_even_when_wsform_is_absent(): void
    {
        // WS Form is not installed in the test environment, so this exercises the
        // reset path and proves a stale report cannot leak into a later run.
        update_option(DBVC_Third_Party_Portability::OPTION_WSFORM_FORMS, '1');
        try {
            DBVC_Third_Party_Portability::export_selected_entities();
        } finally {
            delete_option(DBVC_Third_Party_Portability::OPTION_WSFORM_FORMS);
        }

        $report = DBVC_Third_Party_Portability::get_last_wsform_export_report();
        $this->assertSame([], $report['forms_skipped']);
        $this->assertSame(0, $report['forms_written']);
        $this->assertFalse($report['settings_written']);
    }
}
