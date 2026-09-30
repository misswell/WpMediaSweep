/**
 * REST client for the MediaSweep API.
 */
const { __ } = wp.i18n;

const BASE = (window.mswConfig && window.mswConfig.restUrl) || '/wp-json/mediasweep/v1';
const NONCE = (window.mswConfig && window.mswConfig.nonce) || '';

async function request(path, options = {}) {
	const res = await fetch(BASE + path, {
		...options,
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': NONCE,
			...(options.headers || {}),
		},
	});

	const data = await res.json().catch(() => null);

	if (!res.ok) {
		const message =
			(data && data.message) ||
			(res.statusText ? `${res.status} ${res.statusText}` : __('Request failed', 'mediasweep'));
		const error = new Error(message);
		error.code = data && data.code;
		throw error;
	}

	return data;
}

export const api = {
	stats: () => request('/stats'),
	images: (params) => request('/images?' + new URLSearchParams(clean(params))),
	references: (id) => request(`/images/${id}/references`),
	analyze: (id) => request(`/images/${id}/analyze`, { method: 'POST' }),
	restore: (id) => request(`/images/${id}/restore`, { method: 'POST' }),
	trash: (ids) => request('/delete', { method: 'POST', body: JSON.stringify({ ids }) }),
	trashList: () => request('/trash'),
	trashRestore: (token) => request(`/trash/${token}/restore`, { method: 'POST' }),
	startScan: () => request('/scan', { method: 'POST' }),
	startReferenceScan: () => request('/scan/references', { method: 'POST' }),
	compressOne: (id, force = false) =>
		request('/compress', { method: 'POST', body: JSON.stringify({ id, force }) }),
	compressIds: (ids) => request('/compress', { method: 'POST', body: JSON.stringify({ ids }) }),
	compressAll: () => request('/compress', { method: 'POST', body: JSON.stringify({ all: true }) }),
	tasks: (limit = 30) => request('/tasks?limit=' + limit),
	taskControl: (id, action) => request(`/tasks/${id}/${action}`, { method: 'POST' }),
	tick: () => request('/tick', { method: 'POST' }),
	settings: () => request('/settings'),
	saveSettings: (settings) =>
		request('/settings', { method: 'POST', body: JSON.stringify(settings) }),
	logs: (limit = 50) => request('/logs?limit=' + limit),
	clearLogs: () => request('/logs', { method: 'DELETE' }),
};

function clean(obj) {
	return Object.fromEntries(
		Object.entries(obj || {}).filter(([, v]) => v !== '' && v !== null && v !== undefined)
	);
}
