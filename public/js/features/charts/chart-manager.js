import {basePath, fetchJson} from "../../utils";
import {resolveEchartsCallbacks} from "./echarts-callbacks";
import {loadEcharts} from "./echarts-loader";
import {serialize} from "../data-table/filter-url";

// Charts grow into place, draw on the glass (no background of their own) and get rounded bars,
// unless a chart says otherwise.
const withMotion = (options) => {
    const series = Array.isArray(options.series) ? options.series : (options.series ? [options.series] : []);
    series.forEach((serie) => {
        if ('bar' === serie.type) {
            serie.itemStyle = {borderRadius: [6, 6, 0, 0], ...(serie.itemStyle || {})};
        }
    });

    // Grid lines in ECharts' default light grey are left to the theme, so they stay faint on the dark glass.
    ['xAxis', 'yAxis'].forEach((key) => {
        const axes = Array.isArray(options[key]) ? options[key] : (options[key] ? [options[key]] : []);
        axes.forEach((axis) => {
            if (axis.splitLine?.lineStyle?.color?.toUpperCase?.() === '#E0E6F1') {
                delete axis.splitLine.lineStyle.color;
            }
        });
    });

    return {
        backgroundColor: 'transparent',
        animationDuration: 1100,
        animationEasing: 'cubicOut',
        animationDurationUpdate: 600,
        ...options,
    };
};

export default class ChartManager {
    constructor() {
        this.allCharts = [];
        this.theme = null;
        this.pendingTheme = new Set();
        this.resizeObserver = new ResizeObserver(entries => {
            for (const entry of entries) {
                const chart = echarts.getInstanceByDom(entry.target);
                if (!chart || !entry.target.offsetParent) {
                    continue;
                }
                if (this.pendingTheme.delete(chart)) {
                    chart.setTheme(this.theme);
                }
                chart.resize();
            }
        });
    }

    init(rootNode, isDarkMode) {
        this.theme = isDarkMode ? 'v5-dark' : 'v5';
        const handlers = this.getClickHandlers();
        const chartNodes = rootNode.querySelectorAll('[data-echarts-options], [data-echarts-options-url]');
        if (0 === chartNodes.length) return;

        loadEcharts().then(() => chartNodes.forEach(chartNode => {
            const chart = echarts.init(chartNode, this.theme);
            const chartOptionsUrl = chartNode.getAttribute('data-echarts-options-url');

            const loadOptions = chartOptionsUrl
                ? fetchJson(chartOptionsUrl)
                : Promise.resolve(JSON.parse(chartNode.getAttribute('data-echarts-options')));
            chart.showLoading();

            loadOptions.then(chartOptions => {
                resolveEchartsCallbacks(chartOptions);
                chart.setOption(withMotion(chartOptions));
            }).catch(error => {
                console.error('Failed to load chart data:', error);
            }).finally(() => {
                chart.hideLoading();
            });

            const clickHandlerName = chartNode.getAttribute('data-echarts-click');
            if (clickHandlerName && handlers[clickHandlerName]) {
                chart.on('click', function (params) {
                    const clickData = JSON.parse(chartNode.getAttribute('data-echarts-click-data') || '{}');
                    handlers[clickHandlerName](params, clickData);
                });
            }

            this.allCharts.push(chart);
            this.resizeObserver.observe(chartNode);
        }));
    }

    getClickHandlers() {
        return {
            handleMonthlyStatsClick: (params, clickData) => {
                if (!params || !params.dataIndex || !params.seriesName) {
                    return;
                }
                const month = (params.dataIndex + 1).toString().padStart(2, "0");

                window.location.assign(`${basePath()}/monthly-stats/${params.seriesName}-${month}`);
            },
            handleWeeklyStatsClick: (params, clickData) => {
                if (!params || !params.dataIndex) {
                    return;
                }

                const weeks = clickData.weeks;
                if (!params.dataIndex in weeks) {
                    return;
                }
                const filters = serialize({filters: {
                    "sportType": clickData.sportTypes,
                    "start-date": {"from": weeks[params.dataIndex]['from'], "to": weeks[params.dataIndex]['to']},
                }});

                window.location.assign(`${basePath()}/activities?${filters}`);
            },
            handleActivityGridChartClick: (params, clickData) => {
                if (!params || !params.value || params.value < 1) {
                    return;
                }

                const filters = serialize({filters: {
                    "start-date": {"from": params.value[0], "to": params.value[0]},
                }});

                window.location.assign(`${basePath()}/activities?${filters}`);
            },
        };
    }

    toggleDarkTheme(isDarkMode) {
        this.theme = isDarkMode ? 'v5-dark' : 'v5';

        this.allCharts.forEach(chart => {
            if (!chart.getDom().offsetParent) {
                this.pendingTheme.add(chart);
                return;
            }
            this.pendingTheme.delete(chart);
            chart.setTheme(this.theme);
        });
    }
}
