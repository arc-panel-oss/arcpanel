// apRrdGraph: server graphs from `v-export-rrd` (port of rrdCharts.js).
// chart.js is loaded on demand as a separate chunk.
import { request } from '../http.js';

export const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

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
				const data = chartData(rrd, [cssVar('--ap-accent'), cssVar('--ap-ok'), cssVar('--ap-warn'), cssVar('--ap-info')]);
				const text = cssVar('--ap-fg-muted');
				const grid = cssVar('--ap-border');
				const labels = this.period === 'daily' ? { hour: '2-digit', minute: '2-digit' } : { month: 'short', day: 'numeric' };
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
								title: { display: Boolean(rrd?.unit), text: rrd?.unit ?? '', color: text },
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
