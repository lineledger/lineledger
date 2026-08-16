/**
 * Chart.js + Alpine glue for the report/dashboard charts.
 *
 * The server (App\Support\Reporting\ReportChartBuilder) hands us a keyed map of
 * "semantic" chart configs — bar / grouped-bar / stacked-bar / doughnut /
 * waterfall — already in dollars. This module owns all the Chart.js specifics:
 * mapping those semantic types to Chart.js options, theming for light/dark,
 * money formatting (mirroring App\Support\Money), and high-resolution,
 * light-themed image capture for PNG / PDF / print export.
 *
 * One persistent Alpine component (`chartPanel`) owns the selection, open/closed
 * state, and the Chart instance — all in a single scope so reactivity is robust
 * across Livewire re-renders. The live <canvas> carries wire:ignore so morphs
 * never wipe it; fresh data arrives through a "feeder" element whose wire:key is
 * bound to a hash of the data (it re-runs setCharts() on every data change). See
 * resources/views/components/reports/chart-panel.blade.php.
 */
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    Legend,
    LinearScale,
    Tooltip,
} from 'chart.js';

Chart.register(
    BarController,
    BarElement,
    DoughnutController,
    ArcElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
);

// Brand-led categorical palette (doughnut slices, grouped datasets).
const PALETTE = ['#1D9E75', '#2563EB', '#F59E0B', '#8B5CF6', '#EC4899', '#14B8A6', '#EF4444', '#64748B', '#0EA5E9', '#D97706'];
const POSITIVE = '#1D9E75'; // increase
const NEGATIVE = '#EF4444'; // decrease
const TOTAL = '#2563EB';    // running-total bar (neutral)

function isDark() {
    return document.documentElement.classList.contains('dark');
}

/**
 * Theme colours. Exports always use the light, print-friendly palette so a chart
 * captured in dark mode still lands as dark text on white in the PDF/PNG.
 */
function themeColors(forExport) {
    if (forExport) {
        return { text: '#1f2937', grid: '#e5e7eb', surface: '#ffffff' };
    }

    return isDark()
        ? { text: '#e5e7eb', grid: 'rgba(255,255,255,0.10)', surface: '#18181b' }
        : { text: '#374151', grid: 'rgba(17,24,39,0.08)', surface: '#ffffff' };
}

/** Money formatters: full precision for tooltips, abbreviated for axis ticks. */
function makeFormatter(symbol, decimals) {
    const sym = symbol || '';
    const dp = Number.isInteger(decimals) ? decimals : 2;

    return {
        full: (v) => {
            const n = Number(v);
            if (!Number.isFinite(n)) {
                return '';
            }
            return sym + n.toLocaleString(undefined, { minimumFractionDigits: dp, maximumFractionDigits: dp });
        },
        axis: (v) => {
            const n = Number(v);
            if (!Number.isFinite(n)) {
                return '';
            }
            const a = Math.abs(n);
            if (a >= 1_000_000) {
                const m = n / 1_000_000;
                return sym + (Number.isInteger(m) ? m.toFixed(0) : m.toFixed(1)) + 'M';
            }
            if (a >= 1_000) {
                const k = n / 1_000;
                return sym + (Number.isInteger(k) ? k.toFixed(0) : k.toFixed(1)) + 'K';
            }
            return sym + n.toFixed(0);
        },
    };
}

/** Paints a white background behind the chart — only used for exports. */
const whiteBackgroundPlugin = {
    id: 'whiteBackground',
    beforeDraw(chart) {
        const { ctx } = chart;
        ctx.save();
        ctx.globalCompositeOperation = 'destination-over';
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, chart.width, chart.height);
        ctx.restore();
    },
};

/**
 * Translate one semantic config into a Chart.js config object.
 */
