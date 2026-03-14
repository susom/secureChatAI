// ============================================================
// ANALYTICS DASHBOARD - D3.js Visualizations
// ============================================================
// Contract: Host page must define `const logsData` before this script loads

// Model pricing (per 1M tokens)
const MODEL_PRICING = {
    'gpt-4': { prompt: 30, completion: 60 },
    'gpt-4o': { prompt: 2.5, completion: 10 },
    'gpt-4o-mini': { prompt: 0.15, completion: 0.6 },
    'gpt-4.1': { prompt: 30, completion: 60 },
    'o1': { prompt: 15, completion: 60 },
    'o3-mini': { prompt: 1.1, completion: 4.4 },
    'gpt-5': { prompt: 50, completion: 100 },
    'claude-3.5-sonnet': { prompt: 3, completion: 15 },
    'claude-3-opus': { prompt: 15, completion: 75 },
    'claude-3-haiku': { prompt: 0.25, completion: 1.25 },
    'gemini-1.5-pro': { prompt: 1.25, completion: 5 },
    'gemini-1.5-flash': { prompt: 0.075, completion: 0.3 },
    'llama-3.3-70b': { prompt: 0.35, completion: 0.4 },
    'deepseek-chat': { prompt: 0.27, completion: 1.1 },
    'ada-002': { prompt: 0.1, completion: 0 }, // embeddings
    'default': { prompt: 1, completion: 2 }
};

function calculateCost(model, promptTokens, completionTokens) {
    const pricing = MODEL_PRICING[model] || MODEL_PRICING['default'];
    const promptCost = (promptTokens / 1000000) * pricing.prompt;
    const completionCost = (completionTokens / 1000000) * pricing.completion;
    return promptCost + completionCost;
}

function initAnalyticsDashboard() {
    console.log('Initializing analytics with', logsData.length, 'logs');

    // Process logs data
    const processedData = processLogsData(logsData);

    // Update info banner
    $('#totalLogsCount').text(logsData.length.toLocaleString());

    if (logsData.length > 0) {
        const timestamps = logsData
            .filter(l => l.timestamp)
            .map(l => new Date(l.timestamp));

        if (timestamps.length > 0) {
            const minDate = new Date(Math.min(...timestamps));
            const maxDate = new Date(Math.max(...timestamps));
            $('#dataDateRange').text(
                minDate.toLocaleDateString() + ' to ' + maxDate.toLocaleDateString()
            );
        }
    }

    // Render summary cards
    renderSummaryCards(processedData);

    // Render charts
    renderTokensChart(processedData, 'hourly');
    renderModelChart(processedData);
    renderCostChart(processedData);
    renderHeatmap(processedData);
    renderAgentFlow(processedData);
}

