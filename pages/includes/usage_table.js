// ============================================================
// USAGE TABLE - DataTable init, filters, export, auto-refresh
// ============================================================
// Depends on: usage_analytics.js (initAnalyticsDashboard, refreshDataAjax, processLogsData, renderTokensChart)

$(document).ready(function() {
    let autoRefreshInterval = null;

    // Initialize analytics dashboard
    initAnalyticsDashboard();

    // Custom sorting for token column
    $.fn.dataTable.ext.order['tokens-sort'] = function(settings, col) {
        return this.api().column(col, { order: 'index' }).nodes().map(function(td, i) {
            var totalTokens = $(td).find('.accordion-button').text().match(/Total: (\d+)/);
            return totalTokens ? parseInt(totalTokens[1], 10) : 0;
        });
    };

    // Custom sorting for tools column
    $.fn.dataTable.ext.order['tools-sort'] = function(settings, col) {
        return this.api().column(col, { order: 'index' }).nodes().map(function(td, i) {
            var toolCount = $(td).find('.accordion-button').text().match(/(\d+) tool/);
            return toolCount ? parseInt(toolCount[1], 10) : 0;
        });
    };

    // Initialize DataTable with export buttons
    var table = $('#logTable').DataTable({
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "fixedColumns": true,
        "pageLength": 25,
        "lengthMenu": [10, 25, 50, 75, 100],
        "order": [[0, 'desc']], // Default sort by ID descending (newest first)
        "columnDefs": [
            {
                "targets": 5,
                "orderDataType": "tokens-sort"
            },
            {
                "targets": 9,
                "orderDataType": "tools-sort"
            }
        ],
        "dom": '<"row"<"col-sm-6"l><"col-sm-6"f>>rtip', // Put length and search on same row
        "buttons": []
    });

    // Model filter
    $('#modelFilter').on('change', function() {
        table.column(4).search(this.value).draw();
    });

    // Project filter (silently no-ops if element doesn't exist)
    $('#projectFilter').on('change', function() {
        table.column(1).search(this.value).draw();
    });

    // Type filter
    $('#typeFilter').on('change', function() {
        table.column(2).search(this.value).draw();
    });

    // Session ID filter
    $('#sessionFilter').on('change', function() {
        table.column(5).search(this.value).draw();
    });

    // Agent mode filter
    $('#agentModeFilter').on('change', function() {
        if (this.checked) {
            // Show only rows with tools
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var toolsCell = $(table.row(dataIndex).node()).find('.tools-column').text().trim();
                return toolsCell !== '\u2014' && toolsCell !== '';
            });
        } else {
            // Remove custom filter
            $.fn.dataTable.ext.search.pop();
        }
        table.draw();
    });

    // Limit selector
    $('#limitSelect').on('change', function() {
        window.location.href = window.location.pathname + '?limit=' + this.value;
    });

    // Export CSV
    $('#exportBtn').on('click', function() {
        // Get filtered/sorted data
        var data = table.rows({ search: 'applied' }).data();
        var csv = 'ID,Project ID,Type,Timestamp,Model,Tokens,Temperature,Top P,Freq Penalty,Pres Penalty\n';

        data.each(function(row) {
            // Extract data from HTML (simplified for CSV)
            var $row = $(table.row(':contains("' + row[0] + '")').node());
            csv += '"' + row[0] + '",';  // ID
            csv += '"' + row[1] + '",';  // Project ID
            csv += '"' + row[2] + '",';  // Type
            csv += '"' + row[3] + '",';  // Timestamp
            csv += '"' + row[4] + '"\n'; // Model
        });

        var blob = new Blob([csv], { type: 'text/csv' });
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'securechat-logs-' + new Date().toISOString().slice(0,10) + '.csv';
        a.click();
    });

    // Export JSON
    $('#exportJsonBtn').on('click', function() {
        var data = table.rows({ search: 'applied' }).data().toArray();
        var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'securechat-logs-' + new Date().toISOString().slice(0,10) + '.json';
        a.click();
    });

    // Auto-refresh toggle
    $('#autoRefreshToggle').on('change', function() {
        if (this.checked) {
            $('#autoRefreshIndicator').show();
            $('#liveIndicator').show();
            autoRefreshInterval = setInterval(function() {
                refreshDataAjax();
            }, 30000); // 30 seconds
        } else {
            $('#autoRefreshIndicator').hide();
            $('#liveIndicator').hide();
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
        }
    });

    // Start auto-refresh by default (since checkbox is checked)
    if ($('#autoRefreshToggle').is(':checked')) {
        $('#autoRefreshIndicator').show();
        $('#liveIndicator').show();
        autoRefreshInterval = setInterval(function() {
            refreshDataAjax();
        }, 30000);
    }

    // Date filtering
    $('#applyDateFilter').on('click', function() {
        const startDate = $('#dateStart').val();
        const endDate = $('#dateEnd').val();

        if (!startDate || !endDate) {
            alert('Please select both start and end dates');
            return;
        }

        // Add date filters to URL and reload
        const params = new URLSearchParams(window.location.search);
        params.set('dateStart', startDate);
        params.set('dateEnd', endDate);
        window.location.search = params.toString();
    });

    $('#clearDateFilter').on('click', function() {
        const params = new URLSearchParams(window.location.search);
        params.delete('dateStart');
        params.delete('dateEnd');
        window.location.search = params.toString();
    });

    // Load date filters from URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('dateStart')) {
        $('#dateStart').val(urlParams.get('dateStart'));
    }
    if (urlParams.has('dateEnd')) {
        $('#dateEnd').val(urlParams.get('dateEnd'));
    }

    // Dark mode toggle
    $('#darkModeToggle').on('click', function() {
        $('body').toggleClass('dark-mode');
        const isDark = $('body').hasClass('dark-mode');
        $('#darkModeIcon').text(isDark ? '\u2600\uFE0F' : '\uD83C\uDF19');
        localStorage.setItem('darkMode', isDark);
    });

    // Load dark mode preference
    if (localStorage.getItem('darkMode') === 'true') {
        $('body').addClass('dark-mode');
        $('#darkModeIcon').text('\u2600\uFE0F');
    }

    // Theme selector
    $('#themeSelector').on('change', function() {
        const theme = this.value;
        $('.analytics-card').removeClass('theme-ocean theme-sunset theme-forest theme-neon theme-monochrome');
        if (theme !== 'default') {
            $('.analytics-card').addClass('theme-' + theme);
        }
        localStorage.setItem('dashboardTheme', theme);
    });

    // Load theme preference (default to ocean if none saved - for the humorless directors)
    const savedTheme = localStorage.getItem('dashboardTheme') || 'ocean';
    if (savedTheme !== 'default') {
        $('#themeSelector').val(savedTheme);
        $('.analytics-card').addClass('theme-' + savedTheme);
    }

    // Manual refresh button
    $('#manualRefreshBtn').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true).css('animation', 'spin 0.6s linear');

        refreshDataAjax();

        setTimeout(() => {
            btn.prop('disabled', false).css('animation', 'none');
        }, 1000);
    });

    // Time range toggle buttons
    $('#timeRangeToggle button').on('click', function() {
        const btn = $(this);
        const range = btn.data('range');

        // Update button states
        $('#timeRangeToggle button').removeClass('btn-primary active').addClass('btn-outline-primary');
        btn.removeClass('btn-outline-primary').addClass('btn-primary active');

        // Re-render chart with new time range
        const processedData = processLogsData(window.logsData);
        renderTokensChart(processedData, range);
    });

    // Ensure all accordions in the row expand/collapse together
    $('#logTable').on("click", ".accordion-button", function(event) {
        event.stopPropagation();
        event.preventDefault();

        let row = $(this).closest("tr");
        let isExpanding = row.find('.accordion-collapse.show').length === 0;

        row.find('.accordion-button').each(function() {
            let button = $(this);
            let target = button.attr('data-bs-target');

            if (isExpanding) {
                button.removeClass('collapsed').attr('aria-expanded', true);
                $(target).addClass('show').collapse('show');
            } else {
                button.addClass('collapsed').attr('aria-expanded', false);
                $(target).removeClass('show').collapse('hide');
            }
        });
    });

    // Prevent sorting when clicking accordion buttons
    $('#logTable').on('click', 'th', function(event) {
        if ($(event.target).closest('.accordion-button').length > 0) {
            event.stopImmediatePropagation();
        }
    });
});