function buildChartConfig(cfg, colors, animate) {
    const fmt = makeFormatter(cfg.symbol, cfg.decimals);

    const base = {
        responsive: true,
        maintainAspectRatio: false,
        animation: animate ? { duration: 400 } : false,
        plugins: {
            legend: { display: false, labels: { color: colors.text, usePointStyle: true, boxWidth: 8 } },
            tooltip: {},
        },
    };

    if (cfg.type === 'doughnut') {
        return {
            type: 'doughnut',
            data: {
                labels: cfg.labels,
                datasets: cfg.datasets.map((ds) => ({
                    label: ds.label,
                    data: ds.data,
                    backgroundColor: cfg.labels.map((_, i) => PALETTE[i % PALETTE.length]),
                    borderColor: colors.surface,
                    borderWidth: 2,
                })),
            },
            options: {
                ...base,
                cutout: '58%',
                plugins: {
                    legend: { display: true, position: 'right', labels: { color: colors.text, usePointStyle: true, boxWidth: 8 } },
                    tooltip: { callbacks: { label: (c) => ` ${c.label}: ${fmt.full(c.parsed)}` } },
                },
            },
        };
    }

    if (cfg.type === 'waterfall') {
        const ds = cfg.datasets[0];
        const colorsArr = (ds.kinds || []).map((kind, i) => {
            if (kind === 'increase') {
                return POSITIVE;
            }
            if (kind === 'decrease') {
                return NEGATIVE;
            }
            return (ds.deltas && ds.deltas[i] < 0) ? NEGATIVE : TOTAL;
        });

        return {
            type: 'bar',
            data: {
                labels: cfg.labels,
                datasets: [{
                    label: ds.label,
                    data: ds.data,
                    backgroundColor: colorsArr,
                    borderWidth: 0,
                    borderRadius: 3,
                    maxBarThickness: 72,
                }],
            },
            options: {
                ...base,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (c) => ` ${fmt.full(ds.deltas ? ds.deltas[c.dataIndex] : c.parsed.y)}` } },
                },
                scales: {
                    x: { ticks: { color: colors.text }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: colors.text, callback: (v) => fmt.axis(v) }, grid: { color: colors.grid } },
                },
            },
        };
    }

    // bar / grouped-bar / stacked-bar
    const stacked = cfg.type === 'stacked-bar' || cfg.stacked === true;
    const multi = cfg.datasets.length > 1;

    return {
        type: 'bar',
        data: {
            labels: cfg.labels,
            datasets: cfg.datasets.map((ds, i) => ({
                label: ds.label,
                data: ds.data,
                backgroundColor: ds.perPointColors || ds.color || PALETTE[i % PALETTE.length],
                borderWidth: 0,
                borderRadius: 4,
                maxBarThickness: 72,
            })),
        },
        options: {
            ...base,
            plugins: {
                legend: { display: multi, position: 'top', labels: { color: colors.text, usePointStyle: true, boxWidth: 8 } },
                tooltip: { callbacks: { label: (c) => ` ${multi ? c.dataset.label + ': ' : ''}${fmt.full(c.parsed.y)}` } },
            },
            scales: {
                x: { stacked, ticks: { color: colors.text }, grid: { display: false } },
                y: { stacked, beginAtZero: true, ticks: { color: colors.text, callback: (v) => fmt.axis(v) }, grid: { color: colors.grid } },
            },
        },
    };
}

/**
 * Render a config to a high-resolution, light-themed PNG data URL via a
 * throwaway off-screen chart, so exports are crisp and never inherit dark-mode
 * colours. Returns null on failure.
 */
function captureChartPng(cfg, scale) {
    const width = 1100;
    const height = 620;
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    canvas.style.position = 'fixed';
    canvas.style.left = '-99999px';
    canvas.style.top = '0';
    canvas.style.width = `${width}px`;
    canvas.style.height = `${height}px`;
    document.body.appendChild(canvas);

    let url = null;
    let chart = null;

    try {
        const built = buildChartConfig(cfg, themeColors(true), false);
        built.options.responsive = false;
        built.options.maintainAspectRatio = false;
        built.options.devicePixelRatio = scale;
        built.plugins = [whiteBackgroundPlugin];

        chart = new Chart(canvas.getContext('2d'), built);
        chart.update('none');
        url = canvas.toDataURL('image/png');
    } catch (e) {
        url = null;
    } finally {
        chart?.destroy();
        canvas.remove();
    }

    return url;
}