function processLogsData(logs) {
    const now = new Date();
    const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const oneDayAgo = new Date(now.getTime() - 24 * 60 * 60 * 1000);
    const twoDaysAgo = new Date(now.getTime() - 48 * 60 * 60 * 1000);

    let totalTokensAllTime = 0;
    let totalCostAllTime = 0;
    let totalCalls = 0;
    let agentCalls = 0;

    // Trend calculation (last 24h vs previous 24h)
    let tokensLast24h = 0;
    let tokensPrev24h = 0;
    let costLast24h = 0;
    let costPrev24h = 0;
    let callsLast24h = 0;
    let callsPrev24h = 0;
    let agentCallsLast24h = 0;
    let agentCallsPrev24h = 0;

    const hourlyData = {};
    const dailyData = {};
    const modelCounts = {};
    const modelCosts = {};
    const heatmapData = Array(7).fill(0).map(() => Array(24).fill(0));
    const toolSequences = [];

    console.log('Processing logs:', logs.length);
    console.log('Start of day:', startOfDay);

    logs.forEach((log, idx) => {
        if (!log.timestamp) {
            console.warn('Log missing timestamp:', idx);
            return;
        }

        const timestamp = new Date(log.timestamp);
        const promptTokens = log.usage?.prompt_tokens || 0;
        const completionTokens = log.usage?.completion_tokens || 0;
        const totalTokens = log.usage?.total_tokens || 0;
        const model = log.model || 'unknown';
        const cost = calculateCost(model, promptTokens, completionTokens);

        totalCalls++;
        totalTokensAllTime += totalTokens;
        totalCostAllTime += cost;

        // Trend tracking (last 24h vs previous 24h)
        if (timestamp >= oneDayAgo) {
            tokensLast24h += totalTokens;
            costLast24h += cost;
            callsLast24h++;
        } else if (timestamp >= twoDaysAgo && timestamp < oneDayAgo) {
            tokensPrev24h += totalTokens;
            costPrev24h += cost;
            callsPrev24h++;
        }

        // Agent mode detection
        const hasAgentTools = log.choices?.[0]?.message?.tools_used?.length > 0;
        if (hasAgentTools) {
            agentCalls++;
            if (timestamp >= oneDayAgo) agentCallsLast24h++;
            else if (timestamp >= twoDaysAgo && timestamp < oneDayAgo) agentCallsPrev24h++;

            const tools = log.choices[0].message.tools_used;
            if (tools.length > 1) {
                toolSequences.push(tools.map(t => t.name));
            }
        }

        // Hourly aggregation
        const hourKey = timestamp.toISOString().slice(0, 13); // YYYY-MM-DDTHH
        if (!hourlyData[hourKey]) {
            hourlyData[hourKey] = { prompt: 0, completion: 0, total: 0, cost: 0, calls: 0 };
        }
        hourlyData[hourKey].prompt += promptTokens;
        hourlyData[hourKey].completion += completionTokens;
        hourlyData[hourKey].total += totalTokens;
        hourlyData[hourKey].cost += cost;
        hourlyData[hourKey].calls += 1;

        // Daily aggregation
        const dayKey = timestamp.toISOString().slice(0, 10); // YYYY-MM-DD
        if (!dailyData[dayKey]) {
            dailyData[dayKey] = { prompt: 0, completion: 0, total: 0, cost: 0, calls: 0 };
        }
        dailyData[dayKey].prompt += promptTokens;
        dailyData[dayKey].completion += completionTokens;
        dailyData[dayKey].total += totalTokens;
        dailyData[dayKey].cost += cost;
        dailyData[dayKey].calls += 1;

        // Model distribution
        modelCounts[model] = (modelCounts[model] || 0) + 1;
        modelCosts[model] = (modelCosts[model] || 0) + cost;

        // Heatmap (hour x day of week)
        const hour = timestamp.getHours();
        const day = timestamp.getDay(); // 0 = Sunday
        heatmapData[day][hour] += totalTokens;
    });

    // Calculate trends
    const tokenTrend = tokensPrev24h > 0
        ? (((tokensLast24h - tokensPrev24h) / tokensPrev24h) * 100).toFixed(1)
        : 0;
    const costTrend = costPrev24h > 0
        ? (((costLast24h - costPrev24h) / costPrev24h) * 100).toFixed(1)
        : 0;
    const callsTrend = callsPrev24h > 0
        ? (((callsLast24h - callsPrev24h) / callsPrev24h) * 100).toFixed(1)
        : 0;
    const agentPercentLast24h = callsLast24h > 0
        ? ((agentCallsLast24h / callsLast24h) * 100).toFixed(1)
        : 0;
    const agentPercentPrev24h = callsPrev24h > 0
        ? ((agentCallsPrev24h / callsPrev24h) * 100).toFixed(1)
        : 0;
    const agentTrend = agentPercentPrev24h > 0
        ? (((agentPercentLast24h - agentPercentPrev24h) / agentPercentPrev24h) * 100).toFixed(1)
        : 0;

    console.log('Processed:', {
        totalCalls,
        totalTokens: totalTokensAllTime,
        totalCost: totalCostAllTime,
        trends: { tokenTrend, costTrend, callsTrend, agentTrend },
        hourlyDataPoints: Object.keys(hourlyData).length,
        dailyDataPoints: Object.keys(dailyData).length,
        models: Object.keys(modelCounts)
    });

    return {
        totalTokens: totalTokensAllTime,
        totalCost: totalCostAllTime,
        totalCalls,
        agentCalls,
        agentPercent: totalCalls > 0 ? ((agentCalls / totalCalls) * 100).toFixed(1) : 0,
        tokenTrend,
        costTrend,
        callsTrend,
        agentTrend,
        hourlyData,
        dailyData,
        modelCounts,
        modelCosts,
        heatmapData,
        toolSequences
    };
}

