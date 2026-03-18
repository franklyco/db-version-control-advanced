jQuery(document).ready(function($) {

    let urlsToProcess = [];
    let totalUrls = 0;
    let processedCount = 0;
    let failedCount = 0;
    let isCrawlingStopped = false;
    let activeCrawlOverrides = {};
    let dbvc_cc_active_crawl_domain = '';
    let dbvc_cc_domain_ai_refresh_requested = false;

    const $form = $('#cc-form');
    const $submitBtn = $('#cc-submit');
    const $stopBtn = $('#cc-stop');
    const $clearLogBtn = $('#cc-clear-log');
    const $downloadLogBtn = $('#cc-download-log');
    const $statusWrapper = $('#cc-status-wrapper');
    const $log = $('#cc-status-log');
    const $progressBar = $('#cc-progress-bar');
    const { ajax_url, nonce, actions } = dbvc_cc_ajax_object;

    function getCollectOverrides() {
        return {
            request_delay: parseInt($('#request_delay').val(), 10) || 500,
            request_timeout: parseInt($('#request_timeout').val(), 10) || 30,
            user_agent: String($('#user_agent').val() || ''),
            exclude_selectors: String($('#exclude_selectors').val() || ''),
            focus_selectors: String($('#focus_selectors').val() || ''),
            capture_mode: String($('#capture_mode').val() || 'deep'),
            capture_include_attribute_context: $('#capture_include_attribute_context').is(':checked') ? 1 : 0,
            capture_include_dom_path: $('#capture_include_dom_path').is(':checked') ? 1 : 0,
            capture_max_elements_per_page: parseInt($('#capture_max_elements_per_page').val(), 10) || 2000,
            capture_max_chars_per_element: parseInt($('#capture_max_chars_per_element').val(), 10) || 1000,
            context_enable_boilerplate_detection: $('#context_enable_boilerplate_detection').is(':checked') ? 1 : 0,
            context_enable_entity_hints: $('#context_enable_entity_hints').is(':checked') ? 1 : 0,
            ai_enable_section_typing: $('#ai_enable_section_typing').is(':checked') ? 1 : 0,
            ai_section_typing_confidence_threshold: parseFloat($('#ai_section_typing_confidence_threshold').val()) || 0.65,
            scrub_policy_enabled: $('#scrub_policy_enabled').is(':checked') ? 1 : 0,
            scrub_profile_mode: String($('#scrub_profile_mode').val() || 'deterministic-default'),
            scrub_attr_action_class: String($('#scrub_attr_action_class').val() || 'tokenize'),
            scrub_attr_action_id: String($('#scrub_attr_action_id').val() || 'hash'),
            scrub_attr_action_data: String($('#scrub_attr_action_data').val() || 'tokenize'),
            scrub_attr_action_style: String($('#scrub_attr_action_style').val() || 'drop'),
            scrub_attr_action_aria: String($('#scrub_attr_action_aria').val() || 'keep'),
            scrub_custom_allowlist: String($('#scrub_custom_allowlist').val() || ''),
            scrub_custom_denylist: String($('#scrub_custom_denylist').val() || ''),
            scrub_ai_suggestion_enabled: $('#scrub_ai_suggestion_enabled').is(':checked') ? 1 : 0,
            scrub_preview_sample_size: parseInt($('#scrub_preview_sample_size').val(), 10) || 20,
        };
    }

    $form.on('submit', function(e) {
        e.preventDefault();
        resetState();
        
        const sitemapUrl = $('#sitemap_url').val();
        if (!sitemapUrl) {
            logMessage('ERROR: Please enter a sitemap URL.', 'error');
            return;
        }

        activeCrawlOverrides = getCollectOverrides();

        $submitBtn.prop('disabled', true).hide();
        $stopBtn.show();
        $statusWrapper.show();
        logMessage('Starting process...');
        logMessage(`Fetching sitemap: ${sitemapUrl}`);

        $.post(ajax_url, {
            action: actions.get_urls,
            nonce: nonce,
            sitemap_url: sitemapUrl,
            crawl_overrides: activeCrawlOverrides,
        })
        .done(function(response) {
            if (response.success) {
                urlsToProcess = response.data.urls;
                totalUrls = urlsToProcess.length;
                dbvc_cc_active_crawl_domain = dbvc_cc_resolve_domain_key(response.data.domain, urlsToProcess, sitemapUrl);
                logMessage(`SUCCESS: Found ${totalUrls} URLs to process.`, 'success');
                if (totalUrls > 0) {
                    processNextUrl();
                } else {
                    logMessage('No URLs found. Process finished.', 'info');
                    finishProcess();
                }
            } else {
                logMessage(`ERROR: ${response.data.message}`, 'error');
                finishProcess();
            }
        })
        .fail(function() {
            logMessage('ERROR: An unknown AJAX error occurred while fetching the sitemap.', 'error');
            finishProcess();
        });
    });

    $stopBtn.on('click', function() {
        isCrawlingStopped = true;
        logMessage('STOPPING: Crawl will halt after the current page finishes processing.', 'info');
        $(this).prop('disabled', true).text('Stopping...');
    });

    $clearLogBtn.on('click', function() {
        $log.empty();
    });

    $downloadLogBtn.on('click', function() {
        const logContent = $log.text();
        const blob = new Blob([logContent], { type: 'text/plain' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `dbvc-content-migration-log-${new Date().toISOString().slice(0, 10)}.txt`;
        link.click();
        URL.revokeObjectURL(link.href);
    });
    
    function processNextUrl() {
        if (isCrawlingStopped || urlsToProcess.length === 0) {
            logMessage(isCrawlingStopped ? 'Process stopped by user.' : 'All pages processed!', 'success');
            finishProcess();
            return;
        }

        const url = urlsToProcess.shift();
        logMessage(`[${processedCount + failedCount + 1}/${totalUrls}] Processing: ${url}`);
        
        $.post(ajax_url, {
            action: actions.process_url,
            nonce: nonce,
            page_url: url,
            crawl_overrides: activeCrawlOverrides,
        }, 'json')
        .done(function(response) {
            if (response.success) {
                processedCount++;
                logMessage(`  -> SUCCESS: ${response.data.message}`, 'success');
            } else {
                failedCount++;
                logMessage(`  -> ERROR: ${response.data.message}`, 'error');
            }
        })
        .fail(function() {
            failedCount++;
            logMessage(`  -> ERROR: Unknown AJAX error for ${url}`, 'error');
        })
        .always(function() {
            updateProgressBar();
            const delay = parseInt(activeCrawlOverrides.request_delay, 10) || 500;
            setTimeout(processNextUrl, delay);
        });
    }

    function updateProgressBar() {
        const processedTotal = processedCount + failedCount;
        const percentage = totalUrls > 0 ? (processedTotal / totalUrls) * 100 : 0;
        $progressBar.css('width', percentage + '%').text(Math.round(percentage) + '%');
    }

    function dbvc_cc_resolve_domain_key(preferredDomain, urls, fallbackUrl) {
        const preferred = String(preferredDomain || '').trim().toLowerCase().replace(/[^a-z0-9.-]/g, '');
        if (preferred) {
            return preferred;
        }

        const candidates = [];
        if (Array.isArray(urls)) {
            candidates.push(...urls);
        }
        if (fallbackUrl) {
            candidates.push(fallbackUrl);
        }

        for (let index = 0; index < candidates.length; index++) {
            const candidate = String(candidates[index] || '').trim();
            if (!candidate) {
                continue;
            }

            try {
                const parsed = new URL(candidate);
                const host = String(parsed.hostname || '').toLowerCase().replace(/^www\./, '').replace(/[^a-z0-9.-]/g, '');
                if (host) {
                    return host;
                }
            } catch (dbvc_cc_parse_error) {
                // Ignore parse failures and continue scanning candidates.
            }
        }

        return '';
    }

    function dbvc_cc_trigger_domain_ai_refresh_if_needed() {
        const dbvc_cc_refresh_action = actions && actions.trigger_domain_ai_refresh ? String(actions.trigger_domain_ai_refresh) : '';
        if (dbvc_cc_domain_ai_refresh_requested || !dbvc_cc_refresh_action || !dbvc_cc_active_crawl_domain) {
            if (!dbvc_cc_active_crawl_domain) {
                logMessage('INFO: Domain AI refresh skipped because no crawl domain was detected.', 'info');
            }
            return;
        }

        dbvc_cc_domain_ai_refresh_requested = true;
        logMessage(`Queueing full-domain AI refresh for ${dbvc_cc_active_crawl_domain}...`, 'info');

        $.post(ajax_url, {
            action: dbvc_cc_refresh_action,
            nonce: nonce,
            domain: dbvc_cc_active_crawl_domain,
        }, 'json')
        .done(function(dbvc_cc_response) {
            if (!dbvc_cc_response || !dbvc_cc_response.success) {
                const dbvc_cc_error_message = dbvc_cc_response && dbvc_cc_response.data && dbvc_cc_response.data.message
                    ? String(dbvc_cc_response.data.message)
                    : 'Unknown error while queueing domain AI refresh.';
                logMessage(`ERROR: ${dbvc_cc_error_message}`, 'error');
                return;
            }

            const dbvc_cc_payload = dbvc_cc_response.data || {};
            const dbvc_cc_batch_id = dbvc_cc_payload.batch_id ? String(dbvc_cc_payload.batch_id) : '';
            const dbvc_cc_total_jobs = Number(dbvc_cc_payload.total_jobs || 0);
            const dbvc_cc_failed_jobs = Number(dbvc_cc_payload.failed_jobs || 0);
            const dbvc_cc_status_line = dbvc_cc_payload.message
                ? String(dbvc_cc_payload.message)
                : `Queued AI refresh for ${dbvc_cc_active_crawl_domain}.`;
            logMessage(`SUCCESS: ${dbvc_cc_status_line}`, 'success');

            if (dbvc_cc_batch_id) {
                logMessage(`INFO: AI refresh batch ${dbvc_cc_batch_id} queued (${dbvc_cc_total_jobs} jobs, ${dbvc_cc_failed_jobs} queue errors).`, 'info');
            }

            if (dbvc_cc_payload.warning_badge && dbvc_cc_payload.warning_message) {
                logMessage(`WARNING: ${String(dbvc_cc_payload.warning_message)}`, 'error');
            }
        })
        .fail(function() {
            logMessage('ERROR: Failed to queue domain AI refresh.', 'error');
        });
    }

    function logMessage(message, type = 'info') {
        const colorMap = { 'info': '#333', 'success': '#008000', 'error': '#DC143C' };
        $log.append(`<div class="log-entry log-${type}" style="color:${colorMap[type] || '#333'};">${message}</div>`);
        $log.scrollTop($log[0].scrollHeight);
    }

    function resetState() {
        urlsToProcess = [];
        totalUrls = 0;
        processedCount = 0;
        failedCount = 0;
        isCrawlingStopped = false;
        activeCrawlOverrides = {};
        dbvc_cc_active_crawl_domain = '';
        dbvc_cc_domain_ai_refresh_requested = false;
        $log.empty();
        $statusWrapper.hide();
        $stopBtn.prop('disabled', false).text('Stop Crawling').hide();
        $submitBtn.prop('disabled', false).show();
        updateProgressBar();
    }

    function finishProcess() {
        $submitBtn.prop('disabled', false).show();
        $stopBtn.hide();
        const finalMessage = `\nProcess finished. Successful: ${processedCount}, Failed: ${failedCount}.`;
        logMessage(finalMessage, 'info');
        dbvc_cc_trigger_domain_ai_refresh_if_needed();
    }
});