function slugify(s) {
    return (s || '')
        .toString()
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

document.addEventListener('alpine:init', () => {
    /**
     * Owns one report/dashboard chart panel: the keyed series, which one is
     * selected, the open/closed state, and the Chart instance — all in a single
     * Alpine scope so selection changes reliably redraw (no cross-scope
     * reactivity to break across Livewire morphs). x-data passes only STATIC
     * config (open + labels); the volatile series, period, and title arrive via
     * setCharts(), called from a wire:key'd feeder element on every data change.
     * Keeping volatile data out of x-data is what stops a Livewire morph from
     * re-initializing this component (and resetting open/selection) on each
     * date-range change. See chart-panel.blade.php.
     */
    window.Alpine.data('chartPanel', (opts = {}) => ({
        charts: {},
        open: opts.open === true,
        selected: '',
        title: '',
        period: '',
        labels: opts.labels || { hide: 'Hide', show: 'Show' },
        chart: null,
        themeObserver: null,

        init() {
            this.selected = Object.keys(this.charts)[0] || '';
            this.$watch('selected', () => this.redraw());
            this.$watch('open', () => this.redraw());

            // Re-theme the live chart when the user toggles light/dark.
            this.themeObserver = new MutationObserver(() => this.redraw());
            this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

            this.$nextTick(() => this.redraw());
        },

        destroy() {
            this.themeObserver?.disconnect();
            this.chart?.destroy();
            this.chart = null;
        },

        /** Replace the series + period + title (called by the feeder on changes). */
        setCharts(next, period, title) {
            this.charts = next || {};
            if (period !== undefined && period !== null) {
                this.period = period;
            }
            if (title !== undefined && title !== null) {
                this.title = title;
            }
            if (!this.charts[this.selected]) {
                this.selected = Object.keys(this.charts)[0] || '';
            }
            this.redraw();
        },

        select(key) {
            this.selected = key;
        },

        isActive(key) {
            return (this.selected || Object.keys(this.charts)[0]) === key;
        },

        hasCharts() {
            return Object.keys(this.charts).length > 0;
        },

        manyCharts() {
            return Object.keys(this.charts).length > 1;
        },

        redraw() {
            if (!this.open) {
                return;
            }
            const cfg = this.charts[this.selected];
            const canvas = this.$refs.canvas;
            if (!cfg || !canvas) {
                this.chart?.destroy();
                this.chart = null;
                return;
            }

            // Always recreate: reassigning chart.data wholesale on an existing
            // instance doesn't reliably refresh Chart.js (a doughnut→doughnut
            // switch kept the old slices), and a fresh instance restores clean
            // hover/tooltip handlers. These charts are small + switched rarely.
            this.chart?.destroy();
            this.chart = new Chart(canvas.getContext('2d'), buildChartConfig(cfg, themeColors(false), true));
        },

        /** High-res PNG of the current chart, downscaling once if it's too large. */
        captureImage() {
            const cfg = this.charts[this.selected];
            if (!cfg) {
                return null;
            }
            let url = captureChartPng(cfg, 2);
            if (url && url.length > 900_000) {
                url = captureChartPng(cfg, 1) || url;
            }
            return url;
        },

        downloadPng() {
            const url = this.captureImage();
            if (!url) {
                return;
            }
            const a = document.createElement('a');
            a.href = url;
            a.download = `${slugify(this.title) || 'chart'}.png`;
            document.body.appendChild(a);
            a.click();
            a.remove();
        },

        async exportPdf() {
            const url = this.captureImage();
            if (!url) {
                return;
            }
            await this.$wire.exportChartPdf(url, this.title || '', this.period || '');
        },

        /**
         * Print the chart via a throwaway window holding the high-res image.
         * Client-side (no server round trip) so the native print dialog opens
         * immediately — Livewire would otherwise force a file download.
         */
        printChart() {
            const url = this.captureImage();
            if (!url) {
                return;
            }
            const win = window.open('', '_blank');
            if (!win) {
                return;
            }
            const esc = (s) => String(s || '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
            win.document.write(
                '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + esc(this.title || 'Chart') + '</title>'
                + '<style>body{font-family:ui-sans-serif,system-ui,sans-serif;margin:24px;color:#1f2937}'
                + 'h1{font-size:18px;margin:0 0 2px}.period{color:#6b7280;font-size:12px;margin:0 0 16px}'
                + 'img{width:100%;max-width:960px;height:auto}@media print{body{margin:0}}</style></head><body>'
                + (this.title ? '<h1>' + esc(this.title) + '</h1>' : '')
                + (this.period ? '<div class="period">' + esc(this.period) + '</div>' : '')
                + '<img src="' + url + '" onload="window.focus();window.print();">'
                + '</body></html>',
            );
            win.document.close();
        },
    }));
});