// Session Modal - Click on session_id to view conversation
$('#logTable').on('click', '.session-id-column', function(e) {
    e.preventDefault();
    var sessionId = $(this).text().trim();
    if (!sessionId || sessionId === 'N/A') return;

    // Show modal with loading state
    $('#sessionModalLabel').text('Session: ' + sessionId);
    $('#sessionContent').html('<div class="text-center p-4"><div class="spinner-border" role="status"></div><p class="mt-2">Loading conversation...</p></div>');
    $('#sessionModal').modal('show');

    // Build URL - use dedicated AJAX endpoint if available (project page), otherwise same page (admin)
    var ajaxUrl;
    if (window.USAGE_AJAX_URL) {
        var url = new URL(window.USAGE_AJAX_URL, window.location.origin);
        url.searchParams.set('session_id', sessionId);
        ajaxUrl = url.toString();
    } else {
        var url = new URL(window.location.href);
        url.searchParams.set('session_id', sessionId);
        url.searchParams.delete('format');
        url.searchParams.delete('demo');
        ajaxUrl = url.toString();
    }
    console.log('Fetching session from:', ajaxUrl);

    // Fetch session data via query param
    $.ajax({
        url: ajaxUrl,
        type: 'GET',
        success: function(session, textStatus, xhr) {
            console.log('Got response:', session);
            if (session.error) {
                $('#sessionContent').html('<div class="alert alert-danger">' + session.error + '</div>');
                return;
            }

            if (!session.messages || session.messages.length === 0) {
                $('#sessionContent').html('<div class="alert alert-warning">No messages found for this session.</div>');
                return;
            }

            // Build conversation display
            var html = '<div class="session-meta mb-3 p-3 bg-light rounded">';
            html += '<strong>Session ID:</strong> ' + session.session_id + '<br>';
            html += '<strong>Project ID:</strong> ' + (session.metadata?.project_id || 'N/A') + '<br>';
            html += '<strong>Started:</strong> ' + (session.metadata?.start_time || 'N/A') + '<br>';
            html += '<strong>Duration:</strong> ' + (session.metadata?.duration_seconds || 0) + ' seconds<br>';
            html += '<strong>Total Turns:</strong> ' + (session.stats?.total_turns || 0) + '<br>';
            html += '<strong>Total Tokens:</strong> ' + (session.stats?.total_tokens || 0).toLocaleString() + '<br>';
            html += '<strong>Models:</strong> ' + (session.stats?.models_used?.join(', ') || 'N/A');
            html += '</div>';

            html += '<div class="conversation-thread">';

            var turn = 0;
            for (var i = 0; i < session.messages.length; i++) {
                var msg = session.messages[i];
                var isUser = msg.role === 'user';
                var bubbleClass = isUser ? 'bg-primary text-white' : 'bg-light';
                var alignClass = isUser ? 'justify-content-end' : 'justify-content-start';
                var label = isUser ? 'User' : 'Assistant';

                // Check if this is a new turn
                if (msg.turn !== turn) {
                    turn = msg.turn;
                    html += '<hr class="my-4"><div class="turn-label text-muted small mb-2">Turn ' + turn + '</div>';
                }

                html += '<div class="d-flex ' + alignClass + ' mb-3">';
                html += '<div class="card ' + bubbleClass + '" style="max-width: 80%;">';
                html += '<div class="card-body p-3">';
                html += '<div class="small opacity-75 mb-1">' + label + '</div>';
                html += '<div class="message-content">' + escapeHtml(msg.content) + '</div>';

                if (!isUser && msg.tools_used && msg.tools_used.length > 0) {
                    html += '<div class="mt-2 pt-2 border-top small">';
                    html += '<strong>Tools Used:</strong><ul class="mb-0">';
                    for (var t = 0; t < msg.tools_used.length; t++) {
                        var tool = msg.tools_used[t];
                        html += '<li>' + tool.name + '</li>';
                    }
                    html += '</ul></div>';
                }

                html += '</div></div></div>';
            }

            html += '</div>';
            $('#sessionContent').html(html);
        },
        error: function(xhr, status, error) {
            console.log('Error response:', xhr.responseText);
            $('#sessionContent').html('<div class="alert alert-danger">Error loading session: ' + error + '<br>Response: ' + xhr.responseText + '</div>');
        }
    });
});
