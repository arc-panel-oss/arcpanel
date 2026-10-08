// apRrdGraph: server graphs from `v-export-rrd` (port of rrdCharts.js).
// chart.js is loaded on demand as a separate chunk.
import { request } from '../http.js';
import { __ } from '../i18n.js';

export const PERIODS = ['daily', 'weekly', 'monthly', 'yearly', 'biennially', 'triennially'];

/** toLocaleString() options of the x axis labels per period, as rrdCharts.js shows them. */
export function labelFormat(period) {
	switch (period) {
		case 'daily':
			return { hour: '2-digit', minute: '2-digit' };
		case 'weekly':
			return { weekday: 'short', day: 'numeric' };
		case 'monthly':
			return { month: 'short', day: 'numeric' };
		case 'yearly':
			return { month: 'long' };
		default:
			return { month: 'short', year: 'numeric' };
	}
}

/** Chart.js data from the JSON of GET url?service=&period=. */
export function chartData(rrd, colours) {
	const meta = rrd?.meta ?? { start: 0, step: 0, legend: [] };
	const rows = Array.isArray(rrd?.data) ? rrd.data : [];
	return {
		labels: rows.map((_, index) => new Date((meta.start + index * meta.step) * 1000)),
		datasets: (meta.legend ?? []).map((legend, legendIndex) => ({
			label: legend,
			data: rows.map((row) => row[legendIndex]),
			tension: 0.3,
			pointStyle: false,
			borderWidth: 2,
			borderColor: colours[legendIndex % colours.length],
		})),
	};
}

/**
 * The load graph: v-update-sys-rrd-la stores the load average times 100, and
 * the process count would flatten it on a shared axis. The load is scaled
 * back and the other series get their own axis on the right.
 */
export function loadAverage(data) {
	const [load, ...rest] = data.datasets;
	if (!load) {
		return data;
	}
	const scaled = (value) => (value === null || value === undefined || value === '' ? value : Number(value) / 100);
	return {
		...data,
		datasets: [{ ...load, data: load.data.map(scaled), yAxisID: 'y' }, ...rest.map((dataset) => ({ ...dataset, yAxisID: 'y1' }))],
	};
}

function cssVar(name) {
	return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

export default function apRrdGraph({ url = '', service = 'la', period = 'daily' } = {}) {
	return {
		url,
		service,
		period,
		loading: false,
		error: '',
		chart: null,

		async init() {
			await this.load();
		},

		destroy() {
			this.chart?.destroy();
			this.chart = null;
		},

		async setPeriod(value) {
			if (!PERIODS.includes(value) || value === this.period) {
				return;
			}
			this.period = value;
			await this.load();
		},

		async load() {
			this.loading = true;
			this.error = '';
			try {
				const rrd = await request(
					`${this.url}?service=${encodeURIComponent(this.service)}&period=${encodeURIComponent(this.period)}`,
				);
				const { Chart } = await import('chart.js/auto');
				const series = chartData(rrd, [cssVar('--ap-accent'), cssVar('--ap-ok'), cssVar('--ap-warn'), cssVar('--ap-info')]);
				const isLoad = this.service === 'la' && series.datasets.length > 1;
				const data = isLoad ? loadAverage(series) : series;
				const text = cssVar('--ap-fg-muted');
				const grid = cssVar('--ap-border');
				const labels = labelFormat(this.period);
				this.chart?.destroy();
				this.chart = new Chart(this.$refs.canvas, {
					type: 'line',
					data: { ...data, labels: data.labels.map((date) => date.toLocaleString([], labels)) },
					options: {
						animation: false,
						maintainAspectRatio: false,
						plugins: { legend: { position: 'bottom', labels: { color: text } } },
						scales: {
							x: { ticks: { color: text, maxTicksLimit: 8 }, grid: { color: grid } },
							y: {
								title: isLoad
									? { display: true, text: __('Load Average'), color: text }
									: { display: Boolean(rrd?.unit), text: rrd?.unit ?? '', color: text },
								ticks: { color: text },
								grid: { color: grid },
							},
							...(isLoad
								? {
										y1: {
											position: 'right',
											title: { display: true, text: data.datasets[1].label, color: text },
											ticks: { color: text, precision: 0 },
											grid: { drawOnChartArea: false },
										},
									}
								: {}),
						},
					},
				});
			} catch (error) {
				this.error = error.message;
			} finally {
				this.loading = false;
			}
		},
	};
}
