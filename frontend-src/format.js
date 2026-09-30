const { __ } = wp.i18n;

export function sizeFormat(bytes) {
	const n = Number(bytes) || 0;
	if (n < 1024) return n + ' B';
	const units = ['KB', 'MB', 'GB', 'TB'];
	let value = n / 1024;
	let unit = 0;
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024;
		unit++;
	}
	return value.toFixed(value >= 10 ? 0 : 1) + ' ' + units[unit];
}

export function numberFormat(n) {
	return (Number(n) || 0).toLocaleString();
}

export function dateFormat(mysql) {
	if (!mysql) return '—';
	const d = new Date(mysql.replace(' ', 'T') + (mysql.endsWith('Z') ? '' : 'Z'));
	if (isNaN(d.getTime())) return mysql;
	return d.toLocaleString();
}

export const STATUS_LABELS = {
	used: __('In use', 'mediasweep'),
	maybe: __('Maybe used', 'mediasweep'),
	unused: __('No references', 'mediasweep'),
	orphan: __('Orphan file', 'mediasweep'),
	unknown: __('Not analyzed', 'mediasweep'),
};

export const STATUS_COLORS = {
	used: '#00a32a',
	maybe: '#dba617',
	unused: '#d63638',
	orphan: '#8c8f94',
	unknown: '#8c8f94',
};

export const TASK_STATUS_LABELS = {
	queued: __('Queued', 'mediasweep'),
	running: __('Running', 'mediasweep'),
	paused: __('Paused', 'mediasweep'),
	completed: __('Completed', 'mediasweep'),
	failed: __('Failed', 'mediasweep'),
	cancelled: __('Cancelled', 'mediasweep'),
};

export const TASK_TYPE_LABELS = {
	scan: __('Media scan', 'mediasweep'),
	reference_scan: __('Reference scan', 'mediasweep'),
	compress: __('Compression', 'mediasweep'),
};

export const RISK_LABELS = {
	keep: __('Do not delete', 'mediasweep'),
	cautious: __('Delete with care', 'mediasweep'),
	safe: __('Safe to delete', 'mediasweep'),
	follow: __('Follows parent', 'mediasweep'),
};

export const RISK_COLORS = {
	keep: '#d63638',
	cautious: '#dba617',
	safe: '#00a32a',
	follow: '#8c8f94',
};
