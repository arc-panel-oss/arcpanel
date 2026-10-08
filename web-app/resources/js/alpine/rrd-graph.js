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
 * The load graph: v-update-sys-rrd-la stores the load average times 100 next
 * to the process count. One axis only: the load is scaled back and drawn
 * alone, the process count is told as its latest value ("Processes: 82").
 *
 * @returns {{ data: object, note: string }}
 */
export function loadAverage(data) {
	const [load, processes] = data.datasets;
	if (!load) {
		return { data, note: '' };
	}
	const missing = (value) => value === null || value === undefined || value === '' || Number.isNaN(Number(value));
	const latest = processes ? [...processes.data].reverse().find((value) => !missing(value)) : undefined;
	return {
		data: { ...data, datasets: [{ ...load, data: load.data.map((value) => (missing(value) ? null : Number(value) / 100)) }] },
		note: latest === undefined ? '' : `${processes.label}: ${Math.round(Number(latest))}`,
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
		note: '',
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
				const load = isLoad ? loadAverage(series) : null;
				const data = load ? load.data : series;
				this.note = load ? load.note : '';
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
						// One series is named by the card title: no legend box
						plugins: { legend: { display: data.datasets.length > 1, position: 'bottom', labels: { color: text } } },
						scales: {
							x: { ticks: { color: text, maxTicksLimit: 8 }, grid: { color: grid } },
							y: {
								title: isLoad
									? { display: true, text: __('Load Average'), color: text }
									: { display: Boolean(rrd?.unit), text: rrd?.unit ?? '', color: text },
								ticks: { color: text },
								grid: { color: grid },
							},
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