function renderSummaryCards(data) {
    // Format numbers nicely
    const tokensDisplay = data.totalTokens >= 1000000
        ? (data.totalTokens / 1000000).toFixed(2) + 'M'
        : (data.totalTokens / 1000).toFixed(1) + 'K';

    const costDisplay = data.totalCost >= 1
        ? '$' + data.totalCost.toFixed(2)
        : '$' + data.totalCost.toFixed(4);

    // Animated counters (start from current value on refresh, or 0 on first load)
    const isFirstLoad = $('#totalTokens').text() === '-';
    animateNumber('#totalTokens', isFirstLoad ? 0 : null, data.totalTokens, tokensDisplay, isFirstLoad ? 1500 : 800);
    animateNumber('#totalCost', isFirstLoad ? 0 : null, data.totalCost, costDisplay, isFirstLoad ? 1500 : 800);
    animateNumber('#totalCalls', isFirstLoad ? 0 : null, data.totalCalls, data.totalCalls.toLocaleString(), isFirstLoad ? 1500 : 800);
    animateNumber('#agentPercent', isFirstLoad ? 0 : null, parseFloat(data.agentPercent), data.agentPercent + '%', isFirstLoad ? 1500 : 800);

    // Add trend arrows
    const trendArrow = (trend) => {
        const val = parseFloat(trend);
        if (val > 0) return `<span class="trend-arrow trend-up">&#8599; +${Math.abs(val)}%</span>`;
        if (val < 0) return `<span class="trend-arrow trend-down">&#8600; ${val}%</span>`;
        return '<span class="trend-arrow">&rarr; 0%</span>';
    };

    $('#tokenTrend').html('All time ' + trendArrow(data.tokenTrend));
    $('#costTrend').html(Object.keys(data.modelCounts).length + ' models ' + trendArrow(data.costTrend));
    $('#callsTrend').html(data.agentCalls + ' agent ' + trendArrow(data.callsTrend));
    $('#agentTrend').html('of total ' + trendArrow(data.agentTrend));

    // Sparklines
    renderSparkline('#tokenSparkline', data.hourlyData, 'total');
    renderSparkline('#costSparkline', data.hourlyData, 'cost');
    renderSparkline('#callsSparkline', data.hourlyData, 'calls');
    renderSparkline('#agentSparkline', data.hourlyData, 'calls'); // Same shape as calls for now
}

function renderSparkline(selector, hourlyData, field) {
    const svg = d3.select(selector);
    const width = svg.node().parentElement.clientWidth;
    const height = 40;

    svg.attr('viewBox', `0 0 ${width} ${height}`);

    const sortedData = Object.entries(hourlyData)
        .sort((a, b) => a[0].localeCompare(b[0]))
        .slice(-24) // Last 24 hours
        .map(([key, val]) => val[field]);

    if (sortedData.length === 0 || sortedData.every(d => d === 0)) {
        // Show flat line if no data
        svg.selectAll('*').remove();
        svg.append('line')
            .attr('x1', 0)
            .attr('x2', width)
            .attr('y1', height / 2)
            .attr('y2', height / 2)
            .attr('stroke', 'rgba(255,255,255,0.3)')
            .attr('stroke-width', 1)
            .attr('stroke-dasharray', '3,3');
        return;
    }

    const maxVal = d3.max(sortedData);
    const minVal = d3.min(sortedData);

    const x = d3.scaleLinear()
        .domain([0, sortedData.length - 1])
        .range([0, width]);

    const y = d3.scaleLinear()
        .domain([minVal * 0.9, maxVal * 1.1]) // Add padding
        .range([height - 2, 2]);

    const line = d3.line()
        .x((d, i) => x(i))
        .y(d => y(d))
        .curve(d3.curveMonotoneX);

    svg.selectAll('*').remove();

    // Add area fill
    const area = d3.area()
        .x((d, i) => x(i))
        .y0(height - 2)
        .y1(d => y(d))
        .curve(d3.curveMonotoneX);

    svg.append('path')
        .datum(sortedData)
        .attr('fill', 'rgba(255,255,255,0.2)')
        .attr('d', area);

    svg.append('path')
        .datum(sortedData)
        .attr('fill', 'none')
        .attr('stroke', 'rgba(255,255,255,0.9)')
        .attr('stroke-width', 2)
        .attr('d', line);
}

let currentTimeRange = 'hourly'; // Track current view

