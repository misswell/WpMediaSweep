import { STATUS_LABELS, STATUS_COLORS, TASK_STATUS_LABELS, RISK_LABELS, RISK_COLORS } from '../format';

export function StatusBadge({ status }) {
	const label = STATUS_LABELS[status] || status;
	const color = STATUS_COLORS[status] || '#8c8f94';
	return (
		<span className="msw-badge" style={ { borderColor: color, color } }>
			<span className="msw-badge-dot" style={ { background: color } } />
			{ label }
		</span>
	);
}

export function RiskBadge({ level }) {
	if (!level) return null;
	const label = RISK_LABELS[level] || level;
	const color = RISK_COLORS[level] || '#8c8f94';
	return (
		<span className="msw-badge msw-risk-badge" style={ { borderColor: color, color } }>
			<span className="msw-badge-dot" style={ { background: color } } />
			{ label }
		</span>
	);
}

export function TaskStatusBadge({ status }) {
	const color = {
		running: '#00a32a',
		queued: '#2271b1',
		paused: '#dba617',
		completed: '#8c8f94',
		failed: '#d63638',
		cancelled: '#8c8f94',
	}[ status ] || '#8c8f94';
	return (
		<span className="msw-badge" style={ { borderColor: color, color } }>
			<span className="msw-badge-dot" style={ { background: color } } />
			{ TASK_STATUS_LABELS[ status ] || status }
		</span>
	);
}

export function StatCard({ label, value, sub, accent }) {
	return (
		<div className="msw-stat-card">
			<div className="msw-stat-value" style={ accent ? { color: accent } : undefined }>{ value }</div>
			<div className="msw-stat-label">{ label }</div>
			{ sub ? <div className="msw-stat-sub">{ sub }</div> : null }
		</div>
	);
}

export function ProgressBar({ processed, total, failed }) {
	const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
	return (
		<div className="msw-progress">
			<div className="msw-progress-bar" style={ { width: pct + '%' } } />
			<span className="msw-progress-text">
				{ processed } / { total > 0 ? total : '?' }
				{ failed > 0 ? ` (${ failed } failed)` : '' }
			</span>
		</div>
	);
}

export function Pagination({ page, totalPages, onPage }) {
	if (totalPages <= 1) return null;
	const { Button } = wp.components;
	return (
		<div className="msw-pagination">
			<Button
				variant="secondary"
				size="small"
				disabled={ page <= 1 }
				onClick={ () => onPage(page - 1) }
			>
				‹
			</Button>
			<span className="msw-pagination-info">
				{ page } / { totalPages }
			</span>
			<Button
				variant="secondary"
				size="small"
				disabled={ page >= totalPages }
				onClick={ () => onPage(page + 1) }
			>
				›
			</Button>
		</div>
	);
}
