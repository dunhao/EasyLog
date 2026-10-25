/* ============================================================
   EasyLog Prototype · ECharts common config helpers
   ============================================================ */

const EasyLogCharts = {
  defaultOpts() {
    return {
      grid: { top: 24, left: 40, right: 16, bottom: 28 },
      textStyle: { color: '#9CA3B0', fontSize: 11, fontFamily: 'inherit' },
      tooltip: {
        trigger: 'axis',
        backgroundColor: '#1A1F2C',
        borderColor: '#2F3548',
        textStyle: { color: '#E4E7EF', fontSize: 12 }
      },
      legend: {
        textStyle: { color: '#9CA3B0', fontSize: 11 },
        icon: 'roundRect', itemWidth: 8, itemHeight: 8
      }
    };
  },

  /* Time series area chart */
  ingestTrend(dom, opts) {
    const chart = echarts.init(dom, null, { renderer: 'canvas' });
    chart.setOption(Object.assign(this.defaultOpts(), {
      tooltip: { trigger: 'axis' },
      xAxis: {
        type: 'category',
        data: opts.labels,
        axisLine: { lineStyle: { color: '#2F3548' } },
        axisLabel: { color: '#6B7180', fontSize: 10 },
        axisTick: { show: false }
      },
      yAxis: [
        { type: 'value', name: '日志/秒',
          nameTextStyle: { color: '#6B7180', fontSize: 10 },
          axisLine: { show: false },
          axisLabel: { color: '#6B7180', fontSize: 10 },
          splitLine: { lineStyle: { color: '#1A1F2C' } } }
      ],
      series: [{
        name: opts.seriesName || '写入',
        type: 'line', smooth: true,
        showSymbol: false,
        data: opts.data,
        lineStyle: { color: '#4F7CFF', width: 2 },
        itemStyle: { color: '#4F7CFF' },
        areaStyle: {
          color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
            { offset: 0, color: 'rgba(79,124,255,0.35)' },
            { offset: 1, color: 'rgba(79,124,255,0.02)' }
          ])
        }
      }]
    }));
    window.addEventListener('resize', () => chart.resize());
    return chart;
  },

  /* Multi-line chart for query QPS */
  multiLineChart(dom, opts) {
    const chart = echarts.init(dom, null, { renderer: 'canvas' });
    const colors = ['#4F7CFF', '#2EBA68', '#F5A623', '#A625FF'];
    chart.setOption(Object.assign(this.defaultOpts(), {
      legend: { data: opts.series.map(s => s.name), textStyle: { color: '#9CA3B0' }, icon: 'roundRect', itemWidth: 8, itemHeight: 8, top: 0 },
      grid: { top: 32, left: 40, right: 16, bottom: 28 },
      xAxis: {
        type: 'category',
        data: opts.labels,
        axisLine: { lineStyle: { color: '#2F3548' } },
        axisLabel: { color: '#6B7180', fontSize: 10 },
        axisTick: { show: false }
      },
      yAxis: {
        type: 'value',
        axisLine: { show: false },
        axisLabel: { color: '#6B7180', fontSize: 10 },
        splitLine: { lineStyle: { color: '#1A1F2C' } }
      },
      series: opts.series.map((s, i) => ({
        name: s.name, type: 'line', smooth: true, showSymbol: false,
        data: s.data,
        lineStyle: { color: colors[i % colors.length], width: 2 },
        itemStyle: { color: colors[i % colors.length] }
      }))
    }));
    window.addEventListener('resize', () => chart.resize());
    return chart;
  },

  /* Log level distribution pie */
  levelPie(dom, data) {
    const chart = echarts.init(dom, null, { renderer: 'canvas' });
    const palette = {
      DEBUG: '#8E96A8', INFO: '#2EBA68', WARN: '#F5A623',
      ERROR: '#F5455C', FATAL: '#A625FF', TRACE: '#5B6173'
    };
    chart.setOption({
      tooltip: {
        trigger: 'item',
        backgroundColor: '#1A1F2C', borderColor: '#2F3548',
        textStyle: { color: '#E4E7EF' }
      },
      legend: {
        orient: 'vertical', right: 0, top: 'center',
        textStyle: { color: '#9CA3B0', fontSize: 11 }
      },
      series: [{
        type: 'pie',
        radius: ['50%', '78%'],
        center: ['40%', '50%'],
        avoidLabelOverlap: false,
        itemStyle: { borderColor: '#11151F', borderWidth: 2 },
        label: { show: false },
        data: data.map(d => ({
          name: d.name, value: d.value,
          itemStyle: { color: palette[d.name] || '#4F7CFF' }
        }))
      }]
    });
    window.addEventListener('resize', () => chart.resize());
    return chart;
  },

  /* Bar chart (e.g., top topics) */
  barChart(dom, opts) {
    const chart = echarts.init(dom, null, { renderer: 'canvas' });
    chart.setOption(Object.assign(this.defaultOpts(), {
      grid: { top: 16, left: 110, right: 24, bottom: 16 },
      xAxis: {
        type: 'value',
        axisLine: { show: false },
        axisLabel: { color: '#6B7180', fontSize: 10 },
        splitLine: { lineStyle: { color: '#1A1F2C' } }
      },
      yAxis: {
        type: 'category',
        data: opts.labels,
        axisLine: { show: false },
        axisLabel: { color: '#9CA3B0', fontSize: 11 },
        axisTick: { show: false },
        inverse: true
      },
      series: [{
        type: 'bar',
        data: opts.data,
        barWidth: 12,
        itemStyle: {
          color: new echarts.graphic.LinearGradient(0, 0, 1, 0, [
            { offset: 0, color: '#4F7CFF' },
            { offset: 1, color: '#6B91FF' }
          ]),
          borderRadius: [0, 3, 3, 0]
        },
        label: { show: true, position: 'right', color: '#9CA3B0', fontSize: 10 }
      }]
    }));
    window.addEventListener('resize', () => chart.resize());
    return chart;
  },

  /* Histogram (for log search time distribution) */
  histogramChart(dom, opts) {
    const chart = echarts.init(dom, null, { renderer: 'canvas' });
    const levels = ['INFO', 'ERROR', 'WARN', 'DEBUG', 'FATAL'];
    const colors = ['#2EBA68', '#F5455C', '#F5A623', '#8E96A8', '#A625FF'];
    chart.setOption(Object.assign(this.defaultOpts(), {
      legend: { data: levels, textStyle: { color: '#9CA3B0' }, top: 0, icon: 'roundRect', itemWidth: 8, itemHeight: 8 },
      grid: { top: 36, left: 40, right: 16, bottom: 28 },
      xAxis: {
        type: 'category',
        data: opts.labels,
        axisLine: { lineStyle: { color: '#2F3548' } },
        axisLabel: { color: '#6B7180', fontSize: 10 },
        axisTick: { show: false }
      },
      yAxis: {
        type: 'log',
        axisLine: { show: false },
        axisLabel: { color: '#6B7180', fontSize: 10 },
        splitLine: { lineStyle: { color: '#1A1F2C' } }
      },
      series: levels.map((lvl, i) => ({
        name: lvl, type: 'bar', stack: 'total',
        data: opts.series[lvl] || new Array(opts.labels.length).fill(0),
        itemStyle: { color: colors[i] }
      }))
    }));
    window.addEventListener('resize', () => chart.resize());
    return chart;
  },
};