function renderTokensChart(data, timeRange) {
    if (timeRange === undefined) timeRange = 'hourly';
    currentTimeRange = timeRange;

    const svg = d3.select('#tokensChart');
    const container = svg.node().parentElement;
    const width = container.clientWidth;
    const height = 350;
    const margin = { top: 20, right: 30, bottom: 50, left: 60 };

    svg.attr('viewBox', `0 0 ${width} ${height}`);
    svg.selectAll('*').remove();

    const g = svg.append('g')
        .attr('transform', `translate(${margin.left},${margin.top})`);

    const innerWidth = width - margin.left - margin.right;
    const innerHeight = height - margin.top - margin.bottom;

    // Select data based on time range
    let currentData;
    let timeFormat;

    if (timeRange === 'hourly') {
        currentData = Object.entries(data.hourlyData)
            .sort((a, b) => a[0].localeCompare(b[0]))
            .slice(-48) // Last 48 hours
            .map(([key, val]) => ({
                time: new Date(key + ':00'), // Ensure proper date parsing
                prompt: val.prompt,
                completion: val.completion,
                total: val.total
            }));
        timeFormat = d3.timeFormat('%m/%d %H:%M');
    } else if (timeRange === 'daily') {
        currentData = Object.entries(data.dailyData)
            .sort((a, b) => a[0].localeCompare(b[0]))
            .slice(-30) // Last 30 days
            .map(([key, val]) => ({
                time: new Date(key),
                prompt: val.prompt,
                completion: val.completion,
                total: val.total
            }));
        timeFormat = d3.timeFormat('%m/%d');
    } else { // weekly
        // Aggregate daily data into weeks
        const weeklyData = {};
        Object.entries(data.dailyData)
            .sort((a, b) => a[0].localeCompare(b[0]))
            .forEach(([key, val]) => {
                const date = new Date(key);
                const weekStart = new Date(date);
                weekStart.setDate(date.getDate() - date.getDay()); // Start of week (Sunday)
                const weekKey = weekStart.toISOString().slice(0, 10);

                if (!weeklyData[weekKey]) {
                    weeklyData[weekKey] = { prompt: 0, completion: 0, total: 0 };
                }
                weeklyData[weekKey].prompt += val.prompt;
                weeklyData[weekKey].completion += val.completion;
                weeklyData[weekKey].total += val.total;
            });

        currentData = Object.entries(weeklyData)
            .sort((a, b) => a[0].localeCompare(b[0]))
            .slice(-12) // Last 12 weeks
            .map(([key, val]) => ({
                time: new Date(key),
                prompt: val.prompt,
                completion: val.completion,
                total: val.total
            }));
        timeFormat = d3.timeFormat('%m/%d');
    }

    console.log('Token chart data points:', currentData.length, 'timeRange:', timeRange);

    if (currentData.length === 0) {
        g.append('text')
            .attr('x', innerWidth / 2)
            .attr('y', innerHeight / 2)
            .attr('text-anchor', 'middle')
            .style('fill', '#999')
            .text('No token data available');
        return;
    }

    const x = d3.scaleTime()
        .domain(d3.extent(currentData, d => d.time))
        .range([0, innerWidth]);

    const y = d3.scaleLinear()
        .domain([0, d3.max(currentData, d => d.total)])
        .nice()
        .range([innerHeight, 0]);

    // Add axes
    const tickCount = timeRange === 'hourly' ? 8 : timeRange === 'daily' ? 10 : 6;
    g.append('g')
        .attr('transform', `translate(0,${innerHeight})`)
        .call(d3.axisBottom(x)
            .ticks(tickCount)
            .tickFormat(timeFormat)
        )
        .selectAll('text')
        .attr('transform', 'rotate(-35)')
        .style('text-anchor', 'end')
        .style('font-size', '11px')
        .attr('dx', '-0.5em')
        .attr('dy', '0.5em');

    g.append('g')
        .call(d3.axisLeft(y).tickFormat(d => {
            if (d >= 1000) return (d / 1000) + 'K';
            return d;
        }));

    // Add lines
    const linePrompt = d3.line()
        .x(d => x(d.time))
        .y(d => y(d.prompt))
        .curve(d3.curveMonotoneX);

    const lineCompletion = d3.line()
        .x(d => x(d.time))
        .y(d => y(d.completion))
        .curve(d3.curveMonotoneX);

    const lineTotal = d3.line()
        .x(d => x(d.time))
        .y(d => y(d.total))
        .curve(d3.curveMonotoneX);

    g.append('path')
        .datum(currentData)
        .attr('fill', 'none')
        .attr('stroke', '#667eea')
        .attr('stroke-width', 2)
        .attr('d', linePrompt);

    g.append('path')
        .datum(currentData)
        .attr('fill', 'none')
        .attr('stroke', '#11998e')
        .attr('stroke-width', 2)
        .attr('d', lineCompletion);

    g.append('path')
        .datum(currentData)
        .attr('fill', 'none')
        .attr('stroke', '#f5576c')
        .attr('stroke-width', 3)
        .attr('d', lineTotal);

    // Add interactive dots
    const tooltip = d3.select('#d3-tooltip');

    g.selectAll('.dot')
        .data(currentData)
        .enter().append('circle')
        .attr('class', 'dot')
        .attr('cx', d => x(d.time))
        .attr('cy', d => y(d.total))
        .attr('r', 4)
        .attr('fill', '#f5576c')
        .style('cursor', 'pointer')
        .on('mouseover', function(event, d) {
            tooltip.style('opacity', 1)
                .html(`
                    <strong>${d.time.toLocaleString()}</strong><br>
                    Prompt: ${d.prompt.toLocaleString()}<br>
                    Completion: ${d.completion.toLocaleString()}<br>
                    <strong>Total: ${d.total.toLocaleString()}</strong>
                `)
                .style('left', (event.pageX + 10) + 'px')
                .style('top', (event.pageY - 10) + 'px');
        })
        .on('mouseout', function() {
            tooltip.style('opacity', 0);
        });
}

