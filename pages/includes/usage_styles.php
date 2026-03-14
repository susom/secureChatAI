<style>
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        padding: 0;
    }
    html, body {
        font-size: 0.9rem;
    }
    table.dataTable tbody tr {
        height: 24px;
    }
    .accordion-header {
        padding: 0.2rem 0.5rem;
    }
    .accordion-body {
        padding: 0.2rem 0.5rem;
        max-height: 200px;
        overflow-y: auto;
        white-space: pre-wrap;
    }
    .accordion-button {
        padding: 0.2rem 0.5rem;
        font-size: 0.8rem;
    }
    .table td, .table th {
        word-wrap: break-word;
    }
    .query-column, .response-column, .tools-column {
        width: 300px;
        max-width: 300px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .id-column, .project-id-column, .session-id-column {
        width: auto;
    }
    .accordion-collapse {
        width: 100%;
        overflow: hidden;
    }
    .table td {
        word-wrap: break-word;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .tool-step {
        margin-bottom: 0.5rem;
        padding: 0.3rem;
        background: #f8f9fa;
        border-left: 3px solid #007bff;
        font-size: 0.85rem;
    }
    .agent-steps {
        font-size: 0.85rem;
    }
    .filters-panel {
        background: #f8f9fa;
        padding: 1rem;
        margin-bottom: 1rem;
        border-radius: 0.5rem;
    }
    .session-id-column {
        cursor: pointer;
        color: #0d6efd;
        text-decoration: underline;
    }
    .session-id-column:hover {
        color: #0a58ca;
    }
    .controls-panel {
        display: flex;
        gap: 1rem;
        align-items: center;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }
    .dataTables_length {
        float: left;
    }
    .dataTables_filter {
        float: right;
        text-align: right;
    }
    .dataTables_wrapper .row {
        margin-bottom: 1rem;
    }
    .auto-refresh-indicator {
        color: #28a745;
        font-weight: bold;
    }

    /* Analytics Dashboard Styles */
    .nav-tabs {
        border-bottom: 2px solid #dee2e6;
        margin-bottom: 2rem;
    }
    .nav-tabs .nav-link {
        border: none;
        color: #6c757d;
        font-weight: 500;
        padding: 0.75rem 1.5rem;
        transition: all 0.3s;
    }
    .nav-tabs .nav-link:hover {
        color: #007bff;
        border-bottom: 2px solid #007bff;
    }
    .nav-tabs .nav-link.active {
        color: #007bff;
        background: transparent;
        border-bottom: 2px solid #007bff;
    }
    .analytics-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 1rem;
        padding: 1.5rem;
        color: white;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        animation: cardPulse 3s ease-in-out infinite;
    }
    .analytics-card::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        opacity: 0;
        transition: opacity 0.5s;
    }
    .analytics-card:hover::before {
        opacity: 1;
        animation: shimmer 2s infinite;
    }
    .analytics-card:hover {
        transform: translateY(-8px) scale(1.02);
        box-shadow: 0 12px 24px rgba(0,0,0,0.3);
    }
    .analytics-card.green {
        background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    }
    .analytics-card.orange {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    }
    .analytics-card.blue {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    }
    @keyframes cardPulse {
        0%, 100% { box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        50% { box-shadow: 0 6px 12px rgba(0,0,0,0.15); }
    }
    @keyframes shimmer {
        0% { transform: translate(-50%, -50%) rotate(0deg); }
        100% { transform: translate(-50%, -50%) rotate(360deg); }
    }
    .analytics-card h3 {
        font-size: 0.9rem;
        opacity: 0.9;
        margin-bottom: 0.5rem;
        font-weight: 500;
    }
    .analytics-card .big-number {
        font-size: 2.5rem;
        font-weight: bold;
        margin-bottom: 0.25rem;
    }
    .analytics-card .trend {
        font-size: 0.85rem;
        opacity: 0.9;
    }
    .chart-container {
        background: white;
        border-radius: 1rem;
        padding: 1.5rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        margin-bottom: 2rem;
        position: relative;
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
    }
    .chart-container:hover {
        box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }
    /* Make main chart row containers match height */
    .row .col-md-8 .chart-container,
    .row .col-md-4 .chart-container {
        min-height: 480px;
    }
    .chart-actions {
        position: absolute;
        top: 1rem;
        right: 1rem;
        display: flex;
        gap: 0.5rem;
        opacity: 0;
        transition: opacity 0.3s;
    }
    .chart-container:hover .chart-actions {
        opacity: 1;
    }
    .chart-btn {
        background: rgba(0,0,0,0.05);
        border: none;
        border-radius: 0.5rem;
        padding: 0.5rem;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 1.2rem;
    }
    .chart-btn:hover {
        background: rgba(0,0,0,0.1);
        transform: scale(1.1);
    }
    .trend-arrow {
        display: inline-block;
        margin-left: 0.5rem;
        font-size: 1.2em;
    }
    .trend-up { color: #38ef7d; }
    .trend-down { color: #f5576c; }
    .projection-badge {
        position: absolute;
        top: 1rem;
        right: 1rem;
        background: rgba(255,255,255,0.2);
        padding: 0.25rem 0.5rem;
        border-radius: 0.5rem;
        font-size: 0.75rem;
        font-weight: 500;
        backdrop-filter: blur(10px);
    }
    .loading-shimmer {
        animation: shimmerLoading 2s infinite;
    }
    @keyframes shimmerLoading {
        0% { opacity: 0.5; }
        50% { opacity: 1; }
        100% { opacity: 0.5; }
    }
    .chart-title {
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: #333;
    }
    .chart-subtitle {
        font-size: 0.85rem;
        color: #6c757d;
        margin-bottom: 1.5rem;
    }
    #tokensChart, #modelChart, #costChart, #heatmapChart, #flowChart {
        width: 100%;
    }
    .sparkline {
        height: 40px;
        margin-top: 0.5rem;
    }
    .legend-item {
        display: inline-flex;
        align-items: center;
        margin-right: 1.5rem;
        font-size: 0.85rem;
    }
    .legend-color {
        width: 16px;
        height: 16px;
        border-radius: 3px;
        margin-right: 0.5rem;
    }
    .time-range-toggle {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }
    .time-range-toggle .btn {
        font-size: 0.85rem;
        padding: 0.25rem 0.75rem;
    }
    .tooltip-d3 {
        position: absolute;
        background: rgba(0,0,0,0.8);
        color: white;
        padding: 0.5rem 0.75rem;
        border-radius: 0.5rem;
        font-size: 0.85rem;
        pointer-events: none;
        z-index: 1000;
        opacity: 0;
        transition: opacity 0.2s;
    }
    .sankey-node {
        cursor: pointer;
    }
    .sankey-link {
        fill: none;
        stroke: #ccc;
        stroke-opacity: 0.5;
    }
    .sankey-link:hover {
        stroke-opacity: 0.8;
    }

    /* Live Indicator */
    .live-indicator {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(40, 167, 69, 0.1);
        padding: 0.5rem 1rem;
        border-radius: 2rem;
        border: 2px solid #28a745;
    }
    .live-dot {
        width: 10px;
        height: 10px;
        background: #28a745;
        border-radius: 50%;
        animation: pulse-dot 2s infinite;
        box-shadow: 0 0 10px #28a745;
    }
    .live-text {
        font-weight: 700;
        color: #28a745;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
    }
    @keyframes pulse-dot {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.3); opacity: 0.7; }
    }

    /* Dark Mode */
    body.dark-mode {
        background: #1a1a2e;
        color: #eee;
    }
    body.dark-mode .chart-container {
        background: #16213e;
        color: #eee;
    }
    body.dark-mode .chart-title {
        color: #eee;
    }
    body.dark-mode .chart-subtitle {
        color: #aaa;
    }
    body.dark-mode .alert-info {
        background: #16213e;
        color: #eee;
        border-color: #0f3460;
    }
    body.dark-mode .nav-tabs .nav-link {
        color: #aaa;
    }
    body.dark-mode .nav-tabs .nav-link.active {
        color: #4facfe;
        background: #16213e;
    }
    body.dark-mode .table {
        color: #eee;
        background: #16213e;
    }
    body.dark-mode .table-striped tbody tr:nth-of-type(odd) {
        background: rgba(255,255,255,0.02);
    }
    body.dark-mode .form-select,
    body.dark-mode .form-control {
        background: #16213e;
        color: #eee;
        border-color: #0f3460;
    }
    body.dark-mode .btn-outline-secondary {
        color: #eee;
        border-color: #0f3460;
    }
    body.dark-mode .btn-outline-secondary:hover {
        background: #0f3460;
        color: #fff;
    }
    body.dark-mode .accordion-button {
        background: #16213e;
        color: #eee;
    }

    /* Theme System */
    .analytics-card.theme-ocean { background: linear-gradient(135deg, #2E3192 0%, #1BFFFF 100%); }
    .analytics-card.theme-ocean.green { background: linear-gradient(135deg, #134E5E 0%, #71B280 100%); }
    .analytics-card.theme-ocean.orange { background: linear-gradient(135deg, #EE9CA7 0%, #FFDDE1 100%); }
    .analytics-card.theme-ocean.blue { background: linear-gradient(135deg, #06beb6 0%, #48b1bf 100%); }

    .analytics-card.theme-sunset { background: linear-gradient(135deg, #FF6B6B 0%, #FFE66D 100%); }
    .analytics-card.theme-sunset.green { background: linear-gradient(135deg, #F8B500 0%, #FF6B6B 100%); }
    .analytics-card.theme-sunset.orange { background: linear-gradient(135deg, #FF9A56 0%, #FF6B9D 100%); }
    .analytics-card.theme-sunset.blue { background: linear-gradient(135deg, #4FACFE 0%, #F093FB 100%); }

    .analytics-card.theme-forest { background: linear-gradient(135deg, #134E5E 0%, #71B280 100%); }
    .analytics-card.theme-forest.green { background: linear-gradient(135deg, #0F2027 0%, #2C5364 100%); }
    .analytics-card.theme-forest.orange { background: linear-gradient(135deg, #C02425 0%, #F0CB35 100%); }
    .analytics-card.theme-forest.blue { background: linear-gradient(135deg, #1D976C 0%, #93F9B9 100%); }

    .analytics-card.theme-neon { background: linear-gradient(135deg, #B226E1 0%, #D100D1 100%); }
    .analytics-card.theme-neon.green { background: linear-gradient(135deg, #00F260 0%, #0575E6 100%); }
    .analytics-card.theme-neon.orange { background: linear-gradient(135deg, #FA8BFF 0%, #2BD2FF 100%); }
    .analytics-card.theme-neon.blue { background: linear-gradient(135deg, #4E65FF 0%, #92EFFD 100%); }

    .analytics-card.theme-monochrome { background: linear-gradient(135deg, #2C3E50 0%, #4CA1AF 100%); }
    .analytics-card.theme-monochrome.green { background: linear-gradient(135deg, #373B44 0%, #4286f4 100%); }
    .analytics-card.theme-monochrome.orange { background: linear-gradient(135deg, #556270 0%, #FF6B6B 100%); }
    .analytics-card.theme-monochrome.blue { background: linear-gradient(135deg, #283048 0%, #859398 100%); }

    .dashboard-controls {
        animation: slideIn 0.5s ease-out;
    }
    @keyframes slideIn {
        from { opacity: 0; transform: translateX(20px); }
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    .refresh-toast {
        position: fixed;
        top: 80px;
        right: 20px;
        background: rgba(40, 167, 69, 0.95);
        color: white;
        padding: 0.75rem 1.5rem;
        border-radius: 0.5rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        z-index: 9999;
        font-weight: 500;
        backdrop-filter: blur(10px);
    }
    /* Demo mode styling */
    .alert-info:has(.badge.bg-warning) {
        border: 3px dashed #ffc107;
        background: linear-gradient(135deg, #fff3cd 0%, #fffaeb 100%);
        animation: demoPulse 3s ease-in-out infinite;
    }
    @keyframes demoPulse {
        0%, 100% { border-color: #ffc107; }
        50% { border-color: #ff9800; }
    }
</style>
