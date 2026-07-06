/**
 * PixGrow JS Controller
 * Handles UI interactions, queue management, and client-side image compression.
 */
(function($) {
    'use strict';

    // Standalone showNotice toast notification handler
    function showNotice(message, type = 'info') {
        let container = $('.pixgrow-toast-container');
        if (container.length === 0) {
            container = $('<div class="pixgrow-toast-container"></div>').appendTo('body');
        }

        let icon = 'info';
        if (type === 'success') icon = 'saved';
        else if (type === 'error') icon = 'warning';
        else if (type === 'warning') icon = 'warning';

        const toast = $(`
            <div class="pixgrow-toast pixgrow-toast-${type}">
                <div class="pixgrow-toast-icon">
                    <span class="dashicons dashicons-${icon}"></span>
                </div>
                <div class="pixgrow-toast-content">${message}</div>
            </div>
        `);

        container.append(toast);
        // Force reflow
        toast[0].offsetHeight;
        toast.addClass('show');

        setTimeout(function() {
            toast.removeClass('show').addClass('hide');
            setTimeout(function() {
                toast.remove();
            }, 350);
        }, 4000);
    }

    // Global state
    let isProcessing = false;
    let isDirty = false;
    let queue = [];
    let processedCount = 0;
    let processedItemIds = [];
    const batchLimit = 20; // Freemium limit
    let isLimitOver = false;

    // Queue Search & Filter state
    let rawQueueItems = [];
    let filteredQueueItems = [];

    // History Pagination & Search state
    let rawHistoryItems = [];
    let filteredHistoryItems = [];
    let currentHistoryPage = 1;
    let historyItemsPerPage = 20;

    // Queue Pagination state
    let currentQueuePage = 1;
    const queueItemsPerPage = 20;

    // Logs Filtering & Pagination state
    let rawLogItems = [];
    let filteredLogItems = [];
    let currentLogsPage = 1;
    const logsItemsPerPage = 20;

    $(document).ready(function() {
        // Hide stop button on load
        $('#btn-stop-bulk').hide();
        // Removed initTabs call to support clean server-side URL tab routing
        loadLibraryStats();

        const activeTab = new URLSearchParams(window.location.search).get('tab');
        if (activeTab === 'diagnostics') {
            loadDiagnostics();
            loadDebugLogs();
        }

        // Bind diagnostics actions
        $('#btn-refresh-diagnostics').on('click', function(e) {
            e.preventDefault();
            loadDiagnostics();
        });

        $('#btn-refresh-debug-logs').on('click', function(e) {
            e.preventDefault();
            loadDebugLogs();
        });

        $('#btn-clear-debug-logs').on('click', function(e) {
            e.preventDefault();
            if (confirm('Are you sure you want to clear system logs?')) {
                clearDebugLogs();
            }
        });

        // Bind events
        $('#setting-quality').on('input', function() {
            $('#quality-val').text($(this).val() + '%');
        });

        $('#setting-resize').on('change', function() {
            if ($(this).is(':checked')) {
                $('#resize-dimensions').slideDown(200);
            } else {
                $('#resize-dimensions').slideUp(200);
            }
        });

        $('#btn-start-bulk').on('click', function(e) {
            e.preventDefault();
            freeBulkAction();
        });
        $('#btn-stop-bulk').on('click', function(e) {
            e.preventDefault();
            stopBulkOptimization();
        });

        // Bind delegated actions for single compression
        $(document).on('click', '.btn-optimize-single', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            const url = $(this).data('url');
            const name = $(this).data('name');
            freeSingleAction(id, url, name);
        });
        $('#btn-run-scan').on('click', function(e) {
            e.preventDefault();
            showProLockModal('scanner');
        });

        $(document).on('click', '.pixgrow-limit-close', function(e) {
            e.preventDefault();
            $('.pixgrow-limit-notice').slideUp(200);
        });

        $(document).on('click', '#btn-continue-batch', function(e) {
            e.preventDefault();
            $('.pixgrow-limit-notice').slideUp(200);
            freeBulkAction();
        });

        // Bind Queue search, filter and per-page input events
        let queueSearchTimeout = null;
        $('#queue-search-input').on('input', function() {
            clearTimeout(queueSearchTimeout);
            queueSearchTimeout = setTimeout(function() {
                fetchQueuePage(1);
            }, 300);
        });
        $('#queue-format-filter').on('change', function() {
            fetchQueuePage(1);
        });
        $('#queue-per-page').on('change', function() {
            fetchQueuePage(1);
        });

        // Bind History search, filter and per-page input events
        let historySearchTimeout = null;
        $('#history-search-input').on('input', function() {
            clearTimeout(historySearchTimeout);
            historySearchTimeout = setTimeout(function() {
                fetchHistoryPage(1);
            }, 300);
        });
        $('#history-format-filter').on('change', function() {
            fetchHistoryPage(1);
        });
        $('#history-per-page').on('change', function() {
            fetchHistoryPage(1);
        });

        // Bind Logs search and per-page input events
        let logsSearchTimeout = null;
        $('#logs-search-input').on('input', function() {
            clearTimeout(logsSearchTimeout);
            logsSearchTimeout = setTimeout(function() {
                fetchLogsPage(1);
            }, 300);
        });
        $('#logs-per-page').on('change', function() {
            fetchLogsPage(1);
        });



        // Toggle license activation form / Buy button
        $(document).on('click', '#toggle-license-form', function(e) {
            e.preventDefault();
            $('.already-purchased-section').hide();
            $('.pricing-divider').hide();
            $('.buy-pro-btn').hide();
            $('.activation-form').slideDown(250);
        });

        $(document).on('click', '#hide-license-form', function(e) {
            e.preventDefault();
            $('.activation-form').slideUp(250, function() {
                $('.buy-pro-btn').show();
                $('.pricing-divider').show();
                $('.already-purchased-section').show();
            });
        });

        // Bind delegated lock clicks for premium feature unlock modal
        $(document).on('click', '.pixgrow-pro-lock-click, .btn-replace-upsell, .btn-open-pricing-tab', function(e) {
            if ($(this).hasClass('pixgrow-pro-lock-click') && pixgrow_vars.is_pro_licensed) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            const feature = $(this).data('feature') || 'scanner';
            showProLockModal(feature);
        });

        // Intercept change event on target format selector for Pro values
        $(document).on('change', '#setting-format', function() {
            if (pixgrow_vars.is_pro_licensed) {
                return;
            }
            const val = $(this).val();
            if (val === 'smart') {
                $(this).val(pixgrow_vars.format || 'webp');
                showProLockModal('formats');
            }
        });

        // Bind Premium Unlock Modal CTA action buttons
        $(document).on('click', '.btn-lock-buy', function(e) {
            e.preventDefault();
            $('#pixgrow-pro-lock-modal').removeClass('show').fadeOut(200);
            window.location.href = 'admin.php?page=pixgrow-image-optimizer&tab=pricing';
        });

        $(document).on('click', '.btn-lock-account', function(e) {
            e.preventDefault();
            $('#pixgrow-pro-lock-modal').removeClass('show').fadeOut(200);
            window.location.href = 'admin.php?page=pixgrow-image-optimizer&tab=account';
        });

        $(document).on('click', '.btn-lock-close, #pixgrow-pro-lock-modal', function(e) {
            if (e.target === this || $(e.target).hasClass('btn-lock-close')) {
                e.preventDefault();
                $('#pixgrow-pro-lock-modal').removeClass('show').fadeOut(200);
            }
        });

        // Bind delegated actions for history table
        $(document).on('click', '.btn-restore-single', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            freeUndoAction(id);
        });

        // Smooth scroll to pricing plans on Scenario 1 button click
        $('#btn-view-pricing-tab').on('click', function(e) {
            e.preventDefault();
            const target = $('#pixgrow-pricing-plans');
            if (target.length) {
                $('html, body').animate({
                    scrollTop: target.offset().top - 40
                }, 800);
            }
        });

        // Real license key activation click via native Freemius AJAX endpoint
        $('#btn-activate-license').on('click', function() {
            const key = $('#pixgrow-license-key').val().trim();
            if (!key) {
                showNotice('Please enter a license key.', 'warning');
                return;
            }

            const $btn = $(this);
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Activating...');

            $.ajax({
                url: pixgrow_vars.ajax_url,
                type: 'POST',
                data: {
                    action: pixgrow_vars.freemius_action,
                    security: pixgrow_vars.freemius_security,
                    module_id: pixgrow_vars.freemius_id,
                    license_key: key
                },
                success: function(response) {
                    let resultObj = response;
                    if (typeof response === 'string') {
                        try {
                            resultObj = JSON.parse(response);
                        } catch (e) {
                            // fallback
                        }
                    }
                    if (resultObj && resultObj.success) {
                        showNotice('License activated successfully! Reloading...', 'success');
                        setTimeout(function() {
                            if (resultObj.next_page) {
                                window.location.href = resultObj.next_page;
                            } else {
                                window.location.reload();
                            }
                        }, 1500);
                    } else {
                        const errorMsg = (resultObj && resultObj.error && resultObj.error.message) ? resultObj.error.message : (resultObj && resultObj.error ? resultObj.error : 'Failed to activate license. Please verify your key.');
                        showNotice(errorMsg, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function() {
                    showNotice('An error occurred during license activation. Please try again.', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        });

        // License key synchronization via AJAX
        $('#btn-sync-license').on('click', function(e) {
            e.preventDefault();
            const $btn = $(this);
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 6px 0 0; vertical-align:middle;"></span> Synchronizing...');

            $.ajax({
                url: pixgrow_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'pixgrow_pro_sync_license',
                    security: pixgrow_vars.sync_security
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('License synced successfully.', 'success');
                        setTimeout(function() {
                            window.location.reload();
                        }, 1200);
                    } else {
                        const errorMsg = response.data && response.data.message ? response.data.message : 'Failed to sync license.';
                        showNotice(errorMsg, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function() {
                    showNotice('An error occurred during license sync. Please try again.', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        });

        // License key deactivation: submit official Freemius post form
        $('#btn-deactivate-license').on('click', function(e) {
            e.preventDefault();
            if (confirm('Are you sure you want to deactivate your license? This will lock premium features.')) {
                $('#pixgrow-deactivate-form').submit();
            }
        });

        // Save Uninstall Data deletion preference
        $('#setting-delete-data').on('change', function() {
            const deleteData = $(this).is(':checked') ? 1 : 0;
            $.ajax({
                url: pixgrow_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'pixgrow_save_uninstall_setting',
                    delete_data: deleteData,
                    security: pixgrow_vars.security
                },
                success: function(response) {
                    if (!response.success) {
                        console.error('Failed to save uninstall setting.');
                    }
                }
            });
        });

        // Bind Server Limit error modal close events
        $(document).on('click', '.pixgrow-modal-close, .btn-close-modal', function() {
            $('#pixgrow-error-modal').fadeOut(200);
        });

        $(document).on('click', '#pixgrow-error-modal', function(e) {
            if ($(e.target).hasClass('pixgrow-modal')) {
                $(this).fadeOut(200);
            }
        });

        // Bind Compare buttons on optimized history rows
        $(document).on('click', '.btn-compare-single', function(e) {
            e.preventDefault();
            showProLockModal('compare');
        });

        // Bind Compare Modal close events
        $(document).on('click', '.compare-modal-close, .btn-close-compare-modal', function() {
            $('#pixgrow-compare-modal').fadeOut(200);
        });

        // Bind Compare Slider input range drag
        $('.comparison-slider-range').on('input', function() {
            const val = $(this).val();
            $('.comparison-original-wrapper').css('width', val + '%');
            $('.comparison-slider-handle').css('left', val + '%');
        });

        // Set comparison image width on window resize
        $(window).on('resize', function() {
            if ($('#pixgrow-compare-modal').is(':visible')) {
                const containerWidth = $('.comparison-slider-container').width();
                $('#compare-img-optimized').css('width', containerWidth + 'px');
                $('#compare-img-original').css('width', containerWidth + 'px');
            }
        });

        // Bind Retry button click for failed queue items
        $(document).on('click', '.btn-retry-single', function(e) {
            e.preventDefault();
            freeSingleAction();
        });

        // Settings Dirty State Tracker
        $(document).on('input change', '#pixgrow-settings-form :input', function() {
            isDirty = true;
            if (!$('#pixgrow-settings-dirty-notice').is(':visible')) {
                $('#pixgrow-settings-dirty-notice').css('display', 'flex').hide().fadeIn(300);
            }
        });

        // Save settings handler
        $(document).on('submit', '#pixgrow-settings-form', function(e) {
            e.preventDefault();
            const btn = $('#btn-save-settings');
            btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Saving Settings...');

            // Serialize settings inputs
            const formData = $(this).serialize() + '&action=pixgrow_save_all_settings&security=' + pixgrow_vars.security;

            $.ajax({
                url: pixgrow_vars.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Settings');
                    if (response.success) {
                        isDirty = false;
                        $('#pixgrow-settings-dirty-notice').fadeOut(300);
                        showNotice('Settings saved successfully.', 'success');
                    } else {
                        showNotice('Failed to save settings: ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Settings');
                    showNotice('Connection error saving settings.', 'error');
                }
            });
        });

        // Reset settings handler
        $(document).on('click', '#btn-reset-settings', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to reset all PixGrow settings to their defaults? This will reload the page.')) {
                return;
            }

            const btn = $(this);
            btn.prop('disabled', true).text('Resetting...');

            $.ajax({
                url: pixgrow_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'pixgrow_reset_settings',
                    security: pixgrow_vars.security
                },
                success: function(response) {
                    if (response.success) {
                        isDirty = false;
                        showNotice('Settings reset successfully. Reloading page...', 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotice('Failed to reset settings: ' + response.data.message, 'error');
                        btn.prop('disabled', false).text('Reset to Defaults');
                    }
                },
                error: function() {
                    showNotice('Connection error resetting settings.', 'error');
                    btn.prop('disabled', false).text('Reset to Defaults');
                }
            });
        });

        // Bind Logs filter and reset controls
        $(document).on('click', '#btn-filter-logs', function(e) {
            e.preventDefault();
            fetchLogsPage(1);
        });

        $(document).on('click', '#btn-reset-logs', function(e) {
            e.preventDefault();
            $('#logs-search-input').val('');
            $('#logs-date-from').val('');
            $('#logs-date-to').val('');
            fetchLogsPage(1);
        });

        // Bind Logs CSV Export click
        $(document).on('click', '#btn-export-logs-csv', function(e) {
            e.preventDefault();

            if (!pixgrow_vars.is_pro_licensed) {
                showProLockModal('csv');
                return;
            }

            // Fetch currently filtered list
            const query = ($('#logs-search-input').val() || '').toLowerCase().trim();
            const fromDateStr = $('#logs-date-from').val() || '';
            const toDateStr = $('#logs-date-to').val() || '';

            const btn = $(this);
            const originalHtml = btn.html();
            btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Exporting...');

            $.ajax({
                url: pixgrow_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'pixgrow_get_history_page',
                    page: 1,
                    per_page: 99999, // Fetch all matching logs for export
                    search: query,
                    date_from: fromDateStr,
                    date_to: toDateStr,
                    security: pixgrow_vars.security
                },
                success: function(response) {
                    btn.prop('disabled', false).html(originalHtml);
                    if (response.success && response.data && response.data.items) {
                        const logsToExport = response.data.items;
                        if (logsToExport.length === 0) {
                            showNotice('No logs found to export matching current filters.', 'warning');
                            return;
                        }

                        let csvContent = "Filename,Date,Original Size,Optimized Size,Savings,Status\r\n";
                        logsToExport.forEach(function(item) {
                            const row = [
                                `"${item.name}"`,
                                `"${item.opt_time || 'N/A'}"`,
                                `"${item.orig_size}"`,
                                `"${item.size}"`,
                                `"-${item.savings}"`,
                                `"Optimized"`
                            ];
                            csvContent += row.join(",") + "\r\n";
                        });

                        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                        const url = URL.createObjectURL(blob);
                        const link = document.createElement("a");
                        link.setAttribute("href", url);
                        link.setAttribute("download", "pixgrow_optimization_logs.csv");
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        URL.revokeObjectURL(url);

                        showNotice('CSV logs exported successfully.', 'success');
                    } else {
                        showNotice('Failed to retrieve logs for export.', 'error');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html(originalHtml);
                    showNotice('Connection error retrieving logs for export.', 'error');
                }
            });
        });

        // Exit page beforeunload confirmation
        $(window).on('beforeunload', function(e) {
            if (isDirty) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
    });

    // Tab navigation is now handled natively via WordPress admin query variables (pure URL routing)

    /**
     * Helper to render skeleton loader waves.
     */
    function renderStatsSkeletons() {
        $('#stats-total').html('<span class="pixgrow-skeleton" style="width:40px; height:24px;"></span>');
        $('#stats-optimized').html('<span class="pixgrow-skeleton" style="width:40px; height:24px;"></span>');
        $('#stats-unoptimized').html('<span class="pixgrow-skeleton" style="width:40px; height:24px;"></span>');
        $('#stats-skipped').html('<span class="pixgrow-skeleton" style="width:40px; height:24px;"></span>');
    }

    function renderQueueSkeletons() {
        const tbody = $('#queue-tbody');
        tbody.empty();
        const limit = parseInt($('#queue-per-page').val()) || 20;
        for (let i = 0; i < Math.min(5, limit); i++) {
            tbody.append(`
                <tr class="skeleton-row">
                    <td class="column-thumbnail"><div class="pixgrow-skeleton pixgrow-skeleton-circle"></div></td>
                    <td>
                        <div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 50%;"></div>
                        <div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 30%;"></div>
                    </td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 40px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 40px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 60px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 80px; height: 30px;"></div></td>
                </tr>
            `);
        }
    }

    function renderHistorySkeletons() {
        const tbody = $('#history-tbody');
        tbody.empty();
        const limit = parseInt($('#history-per-page').val()) || 20;
        
        tbody.append(`
            <tr class="pixgrow-history-loading-row">
                <td colspan="7" class="text-center" style="padding: 24px; color: #94a3b8; font-weight: 500;">
                    <span class="spinner is-active" style="float: none; margin: 0 8px 0 0; vertical-align: middle;"></span>
                    Loading optimization history...
                </td>
            </tr>
        `);
        
        for (let i = 0; i < Math.min(5, limit); i++) {
            tbody.append(`
                <tr class="skeleton-row">
                    <td class="column-thumbnail"><div class="pixgrow-skeleton pixgrow-skeleton-circle"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 60px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 40px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 40px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 40px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 40px;"></div></td>
                    <td>
                        <div style="display:flex; justify-content:flex-end; gap:6px;">
                            <div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 60px; height: 26px;"></div>
                            <div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 80px; height: 26px;"></div>
                        </div>
                    </td>
                </tr>
            `);
        }
    }

    function renderLogsSkeletons() {
        const tbody = $('#logs-tbody');
        tbody.empty();
        for (let i = 0; i < 5; i++) {
            tbody.append(`
                <tr class="skeleton-row">
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 120px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 200px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 200px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 50px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 50px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 40px;"></div></td>
                    <td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 80px; height: 24px;"></div></td>
                </tr>
            `);
        }
    }

    /**
     * Updates telemetry values inside the Diagnostics tab UI.
     */
    function updateTelemetryUI(telemetry, ajaxDurationMs) {
        if (!telemetry) return;
        $('#diag-buildtime-val').text(telemetry.time_ms + ' ms');
        $('#diag-ajaxresponse-val').text(ajaxDurationMs + ' ms');
        $('#diag-peakmem-val').text(telemetry.peak_mem_mb + ' MB');
        $('#diag-querycount-val').text(telemetry.queries);
    }

    /**
     * Loads image statistics from PHP backend.
     */
    function loadLibraryStats() {
        renderStatsSkeletons();
        
        $.ajax({
            url: pixgrow_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pixgrow_get_library_stats',
                security: pixgrow_vars.security
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;

                    // Update UI stats counts
                    $('#stats-total').text(data.total);
                    $('#stats-optimized').text(data.optimized);
                    // unoptimized on the stats panel represents needs optimization
                    $('#stats-unoptimized').text(data.unoptimized);
                    $('#stats-skipped').text(data.skipped_invalid || 0);

                    // Fetch paginated pages
                    currentQueuePage = 1;
                    currentHistoryPage = 1;
                    currentLogsPage = 1;
                    fetchQueuePage(1);
                    fetchHistoryPage(1);
                    fetchLogsPage(1);

                    populateScanSelect(data.scan_images || []);
                } else {
                    console.error('Failed to load stats:', response.data.message);
                }
            },
            error: function() {
                console.error('AJAX error loading library stats.');
            }
        });
    }

    /**
     * Fetches a paginated segment of the unoptimized queue from server.
     */
    function fetchQueuePage(page) {
        currentQueuePage = page;
        renderQueueSkeletons();

        const perPage = parseInt($('#queue-per-page').val()) || 20;
        const searchVal = ($('#queue-search-input').val() || '').trim();
        const formatVal = $('#queue-format-filter').val() || 'all';

        const ajaxStart = Date.now();

        $.ajax({
            url: pixgrow_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pixgrow_get_queue_page',
                page: page,
                per_page: perPage,
                search: searchVal,
                format: formatVal,
                security: pixgrow_vars.security
            },
            success: function(response) {
                const ajaxEnd = Date.now();
                if (response.success) {
                    const data = response.data;
                    renderQueueRows(data.items || []);
                    renderQueuePaginationControls(data.page, data.total_pages, data.total_items, data.per_page);
                    
                    // Enable/disable start bulk button based on total unoptimized items count
                    if (data.total_items === 0) {
                        $('#btn-start-bulk').prop('disabled', true).addClass('button-disabled');
                    } else {
                        $('#btn-start-bulk').prop('disabled', false).removeClass('button-disabled');
                    }

                    updateTelemetryUI(data.telemetry, ajaxEnd - ajaxStart);
                } else {
                    console.error('Failed to fetch queue page:', response.data.message);
                }
            },
            error: function() {
                console.error('AJAX error loading queue page.');
            }
        });
    }

    /**
     * Fetches a paginated segment of optimized history from server.
     */
    function fetchHistoryPage(page) {
        currentHistoryPage = page;
        renderHistorySkeletons();

        const perPage = parseInt($('#history-per-page').val()) || 20;
        const searchVal = ($('#history-search-input').val() || '').trim();
        const formatVal = $('#history-format-filter').val() || 'all';

        const ajaxStart = Date.now();

        $.ajax({
            url: pixgrow_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pixgrow_get_history_page',
                page: page,
                per_page: perPage,
                search: searchVal,
                format: formatVal,
                security: pixgrow_vars.security
            },
            success: function(response) {
                const ajaxEnd = Date.now();
                if (response.success) {
                    const data = response.data;
                    renderHistoryRows(data.items || []);
                    renderHistoryPaginationControls(data.page, data.total_pages, data.total_items, data.per_page);
                    updateTelemetryUI(data.telemetry, ajaxEnd - ajaxStart);
                } else {
                    console.error('Failed to fetch history page:', response.data.message);
                }
            },
            error: function() {
                console.error('AJAX error loading history page.');
            }
        });
    }

    /**
     * Fetches chronological logs paginated.
     */
    function fetchLogsPage(page) {
        currentLogsPage = page;
        renderLogsSkeletons();

        const searchVal = ($('#logs-search-input').val() || '').trim();
        const fromDateStr = $('#logs-date-from').val() || '';
        const toDateStr = $('#logs-date-to').val() || '';
        const perPage = parseInt($('#logs-per-page').val()) || 20;

        const ajaxStart = Date.now();

        $.ajax({
            url: pixgrow_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pixgrow_get_history_page',
                page: page,
                per_page: perPage,
                search: searchVal,
                date_from: fromDateStr,
                date_to: toDateStr,
                security: pixgrow_vars.security
            },
            success: function(response) {
                const ajaxEnd = Date.now();
                if (response.success) {
                    const data = response.data;
                    renderLogsRows(data.items || []);
                    renderLogsPaginationControls(data.page, data.total_pages, data.total_items, data.per_page);
                    updateTelemetryUI(data.telemetry, ajaxEnd - ajaxStart);
                } else {
                    console.error('Failed to fetch logs page:', response.data.message);
                }
            },
            error: function() {
                console.error('AJAX error loading logs page.');
            }
        });
    }

    /**
     * Renders queue pagination controls.
     */
    function renderQueuePaginationControls(page, totalPages, totalItems, perPage) {
        const bar = $('.queue-pagination-bar');
        const info = $('.queue-pagination-info');
        bar.empty();

        if (totalPages <= 1) {
            bar.hide();
            if (totalItems > 0) {
                info.text(`Total Queue: ${totalItems} images`);
            } else {
                info.text('');
            }
            return;
        }

        bar.css('display', 'flex');
        const startItem = ((page - 1) * perPage) + 1;
        const endItem = Math.min(page * perPage, totalItems);
        info.text(`Showing ${startItem}-${endItem} of ${totalItems} queue items`);

        // Prev Button
        const prevDisabled = page === 1 ? 'disabled' : '';
        bar.append(`<button class="button button-secondary btn-queue-page-nav" data-page="${page - 1}" ${prevDisabled}>&laquo; Prev</button>`);

        // Pages (Collapsed & Ellipsis range)
        const pageRange = getPaginationRange(page, totalPages);
        pageRange.forEach(function(p) {
            if (p === '...') {
                bar.append(`<span class="pagination-ellipsis">...</span>`);
            } else {
                const activeClass = p === page ? 'button-primary btn-glow' : 'button-secondary';
                bar.append(`<button class="button ${activeClass} btn-queue-page-nav" data-page="${p}">${p}</button>`);
            }
        });

        // Next Button
        const nextDisabled = page === totalPages ? 'disabled' : '';
        bar.append(`<button class="button button-secondary btn-queue-page-nav" data-page="${page + 1}" ${nextDisabled}>Next &raquo;</button>`);

        // Bind page button clicks
        $('.btn-queue-page-nav').off('click').on('click', function(e) {
            e.preventDefault();
            if ($(this).prop('disabled') || $(this).hasClass('disabled')) return;
            const targetPage = parseInt($(this).data('page'));
            fetchQueuePage(targetPage);
        });
    }

    /**
     * Helper to format Used In column with direct edit links
     */
    function formatUsedIn(used_in) {
        if (used_in && used_in.title && used_in.edit_url) {
            let typeLabel = used_in.type;
            if (typeLabel === 'post') typeLabel = 'Post';
            else if (typeLabel === 'page') typeLabel = 'Page';
            else if (typeLabel === 'product') typeLabel = 'Woo Product';
            else typeLabel = typeLabel.charAt(0).toUpperCase() + typeLabel.slice(1);
            return `<a href="${used_in.edit_url}" target="_blank">${typeLabel}: ${used_in.title}</a>`;
        }
        return '<span style="color:#94a3b8; font-style:italic;">Library Only</span>';
    }

    /**
     * Renders queue rows inside the table body.
     */
    function renderQueueRows(items) {
        const tbody = $('#queue-tbody');
        tbody.empty();

        if (items.length === 0) {
            tbody.append('<tr><td colspan="6" class="text-center">🎉 No matching images in queue.</td></tr>');
            return;
        }

        items.forEach(function(item) {
            const actionHtml = `<button class="button button-secondary btn-optimize-single" data-id="${item.id}" data-url="${item.url}" data-name="${item.name}">Compress</button>`;

            const labelHtml = (item.title && item.title !== item.name) ?
                `<strong>${item.title}</strong><br><span style="font-size:0.78rem; color:#94a3b8;">(${item.name})</span>` :
                `<strong>${item.name}</strong>`;

            tbody.append(`
                <tr id="queue-row-${item.id}">
                    <td class="column-thumbnail"><img src="${item.thumbnail_url || item.url}" alt="${item.name}"></td>
                    <td>${labelHtml}<br><span class="row-actions"><span class="edit"><a href="post.php?post=${item.id}&action=edit" target="_blank">Edit Details</a></span></span></td>
                    <td>${item.size}</td>
                    <td>${formatUsedIn(item.used_in)}</td>
                    <td><span class="status-badge status-unoptimized" id="status-text-${item.id}">Pending</span></td>
                    <td class="column-actions">
                        ${actionHtml}
                    </td>
                </tr>
            `);
        });

        // btn-optimize-single is handled delegatively by Pro when active
    }

    /**
     * Renders history rows inside the table body.
     */
    function renderHistoryRows(items) {
        const tbody = $('#history-tbody');
        tbody.empty();

        if (items.length === 0) {
            tbody.hide().html('<tr><td colspan="7" class="text-center" style="padding:24px; color:#94a3b8;"><span class="dashicons dashicons-info" style="font-size:2rem; width:2rem; height:2rem; color:#64748b; display:block; margin:0 auto 10px auto;"></span> No optimized images found in history.</td></tr>').fadeIn(250);
            return;
        }

        let html = '';
        items.forEach(function(item) {
            const activeDot = `<span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#10b981; margin-right:6px; vertical-align:middle; box-shadow:0 0 5px #10b981;"></span>`;
            
            let compareBtn = '';
            let actionHtml = '';
            
            if (pixgrow_vars.is_pro_licensed) {
                compareBtn = `<button class="button button-secondary btn-compare-single" data-id="${item.id}" data-url="${item.url}" data-name="${item.name}" data-orig-size="${item.orig_size}" data-size="${item.size}" data-savings="${item.savings}" style="margin-right:6px;">${activeDot}Compare</button>`;
                actionHtml = `<button class="button button-secondary btn-restore-single" data-id="${item.id}">${activeDot}Restore Original</button>`;
            } else {
                compareBtn = `<button class="button button-secondary pixgrow-pro-lock-click" data-feature="compare" style="margin-right:6px;"><span class="dashicons dashicons-lock" style="font-size:13px; width:13px; height:13px; margin-right:4px; vertical-align:middle;"></span>Compare (Pro)</button>`;
                actionHtml = `<button class="button button-secondary pixgrow-pro-lock-click" data-feature="restore"><span class="dashicons dashicons-lock" style="font-size:13px; width:13px; height:13px; margin-right:4px; vertical-align:middle;"></span>Restore Original (Pro)</button>`;
            }
            
            const mediaTitle = item.title || item.name || '(No Title)';

            html += `
                <tr>
                    <td class="column-thumbnail"><img src="${item.thumbnail_url || item.url}" alt="${item.name}"></td>
                    <td><strong>${mediaTitle}</strong></td>
                    <td style="color:#94a3b8; font-size:0.85rem; font-family:monospace;">${item.name}</td>
                    <td>${formatUsedIn(item.used_in)}</td>
                    <td>${item.orig_size}</td>
                    <td>${item.size}</td>
                    <td><span style="color:#34d399; font-weight:600;">${(item.savings === '0%' || item.savings === '0') ? '0%' : `-${item.savings}`}</span></td>
                    <td class="column-actions">
                        <div style="display:flex; justify-content:flex-end; align-items:center;">
                            ${compareBtn}
                            ${actionHtml}
                        </div>
                    </td>
                </tr>
            `;
        });

        tbody.hide().html(html).fadeIn(250);
    }

    /**
     * Renders history pagination controls.
     */
    function renderHistoryPaginationControls(page, totalPages, totalItems, perPage) {
        const bar = $('.history-pagination-bar');
        const info = $('.history-pagination-info');
        bar.empty();

        if (totalPages <= 1) {
            bar.hide();
            if (totalItems > 0) {
                info.text(`Showing all ${totalItems} items`);
            } else {
                info.text('');
            }
            return;
        }

        bar.css('display', 'flex');
        const startItem = ((page - 1) * perPage) + 1;
        const endItem = Math.min(page * perPage, totalItems);
        info.text(`Showing ${startItem}-${endItem} of ${totalItems} items`);

        // Prev Button
        const prevDisabled = page === 1 ? 'disabled' : '';
        bar.append(`<button class="button button-secondary btn-history-page-nav" data-page="${page - 1}" ${prevDisabled}>&laquo; Prev</button>`);

        // Pages (Collapsed & Ellipsis range)
        const pageRange = getPaginationRange(page, totalPages);
        pageRange.forEach(function(p) {
            if (p === '...') {
                bar.append(`<span class="pagination-ellipsis">...</span>`);
            } else {
                const activeClass = p === page ? 'button-primary btn-glow' : 'button-secondary';
                bar.append(`<button class="button ${activeClass} btn-history-page-nav" data-page="${p}">${p}</button>`);
            }
        });

        // Next Button
        const nextDisabled = page === totalPages ? 'disabled' : '';
        bar.append(`<button class="button button-secondary btn-history-page-nav" data-page="${page + 1}" ${nextDisabled}>Next &raquo;</button>`);

        // Bind page button clicks
        $('.btn-history-page-nav').off('click').on('click', function(e) {
            e.preventDefault();
            if ($(this).prop('disabled') || $(this).hasClass('disabled')) return;
            const targetPage = parseInt($(this).data('page'));
            fetchHistoryPage(targetPage);
        });
    }

    /**
     * Renders log rows inside the table body.
     */
    function renderLogsRows(items) {
        const tbody = $('#logs-tbody');
        tbody.empty();

        if (items.length === 0) {
            tbody.html('<tr><td colspan="6" class="text-center">No optimization logs found. Start optimizing images from the Bulk Compressor to generate logs here.</td></tr>');
            return;
        }

        let html = '';
        items.forEach(function(item) {
            const formattedTime = item.opt_time ? item.opt_time : 'N/A';
            const savingsText = (item.savings === '0%' || item.savings === '0') ? '0%' : `-${item.savings}`;
            html += `
                <tr>
                    <td><code>${formattedTime}</code></td>
                    <td><strong>${item.name}</strong> (ID: ${item.id})</td>
                    <td>${item.orig_size}</td>
                    <td>${item.size}</td>
                    <td><span style="color:#34d399; font-weight:600;">${savingsText}</span></td>
                    <td><span class="status-badge status-optimized">Optimized</span></td>
                </tr>
            `;
        });
        tbody.html(html);
    }

    /**
     * Renders log pagination controls.
     */
    function renderLogsPaginationControls(page, totalPages, totalItems, perPage) {
        const bar = $('.logs-pagination-bar');
        const info = $('.logs-pagination-info');
        bar.empty();

        if (totalPages <= 1) {
            bar.hide();
            if (totalItems > 0) {
                info.text(`Showing all ${totalItems} log items`);
            } else {
                info.text('');
            }
            return;
        }

        bar.css('display', 'flex');
        const startItem = ((page - 1) * perPage) + 1;
        const endItem = Math.min(page * perPage, totalItems);
        info.text(`Showing ${startItem}-${endItem} of ${totalItems} logs`);

        // Prev Button
        const prevDisabled = page === 1 ? 'disabled' : '';
        bar.append(`<button class="button button-secondary btn-log-page-nav" data-page="${page - 1}" ${prevDisabled}>&laquo; Prev</button>`);

        // Pages (Collapsed & Ellipsis range)
        const pageRange = getPaginationRange(page, totalPages);
        pageRange.forEach(function(p) {
            if (p === '...') {
                bar.append(`<span class="pagination-ellipsis">...</span>`);
            } else {
                const activeClass = p === page ? 'button-primary btn-glow' : 'button-secondary';
                bar.append(`<button class="button ${activeClass} btn-log-page-nav" data-page="${p}">${p}</button>`);
            }
        });

        // Next Button
        const nextDisabled = page === totalPages ? 'disabled' : '';
        bar.append(`<button class="button button-secondary btn-log-page-nav" data-page="${page + 1}" ${nextDisabled}>Next &raquo;</button>`);

        // Bind page click handler
        $('.btn-log-page-nav').off('click').on('click', function(e) {
            e.preventDefault();
            if ($(this).prop('disabled') || $(this).hasClass('disabled')) return;
            const targetPage = parseInt($(this).data('page'));
            fetchLogsPage(targetPage);
        });
    }

    /**
     * Helper to get a collapsed, responsive page range.
     */
    function getPaginationRange(currentPage, totalPages) {
        const range = 1;
        let pages = [];
        pages.push(1);

        let start = Math.max(2, currentPage - range);
        let end = Math.min(totalPages - 1, currentPage + range);

        if (start > 2) {
            pages.push('...');
        }

        for (let i = start; i <= end; i++) {
            pages.push(i);
        }

        if (end < totalPages - 1) {
            pages.push('...');
        }

        if (totalPages > 1) {
            pages.push(totalPages);
        }

        return pages;
    }

    /**
     * Populates the scanner dropdown selection.
     */
    function populateScanSelect(images) {
        const select = $('#scan-attachment-select');
        select.empty();

        if (images.length === 0) {
            select.append('<option value="">No images available</option>');
            return;
        }

        images.forEach(function(img) {
            select.append(`<option value="${img.id}">${img.name} (ID: ${img.id})</option>`);
        });
    };

    /**
     * Free stubs for Pro features to trigger locked modal.
     */
    /**
     * Sequential bulk and single compression runners for Free mode (WebP).
     */
    function freeSingleAction(id, url, name) {
        if (isProcessing) {
            showNotice('Bulk compression is currently running. Please wait or stop the queue.', 'warning');
            return;
        }

        const btn = $(`.btn-optimize-single[data-id="${id}"]`);
        const originalHtml = btn.html();
        btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

        optimizeImage(id, url, name)
            .then(function() {
                btn.prop('disabled', false).text('Compress');
                loadLibraryStats();
            })
            .catch(function(err) {
                showNotice('Compression failed: ' + err, 'error');
                btn.prop('disabled', false).html(originalHtml);
            });
    }

    function freeUndoAction(id) {
        // Restore is locked in Free mode
        showProLockModal('restore');
    }

    function freeBulkAction(isResuming) {
        if (isProcessing) return;

        isProcessing = true;
        $('#btn-start-bulk').hide();
        $('#btn-stop-bulk').show();
        $('.progress-section').slideDown(200);
        $('.pixgrow-console-log').slideDown(200);

        const logBox = $('.pixgrow-log-box');
        if (!isResuming) {
            logBox.empty().append(`<div class="log-row info">${getLogTime()} [System] Initializing bulk compression queue...</div>`);
        } else {
            logBox.append(`<div class="log-row info">${getLogTime()} [System] Resuming bulk compression queue...</div>`);
        }

        logBox.append(`<div class="log-row info">${getLogTime()} [System] Fetching first batch from server...</div>`);

        processedCount = 0;
        processedItemIds = [];
        queue = [];

        loadBulkQueueBatch([])
            .then(function(items) {
                if (items.length === 0) {
                    isProcessing = false;
                    $('#btn-start-bulk').show();
                    $('#btn-stop-bulk').hide();
                    showNotice('No unoptimized images found matching your filter.', 'info');
                } else {
                    processNextQueueItem();
                }
            })
            .catch(function(err) {
                isProcessing = false;
                $('#btn-start-bulk').show();
                $('#btn-stop-bulk').hide();
                showNotice('Failed to initialize queue: ' + err, 'error');
            });
    }

    function stopBulkOptimization() {
        isProcessing = false;
        $('#btn-start-bulk').show();
        $('#btn-stop-bulk').hide();
        $('#progress-status').text('Optimization stopped.');
        logMessage('[System] Bulk optimization stopped by user.', 'warning');
    }

    function optimizeImage(id, url, filename) {
        return new Promise(function(resolve, reject) {
            updateRowStatus(id, 'compressing', 'Compressing...');
            logMessage(`Starting optimization for image: ${filename} (ID: ${id})`, 'info');

            $.ajax({
                url: pixgrow_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'pixgrow_compress_attachment',
                    attachment_id: id,
                    security: pixgrow_vars.security
                },
                success: function(response) {
                    if (response.success) {
                        updateRowStatus(id, 'optimized', 'Optimized');
                        logMessage(`Compression complete (WEBP). Size reduced to ${response.data.new_size}.`, 'info');
                        resolve(response.data);
                    } else if (response.data && response.data.code === 'server_webp_unsupported') {
                        logMessage('Server WebP unsupported. Falling back to local browser-side Canvas compression...', 'warning');
                        runCanvasFallback(id, url, filename)
                            .then(resolve)
                            .catch(reject);
                    } else {
                        const errorMsg = response.data && response.data.message ? response.data.message : 'Unknown error';
                        updateRowStatus(id, 'unoptimized', 'Failed');
                        logMessage(`Compression failed for ${filename}: ${errorMsg}`, 'error');
                        reject(errorMsg);
                    }
                },
                error: function() {
                    updateRowStatus(id, 'unoptimized', 'Error');
                    logMessage(`Network error compressing ${filename}.`, 'error');
                    reject('Network error');
                }
            });
        });
    }

    function runCanvasFallback(id, url, filename) {
        return new Promise(function(resolve, reject) {
            updateRowStatus(id, 'compressing', 'Downloading...');
            fetch(url)
                .then(res => {
                    if (!res.ok) throw new Error('Failed to fetch original image file.');
                    return res.blob();
                })
                .then(async blob => {
                    updateRowStatus(id, 'compressing', 'Resizing Canvas...');
                    try {
                        const format = 'webp';
                        const quality = 80;

                        // 1. Compress main image
                        const compressedBlob = await compressImageBlob(blob, format, quality);

                        // 2. Prepare FormData for companion upload
                        const formData = new FormData();
                        formData.append('action', 'pixgrow_save_companion_webp');
                        formData.append('attachment_id', id);
                        formData.append('security', pixgrow_vars.security);

                        const newName = filename.substring(0, filename.lastIndexOf('.')) + '.webp';
                        formData.append('main_file', compressedBlob, newName);

                        // 3. Process thumbnails
                        const registeredSizes = pixgrow_vars.sizes || {};
                        const img = new Image();
                        img.src = URL.createObjectURL(blob);
                        await new Promise((res, rej) => {
                            img.onload = () => {
                                URL.revokeObjectURL(img.src);
                                res();
                            };
                            img.onerror = rej;
                        });

                        const sourceWidth = img.width;
                        const sourceHeight = img.height;

                        for (const [sizeName, sizeOpts] of Object.entries(registeredSizes)) {
                            const targetWidth = parseInt(sizeOpts.width);
                            const targetHeight = parseInt(sizeOpts.height);
                            const crop = sizeOpts.crop;

                            if (targetWidth >= sourceWidth && targetHeight >= sourceHeight) {
                                continue;
                            }

                            const thumbCanvasBlob = await resizeImageCanvas(blob, targetWidth, targetHeight, crop);
                            const thumbCompressed = await compressImageBlob(thumbCanvasBlob, format, quality);
                            formData.append('thumb_' + sizeName, thumbCompressed, filename.substring(0, filename.lastIndexOf('.')) + '-' + sizeName + '.webp');
                        }

                        updateRowStatus(id, 'compressing', 'Saving companion...');

                        $.ajax({
                            url: pixgrow_vars.ajax_url,
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            success: function(uploadResponse) {
                                if (uploadResponse.success) {
                                    updateRowStatus(id, 'optimized', 'Optimized');
                                    logMessage(`Canvas companion WebPs saved successfully for ${filename}.`, 'info');
                                    resolve(uploadResponse.data);
                                } else {
                                    const uploadErr = uploadResponse.data && uploadResponse.data.message ? uploadResponse.data.message : 'Unknown upload error';
                                    updateRowStatus(id, 'unoptimized', 'Upload failed');
                                    logMessage(`Failed to upload Canvas WebPs for ${filename}: ${uploadErr}`, 'error');
                                    reject(uploadErr);
                                }
                            },
                            error: function() {
                                updateRowStatus(id, 'unoptimized', 'Upload error');
                                logMessage(`Network error uploading Canvas WebPs for ${filename}.`, 'error');
                                reject('Upload network error');
                            }
                        });

                    } catch (err) {
                        updateRowStatus(id, 'unoptimized', 'Failed');
                        logMessage(`Canvas compression failed for ${filename}: ${err.message || err}`, 'error');
                        reject(err);
                    }
                })
                .catch(err => {
                    updateRowStatus(id, 'unoptimized', 'Failed');
                    logMessage(`Canvas fallback failed for ${filename}: ${err.message || err}`, 'error');
                    reject(err);
                });
        });
    }

    function compressImageBlob(blob, targetFormat, qualityValue) {
        return new Promise(function(resolve, reject) {
            const format = targetFormat || 'webp';
            const quality = qualityValue ? qualityValue / 100 : 0.8;
            const img = new Image();
            img.src = URL.createObjectURL(blob);
            img.onload = function() {
                URL.revokeObjectURL(img.src);
                const canvas = document.createElement('canvas');
                canvas.width = img.width;
                canvas.height = img.height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);
                canvas.toBlob(function(resultBlob) {
                    if (resultBlob) {
                        resolve(resultBlob);
                    } else {
                        reject(new Error('Canvas toBlob returned null.'));
                    }
                }, 'image/' + format, quality);
            };
            img.onerror = function() {
                URL.revokeObjectURL(img.src);
                reject(new Error('Failed to load image for canvas compression.'));
            };
        });
    }

    function resizeImageCanvas(blob, targetWidth, targetHeight, crop) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.src = URL.createObjectURL(blob);
            img.onload = () => {
                URL.revokeObjectURL(img.src);
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');

                const sourceWidth = img.width;
                const sourceHeight = img.height;

                let destWidth = targetWidth;
                let destHeight = targetHeight;

                if (!crop) {
                    const scale = Math.min(targetWidth / sourceWidth, targetHeight / sourceHeight);
                    destWidth = Math.round(sourceWidth * scale);
                    destHeight = Math.round(sourceHeight * scale);
                    canvas.width = destWidth;
                    canvas.height = destHeight;
                    ctx.drawImage(img, 0, 0, sourceWidth, sourceHeight, 0, 0, destWidth, destHeight);
                } else {
                    canvas.width = targetWidth;
                    canvas.height = targetHeight;

                    const scale = Math.max(targetWidth / sourceWidth, targetHeight / sourceHeight);
                    const sourceCropWidth = targetWidth / scale;
                    const sourceCropHeight = targetHeight / scale;

                    const sourceX = (sourceWidth - sourceCropWidth) / 2;
                    const sourceY = (sourceHeight - sourceCropHeight) / 2;

                    ctx.drawImage(img, sourceX, sourceY, sourceCropWidth, sourceCropHeight, 0, 0, targetWidth, targetHeight);
                }

                canvas.toBlob((resultBlob) => {
                    if (resultBlob) {
                        resolve(resultBlob);
                    } else {
                        reject(new Error('Canvas resizing toBlob returned null.'));
                    }
                }, blob.type);
            };
            img.onerror = () => {
                URL.revokeObjectURL(img.src);
                reject(new Error('Failed to load image for canvas resizing.'));
            };
        });
    }

    function loadBulkQueueBatch(excludeIds) {
        return new Promise(function(resolve, reject) {
            const searchQuery = ($('#queue-search-input').val() || '').trim();
            const formatFilter = $('#queue-format-filter').val() || 'all';

            $.ajax({
                url: pixgrow_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'pixgrow_get_queue_page',
                    page: 1,
                    per_page: 50,
                    search: searchQuery,
                    format: formatFilter,
                    exclude: excludeIds,
                    security: pixgrow_vars.security
                },
                success: function(response) {
                    if (response.success) {
                        const items = response.data.items || [];
                        queue = queue.concat(items);
                        resolve(items);
                    } else {
                        reject(response.data.message || 'Unknown error');
                    }
                },
                error: function() {
                    reject('Network error');
                }
            });
        });
    }

    function processNextQueueItem() {
        if (!isProcessing) return;

        if (queue.length === 0) {
            logMessage(`[System] Loading next batch of images from server...`, 'info');
            loadBulkQueueBatch(processedItemIds)
                .then(function(items) {
                    if (items.length === 0) {
                        finishBulkOptimization();
                    } else {
                        processNextQueueItem();
                    }
                })
                .catch(function(err) {
                    isProcessing = false;
                    $('#btn-start-bulk').show();
                    $('#btn-stop-bulk').hide();
                    showNotice('Failed to fetch next batch: ' + err, 'error');
                });
            return;
        }

        const currentItem = queue.shift();
        processedItemIds.push(currentItem.id);

        optimizeImage(currentItem.id, currentItem.url, currentItem.name)
            .then(function() {
                processedCount++;
                const progressPercent = Math.min(100, Math.round((processedItemIds.length / (processedItemIds.length + queue.length)) * 100));

                $('#progress-bar-fill').css('width', progressPercent + '%');
                $('#progress-percentage').text(progressPercent + '%');
                $('#progress-status').text(`Optimizing image ${processedItemIds.length}...`);

                setTimeout(processNextQueueItem, 100);
            })
            .catch(function(err) {
                logMessage(`Skipping ${currentItem.name} due to error: ${err}`, 'error');
                setTimeout(processNextQueueItem, 100);
            });
    }

    function finishBulkOptimization() {
        isProcessing = false;
        $('#btn-start-bulk').show();
        $('#btn-stop-bulk').hide();
        $('#progress-status').text('Optimization completed successfully!');
        logMessage(`[System] Bulk optimization finished. Processed ${processedCount} images.`, 'success');
        showNotice(`Bulk optimization completed. Processed ${processedCount} images.`, 'success');

        loadLibraryStats();
    }

    function updateRowStatus(id, status, text) {
        const row = $(`#queue-row-${id}`);
        if (!row.length) return;

        const badge = row.find(`#status-text-${id}`);
        badge.removeClass('status-unoptimized status-optimized status-skipped status-failed');

        if (status === 'compressing') {
            badge.addClass('status-unoptimized').text(text || 'Compressing...');
        } else if (status === 'optimized') {
            badge.addClass('status-optimized').text(text || 'Optimized');
            row.find('.column-actions').html('<span style="color:#10b981; font-weight:600;">✓ Ready</span>');
        } else {
            badge.addClass('status-failed').text(text || 'Failed');
        }
    }

    /**
     * Helper to format bytes into readable sizes.
     */
    function size_format_bytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const decimals = 2;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(decimals)) + ' ' + sizes[i];
    }

    /**
     * Get formatted log timestamp.
     */
    function getLogTime() {
        const d = new Date();
        return '[' + d.toTimeString().split(' ')[0] + ']';
    }

    /**
     * Appends a message to the console log box.
     */
    function logMessage(text, type = 'info') {
        const logBox = $('.pixgrow-log-box');
        if (logBox.length) {
            const escapedText = $('<div/>').text(text).html();
            logBox.append(`<div class="log-row ${type}">${getLogTime()} ${escapedText}</div>`);
            logBox.scrollTop(logBox[0].scrollHeight);
        }
    }

    function loadDiagnostics() {
        const btn = $('#btn-refresh-diagnostics');
        btn.prop('disabled', true).text('Refreshing...');
        
        const ajaxStart = Date.now();
        $.ajax({
            url: pixgrow_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pixgrow_get_diagnostics',
                security: pixgrow_vars.security
            },
            success: function(response) {
                const ajaxEnd = Date.now();
                btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align:middle; margin-right:4px;"></span>Refresh Telemetry');
                if (response.success) {
                    const data = response.data;
                    $('#diag-pixgrow-val').text(data.pixgrow_version);
                    $('#diag-php-val').text(data.php_version);
                    $('#diag-wp-val').text(data.wp_version);
                    $('#diag-mem-val').text(data.memory_limit);
                    $('#diag-wpmem-val').text(data.wp_memory_limit + ' / (Max: ' + data.wp_max_memory_limit + ')');
                    $('#diag-disk-val').text(data.disk_free);
                    
                    const formatWritable = function(writable) {
                        return writable ? '<span style="color:#22c55e; font-weight:bold;">✓ Writable</span>' : '<span style="color:#ef4444; font-weight:bold;">✗ Not Writable</span>';
                    };
                    
                    $('#diag-uploads-writable-val').html(formatWritable(data.upload_dir.writable));
                    $('#diag-backups-writable-val').html(formatWritable(data.backup_dir.writable));
                    $('#diag-logs-writable-val').html(formatWritable(data.log_dir.writable));
                    
                    let codecsStr = '';
                    if (data.codecs.gd.indexOf('loaded') !== -1) {
                        codecsStr += `GD: ${data.codecs.gd_formats || 'None'}`;
                    }
                    if (data.codecs.imagick.indexOf('loaded') !== -1) {
                        if (codecsStr) codecsStr += ' | ';
                        codecsStr += `Imagick: ${data.codecs.imagick_formats || 'None'}`;
                    }
                    if (!codecsStr) {
                        codecsStr = 'No supported libraries loaded';
                    }
                    $('#diag-codecs-val').text(codecsStr);

                    if (data.telemetry) {
                        $('#diag-buildtime-val').text(data.telemetry.time_ms + ' ms');
                        $('#diag-ajaxresponse-val').text((ajaxEnd - ajaxStart) + ' ms');
                        $('#diag-peakmem-val').text(data.telemetry.peak_mem_mb + ' MB');
                        $('#diag-querycount-val').text(data.telemetry.queries);
                    }
                } else {
                    showNotice('Failed to load system diagnostics: ' + response.data.message, 'error');
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align:middle; margin-right:4px;"></span>Refresh Telemetry');
                showNotice('Connection error while loading diagnostics.', 'error');
            }
        });
    }

    function loadDebugLogs() {
        const consoleEl = $('#pixgrow-debug-log-console');
        consoleEl.text('Loading debug logs...');
        
        $.ajax({
            url: pixgrow_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pixgrow_get_logs',
                security: pixgrow_vars.security
            },
            success: function(response) {
                if (response.success) {
                    consoleEl.text(response.data.logs);
                    consoleEl.scrollTop(consoleEl[0].scrollHeight);
                } else {
                    consoleEl.text('Error: ' + response.data.message);
                }
            },
            error: function() {
                consoleEl.text('Connection error loading debug logs.');
            }
        });
    }

    function clearDebugLogs() {
        $.ajax({
            url: pixgrow_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pixgrow_clear_logs',
                security: pixgrow_vars.security
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    loadDebugLogs();
                } else {
                    showNotice('Failed to clear logs: ' + response.data.message, 'error');
                }
            },
            error: function() {
                showNotice('Connection error while clearing debug logs.', 'error');
            }
        });
    }

    /**
     * Displays the Premium Feature Unlock Modal with dynamic copy.
     */
    function showProLockModal(feature) {
        let title = 'Unlock Pro Feature';
        let desc = 'This is a premium feature. Upgrade to PixGrow Pro to unlock next-generation compression, unthrottled queues, automated uploads, and path replacement autopilot!';

        if (feature === 'formats') {
            title = 'Unlock Smart Formats';
            desc = 'Achieve up to 50% smaller sizes than standard formats with matching visual quality! Unlock our Smart Format Auto-Routing Engine.';
        } else if (feature === 'compare') {
            title = 'Unlock Visual Quality Comparison';
            desc = 'Visually inspect your visual quality changes. Pro unlocks a real-time, split-screen sliding comparison window showing original vs optimized images!';
        } else if (feature === 'restore') {
            title = 'Unlock Original Backup & Restore';
            desc = 'Restore your original uncompressed images in 1-click at any time! Pro backs up original raw assets and enables instant rollback for all optimized attachments.';
        } else if (feature === 'automation') {
            title = 'Unlock Automation Autopilot';
            desc = 'Hands-free background upload compression and automatic background queue processing. Optimize your uploads silently while you focus on writing content!';
        } else if (feature === 'csv') {
            title = 'Unlock CSV Chronological Export';
            desc = 'Export chronological logs of your image optimizations to Excel-compatible CSV files. Download complete spreadsheets of your savings metrics anytime!';
        } else if (feature === 'scanner') {
            title = 'Unlock Static Reference Replacer';
            desc = 'Automate path replacements recursively across post database records and active theme files (PHP/CSS) with safe recovery backup checkpoints!';
        } else if (feature === 'single_compress') {
            title = 'Single Image Optimization';
            desc = 'Compress individual images instantly without running bulk optimization. Available in PixGrow Pro.';
        }

        $('#pixgrow-pro-lock-modal .pixgrow-pro-lock-title').text(title);
        $('#pixgrow-pro-lock-modal .pixgrow-pro-lock-desc').text(desc);
        
        $('#pixgrow-pro-lock-modal').fadeIn(200, function() {
            $(this).addClass('show');
        });
    }

})(jQuery);