function renderModelChart(data) {
    const svg = d3.select('#modelChart');
    const container = svg.node().parentElement;
    const width = container.clientWidth;
    const height = 350;

    svg.attr('viewBox', `0 0 ${width} ${height}`);
    svg.selectAll('*').remove();

    const radius = Math.min(width, height) / 2 - 40;
    const g = svg.append('g')
        .attr('transform', `translate(${width/2},${height/2})`);

    const color = d3.scaleOrdinal()
        .range(['#667eea', '#764ba2', '#f093fb', '#f5576c', '#11998e', '#38ef7d', '#4facfe', '#00f2fe']);

    const pie = d3.pie()
        .value(d => d.value)
        .sort(null);

    const arc = d3.arc()
        .innerRadius(radius * 0.6)
        .outerRadius(radius);

    const data_array = Object.entries(data.modelCounts).map(([key, value]) => ({
        name: key,
        value: value
    }));

    const tooltip = d3.select('#d3-tooltip');

    g.selectAll('path')
        .data(pie(data_array))
        .enter().append('path')
        .attr('d', arc)
        .attr('fill', (d, i) => color(i))
        .attr('stroke', 'white')
        .attr('stroke-width', 2)
        .style('cursor', 'pointer')
        .on('mouseover', function(event, d) {
            d3.select(this).transition()
                .duration(200)
                .attr('d', d3.arc().innerRadius(radius * 0.6).outerRadius(radius * 1.1));

            const percent = ((d.data.value / data.totalCalls) * 100).toFixed(1);
            tooltip.style('opacity', 1)
                .html(`
                    <strong>${d.data.name}</strong><br>
                    Calls: ${d.data.value}<br>
                    ${percent}% of total
                `)
                .style('left', (event.pageX + 10) + 'px')
                .style('top', (event.pageY - 10) + 'px');
        })
        .on('mouseout', function() {
            d3.select(this).transition()
                .duration(200)
                .attr('d', arc);
            tooltip.style('opacity', 0);
        });

    // Center text
    g.append('text')
        .attr('text-anchor', 'middle')
        .attr('dy', '-0.5em')
        .style('font-size', '2em')
        .style('font-weight', 'bold')
        .text(data.totalCalls);

    g.append('text')
        .attr('text-anchor', 'middle')
        .attr('dy', '1.5em')
        .style('font-size', '0.9em')
        .style('fill', '#666')
        .text('total calls');

    // Add legend
    const legend = svg.append('g')
        .attr('transform', `translate(10, ${height - 80})`);

    data_array.slice(0, 5).forEach((d, i) => {
        const legendRow = legend.append('g')
            .attr('transform', `translate(0, ${i * 16})`);

        legendRow.append('rect')
            .attr('width', 12)
            .attr('height', 12)
            .attr('fill', color(i));

        legendRow.append('text')
            .attr('x', 18)
            .attr('y', 10)
            .style('font-size', '11px')
            .text(d.name.length > 20 ? d.name.substring(0, 18) + '...' : d.name);
    });
}

function renderCostChart(data) {
    const svg = d3.select('#costChart');
    const container = svg.node().parentElement;
    const width = container.clientWidth;
    const height = 300;
    const margin = { top: 20, right: 100, bottom: 50, left: 60 };

    svg.attr('viewBox', `0 0 ${width} ${height}`);
    svg.selectAll('*').remove();

    const g = svg.append('g')
        .attr('transform', `translate(${margin.left},${margin.top})`);

    const innerWidth = width - margin.left - margin.right;
    const innerHeight = height - margin.top - margin.bottom;

    // Group costs by day and model
    const dailyCosts = {};
    Object.entries(data.dailyData).slice(-7).forEach(([day, dayData]) => {
        dailyCosts[day] = {};
    });

    // Aggregate by model (simplified for visualization)
    const topModels = Object.entries(data.modelCosts)
        .sort((a, b) => b[1] - a[1])
        .slice(0, 5)
        .map(([model]) => model);

    const modelData = topModels.map(model => ({
        model: model,
        cost: data.modelCosts[model]
    }));

    const x = d3.scaleBand()
        .domain(modelData.map(d => d.model))
        .range([0, innerWidth])
        .padding(0.3);

    const y = d3.scaleLinear()
        .domain([0, d3.max(modelData, d => d.cost)])
        .nice()
        .range([innerHeight, 0]);

    const color = d3.scaleOrdinal()
        .domain(topModels)
        .range(['#667eea', '#11998e', '#f5576c', '#4facfe', '#f093fb']);

    // Add axes with better formatting
    g.append('g')
        .attr('transform', `translate(0,${innerHeight})`)
        .call(d3.axisBottom(x))
        .selectAll('text')
        .attr('transform', 'rotate(-35)')
        .style('text-anchor', 'end')
        .style('font-size', '11px')
        .attr('dx', '-0.5em')
        .attr('dy', '0.5em');

    g.append('g')
        .call(d3.axisLeft(y).tickFormat(d => {
            if (d >= 1) return '$' + d.toFixed(2);
            if (d >= 0.01) return '$' + d.toFixed(4);
            return '$' + d.toExponential(2);
        }));

    // Add bars
    const tooltip = d3.select('#d3-tooltip');

    g.selectAll('.bar')
        .data(modelData)
        .enter().append('rect')
        .attr('class', 'bar')
        .attr('x', d => x(d.model))
        .attr('y', d => y(d.cost))
        .attr('width', x.bandwidth())
        .attr('height', d => innerHeight - y(d.cost))
        .attr('fill', d => color(d.model))
        .style('cursor', 'pointer')
        .on('mouseover', function(event, d) {
            d3.select(this).attr('opacity', 0.7);
            tooltip.style('opacity', 1)
                .html(`
                    <strong>${d.model}</strong><br>
                    Total Cost: $${d.cost.toFixed(4)}
                `)
                .style('left', (event.pageX + 10) + 'px')
                .style('top', (event.pageY - 10) + 'px');
        })
        .on('mouseout', function() {
            d3.select(this).attr('opacity', 1);
            tooltip.style('opacity', 0);
        });
}

function renderHeatmap(data) {
    const svg = d3.select('#heatmapChart');
    const container = svg.node().parentElement;
    const width = container.clientWidth;
    const height = 300;
    const margin = { top: 20, right: 20, bottom: 40, left: 60 };

    svg.attr('viewBox', `0 0 ${width} ${height}`);
    svg.selectAll('*').remove();

    const g = svg.append('g')
        .attr('transform', `translate(${margin.left},${margin.top})`);

    const innerWidth = width - margin.left - margin.right;
    const innerHeight = height - margin.top - margin.bottom;

    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const hours = Array.from({length: 24}, (_, i) => i);

    const cellWidth = innerWidth / 24;
    const cellHeight = innerHeight / 7;

    const maxValue = d3.max(data.heatmapData.flat());

    const colorScale = d3.scaleSequential()
        .domain([0, maxValue])
        .interpolator(d3.interpolateBlues);

    const tooltip = d3.select('#d3-tooltip');

    // Render cells
    data.heatmapData.forEach((dayData, dayIdx) => {
        dayData.forEach((value, hourIdx) => {
            g.append('rect')
                .attr('x', hourIdx * cellWidth)
                .attr('y', dayIdx * cellHeight)
                .attr('width', cellWidth - 1)
                .attr('height', cellHeight - 1)
                .attr('fill', value > 0 ? colorScale(value) : '#f0f0f0')
                .attr('stroke', 'white')
                .style('cursor', 'pointer')
                .on('mouseover', function(event) {
                    tooltip.style('opacity', 1)
                        .html(`
                            <strong>${days[dayIdx]} ${hourIdx}:00</strong><br>
                            Tokens: ${value.toLocaleString()}
                        `)
                        .style('left', (event.pageX + 10) + 'px')
                        .style('top', (event.pageY - 10) + 'px');
                })
                .on('mouseout', function() {
                    tooltip.style('opacity', 0);
                });
        });
    });

    // Add day labels
    days.forEach((day, i) => {
        g.append('text')
            .attr('x', -10)
            .attr('y', i * cellHeight + cellHeight / 2)
            .attr('text-anchor', 'end')
            .attr('dominant-baseline', 'middle')
            .style('font-size', '0.8em')
            .text(day);
    });

    // Add hour labels (every 3 hours)
    for (let i = 0; i < 24; i += 3) {
        g.append('text')
            .attr('x', i * cellWidth + cellWidth / 2)
            .attr('y', innerHeight + 20)
            .attr('text-anchor', 'middle')
            .style('font-size', '0.8em')
            .text(i);
    }
}

function renderAgentFlow(data) {
    const svg = d3.select('#flowChart');
    const container = svg.node().parentElement;
    const width = container.clientWidth;
    const height = 300;

    svg.attr('viewBox', `0 0 ${width} ${height}`);
    svg.selectAll('*').remove();

    if (data.toolSequences.length === 0) {
        svg.append('text')
            .attr('x', width / 2)
            .attr('y', height / 2)
            .attr('text-anchor', 'middle')
            .style('fill', '#999')
            .text('No agent tool sequences found');
        return;
    }

    // Count tool transitions
    const transitions = {};
    data.toolSequences.forEach(sequence => {
        for (let i = 0; i < sequence.length - 1; i++) {
            const key = `${sequence[i]}\u2192${sequence[i+1]}`;
            transitions[key] = (transitions[key] || 0) + 1;
        }
    });

    // Get unique tools
    const allTools = [...new Set(data.toolSequences.flat())];

    // Simple visualization: list top transitions
    const topTransitions = Object.entries(transitions)
        .sort((a, b) => b[1] - a[1])
        .slice(0, 10);

    const g = svg.append('g')
        .attr('transform', 'translate(20,30)');

    topTransitions.forEach((transition, i) => {
        const [flow, count] = transition;

        g.append('text')
            .attr('x', 0)
            .attr('y', i * 25)
            .style('font-size', '0.85em')
            .text(`${flow} (${count}x)`);

        // Bar visualization
        const barWidth = (count / topTransitions[0][1]) * (width - 200);
        g.append('rect')
            .attr('x', 180)
            .attr('y', i * 25 - 12)
            .attr('width', barWidth)
            .attr('height', 15)
            .attr('fill', '#667eea')
            .attr('opacity', 0.6);
    });
}

// ============================================================
// FANCY FEATURES
// ============================================================

// Animated number counter
function animateNumber(selector, start, end, displayText, duration) {
    const element = $(selector);
    const startTime = Date.now();

    // If start is null/undefined, extract current value from element
    let startValue = start;
    if (startValue === null || startValue === undefined) {
        const currentText = element.text().replace(/[^0-9.-]/g, '');
        startValue = parseFloat(currentText) || 0;

        // Adjust for K/M suffixes
        if (element.text().includes('K')) startValue *= 1000;
        if (element.text().includes('M')) startValue *= 1000000;
    }

    const endValue = parseFloat(end) || 0;

    function update() {
        const now = Date.now();
        const elapsed = now - startTime;
        const progress = Math.min(elapsed / duration, 1);

        // Easing function (ease out cubic)
        const eased = 1 - Math.pow(1 - progress, 3);
        const current = startValue + (endValue - startValue) * eased;

        if (progress < 1) {
            // Show intermediate value
            if (displayText.includes('K')) {
                element.text((current / 1000).toFixed(1) + 'K');
            } else if (displayText.includes('M')) {
                element.text((current / 1000000).toFixed(2) + 'M');
            } else if (displayText.includes('$')) {
                element.text('$' + current.toFixed(displayText.includes('.') ? 4 : 2));
            } else if (displayText.includes('%')) {
                element.text(current.toFixed(1) + '%');
            } else {
                element.text(Math.floor(current).toLocaleString());
            }
            requestAnimationFrame(update);
        } else {
            // Show final value
            element.text(displayText);
        }
    }

    update();
}

// Download chart as PNG
function downloadChart(chartId) {
    const svg = document.getElementById(chartId);
    if (!svg) return;

    const svgData = new XMLSerializer().serializeToString(svg);
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');

    const img = new Image();
    const svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
    const url = URL.createObjectURL(svgBlob);

    img.onload = function() {
        canvas.width = svg.clientWidth * 2; // 2x for retina
        canvas.height = svg.clientHeight * 2;
        ctx.fillStyle = 'white';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

        canvas.toBlob(function(blob) {
            const a = document.createElement('a');
            a.download = chartId + '-' + new Date().toISOString().slice(0,10) + '.png';
            a.href = URL.createObjectURL(blob);
            a.click();
        });

        URL.revokeObjectURL(url);
    };

    img.src = url;
}

// Toggle fullscreen for chart
function toggleFullscreen(button) {
    const container = button.closest('.chart-container');

    if (!document.fullscreenElement) {
        container.requestFullscreen().catch(err => {
            console.error('Fullscreen error:', err);
        });
    } else {
        document.exitFullscreen();
    }
}

// Add pulse animation to new data
function pulseElement(selector) {
    $(selector).css({
        animation: 'none'
    }).offset(); // Force reflow
    $(selector).css({
        animation: 'pulse 0.5s ease-in-out'
    });
}

// AJAX data refresh function (SILKY SMOOTH - NO PAGE RELOAD!)
function refreshDataAjax() {
    // Pulse the live indicator
    $('#liveIndicator .live-dot').css('animation', 'none').offset();
    $('#liveIndicator .live-dot').css('animation', 'pulse-dot 0.5s ease-in-out');

    // Build URL with current filters
    // Use dedicated AJAX endpoint if available (project page), otherwise same page (admin page)
    let url;
    if (window.USAGE_AJAX_URL) {
        const params = new URLSearchParams(new URL(window.USAGE_AJAX_URL, window.location.origin).search);
        // Carry over filter params from current page URL
        const pageParams = new URLSearchParams(window.location.search);
        if (pageParams.has('dateStart')) params.set('dateStart', pageParams.get('dateStart'));
        if (pageParams.has('dateEnd')) params.set('dateEnd', pageParams.get('dateEnd'));
        if (pageParams.has('limit')) params.set('limit', pageParams.get('limit'));
        params.set('format', 'json');
        url = window.USAGE_AJAX_URL.split('?')[0] + '?' + params.toString();
    } else {
        const params = new URLSearchParams(window.location.search);
        params.set('format', 'json');
        url = window.location.pathname + '?' + params.toString();
    }

    // Fetch new data
    fetch(url)
        .then(response => response.json())
        .then(data => {
            console.log('Refreshed data:', data);

            // Update logsData globally
            window.logsData = data.logs;

            // Reprocess analytics
            const processedData = processLogsData(data.logs);

            // Update summary cards smoothly
            updateSummaryCardsSmooth(processedData);

            // Update info banner
            $('#totalLogsCount').text(data.logs.length.toLocaleString());

            // Update charts smoothly
            updateChartsSmooth(processedData);

            // Show success toast briefly
            showToast('Updated', 'success');
        })
        .catch(error => {
            console.error('Refresh error:', error);
            showToast('Update failed', 'error');
        });
}

// Update summary cards with smooth transitions
function updateSummaryCardsSmooth(data) {
    const tokensDisplay = data.totalTokens >= 1000000
        ? (data.totalTokens / 1000000).toFixed(2) + 'M'
        : (data.totalTokens / 1000).toFixed(1) + 'K';

    const costDisplay = data.totalCost >= 1
        ? '$' + data.totalCost.toFixed(2)
        : '$' + data.totalCost.toFixed(4);

    // Animate from current values (not 0!)
    animateNumber('#totalTokens', null, data.totalTokens, tokensDisplay, 600);
    animateNumber('#totalCost', null, data.totalCost, costDisplay, 600);
    animateNumber('#totalCalls', null, data.totalCalls, data.totalCalls.toLocaleString(), 600);
    animateNumber('#agentPercent', null, parseFloat(data.agentPercent), data.agentPercent + '%', 600);

    // Update trend text (with smooth fade)
    const trendArrow = (trend) => {
        const val = parseFloat(trend);
        if (val > 0) return `<span class="trend-arrow trend-up">&#8599; +${Math.abs(val)}%</span>`;
        if (val < 0) return `<span class="trend-arrow trend-down">&#8600; ${val}%</span>`;
        return '<span class="trend-arrow">&rarr; 0%</span>';
    };

    $('#tokenTrend').fadeOut(200, function() {
        $(this).html('All time ' + trendArrow(data.tokenTrend)).fadeIn(200);
    });
    $('#costTrend').fadeOut(200, function() {
        $(this).html(Object.keys(data.modelCounts).length + ' models ' + trendArrow(data.costTrend)).fadeIn(200);
    });
    $('#callsTrend').fadeOut(200, function() {
        $(this).html(data.agentCalls + ' agent ' + trendArrow(data.callsTrend)).fadeIn(200);
    });
    $('#agentTrend').fadeOut(200, function() {
        $(this).html('of total ' + trendArrow(data.agentTrend)).fadeIn(200);
    });

    // Update sparklines
    renderSparkline('#tokenSparkline', data.hourlyData, 'total');
    renderSparkline('#costSparkline', data.hourlyData, 'cost');
    renderSparkline('#callsSparkline', data.hourlyData, 'calls');
    renderSparkline('#agentSparkline', data.hourlyData, 'calls');
}

// Update charts smoothly (re-render with transition)
function updateChartsSmooth(data) {
    // Fade charts slightly during update
    $('.chart-container svg').css('opacity', '0.7');

    setTimeout(() => {
        renderTokensChart(data, currentTimeRange); // Preserve selected time range
        renderModelChart(data);
        renderCostChart(data);
        renderHeatmap(data);
        renderAgentFlow(data);

        $('.chart-container svg').css('opacity', '1');
    }, 200);
}

// Toast notification system
function showToast(message, type) {
    if (type === undefined) type = 'success';
    const bgColor = type === 'success' ? 'rgba(40, 167, 69, 0.95)' : 'rgba(220, 53, 69, 0.95)';
    const toast = $('<div class="refresh-toast"></div>')
        .css('background', bgColor)
        .html(message)
        .appendTo('body');

    toast.fadeIn(200);

    setTimeout(() => {
        toast.fadeOut(300, function() {
            $(this).remove();
        });
    }, 2000);
}

// Smooth number updates (call with null start to animate from current value)
function updateNumberSmooth(selector, newValue, displayText) {
    animateNumber(selector, null, newValue, displayText, 1000);
}

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
