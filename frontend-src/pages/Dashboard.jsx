const { useState, useEffect, useRef } = wp.element;
const { Button, Spinner } = wp.components;
const { __ } = wp.i18n;

import { api } from '../api';
import { sizeFormat, numberFormat, dateFormat } from '../format';
import { StatCard } from '../components/common';

export default function Dashboard( { go } ) {
	const [ stats, setStats ] = useState( null );
	const [ busy, setBusy ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ logs, setLogs ] = useState( [] );

	const load = async () => {
		try {
			const [ s, l ] = await Promise.all( [ api.stats(), api.logs( 12 ) ] );
			setStats( s );
			setLogs( l.logs || [] );
		} catch ( e ) {
			setNotice( { type: 'error', text: e.message } );
		}
	};

	useEffect( () => {
		load();
		const timer = setInterval( load, 15000 );
		return () => clearInterval( timer );
	}, [] );

	const run = async ( key, fn, okText ) => {
		setBusy( key );
		setNotice( null );
		try {
			await fn();
			setNotice( { type: 'success', text: okText } );
		} catch ( e ) {
			setNotice( { type: 'error', text: e.message } );
		} finally {
			setBusy( null );
			load();
		}
	};

	if ( ! stats ) {
		return <Spinner />;
	}

	const backendLabel = stats.backends.imagick
		? 'Imagick' + ( stats.backends.gd ? ' + GD' : '' )
		: stats.backends.gd
			? 'GD'
			: __( 'None available', 'mediasweep' );

	return (
		<div className="msw-dashboard">
			{ notice && <div className={ 'msw-notice msw-notice-' + notice.type }>{ notice.text }</div> }

			<div className="msw-stats-grid">
				<StatCard label={ __( 'Images', 'mediasweep' ) } value={ numberFormat( stats.total_files ) } sub={ sizeFormat( stats.total_size ) } />
				<StatCard label={ __( 'Compressed', 'mediasweep' ) } value={ numberFormat( stats.compressed ) } accent="#00a32a"
					sub={ stats.saved > 0 ? sizeFormat( stats.saved ) + ' ' + __( 'saved', 'mediasweep' ) : null } />
				<StatCard label={ __( 'Not optimized', 'mediasweep' ) } value={ numberFormat( stats.pending ) } accent="#dba617" />
				<StatCard label={ __( 'No references', 'mediasweep' ) } value={ numberFormat( stats.unused + stats.orphan ) } accent="#d63638"
					sub={ stats.maybe_used > 0 ? numberFormat( stats.maybe_used ) + ' ' + __( 'maybe used', 'mediasweep' ) : null } />
				<StatCard label={ __( 'Releasable space', 'mediasweep' ) } value={ sizeFormat( stats.releasable ) } accent="#d63638" />
				<StatCard label={ __( 'Engine backends', 'mediasweep' ) } value={ backendLabel } sub={ __( 'local only', 'mediasweep' ) } />
			</div>

			<div className="msw-quick-actions">
				<Button
					variant="primary"
					disabled={ !! busy }
					onClick={ () => run( 'scan', api.startScan, __( 'Media scan started/running.', 'mediasweep' ) ) }
				>
					{ busy === 'scan' ? __( 'Starting…', 'mediasweep' ) : __( 'Start media scan', 'mediasweep' ) }
				</Button>
				<Button
					variant="secondary"
					disabled={ !! busy }
					onClick={ () => run( 'refs', api.startReferenceScan, __( 'Reference scan started/running.', 'mediasweep' ) ) }
				>
					{ busy === 'refs' ? __( 'Starting…', 'mediasweep' ) : __( 'Scan references', 'mediasweep' ) }
				</Button>
				<Button
					variant="secondary"
					disabled={ !! busy || stats.pending === 0 }
					onClick={ () => run( 'compress', api.compressAll, __( 'Compression task queued.', 'mediasweep' ) ) }
				>
					{ busy === 'compress' ? __( 'Queuing…', 'mediasweep' ) : __( 'Compress all unoptimized', 'mediasweep' ) }
				</Button>
				<Button variant="tertiary" onClick={ () => go( 'tasks' ) }>
					{ __( 'View tasks', 'mediasweep' ) }
				</Button>
				<Button variant="tertiary" onClick={ () => go( 'images' ) }>
					{ __( 'Browse images', 'mediasweep' ) }
				</Button>
			</div>

			<h3 className="msw-section-title">{ __( 'Recent activity', 'mediasweep' ) }</h3>
			<table className="msw-log-table">
				<tbody>
					{ logs.length === 0 && (
						<tr><td className="msw-muted">{ __( 'No activity yet.', 'mediasweep' ) }</td></tr>
					) }
					{ logs.map( ( log ) => (
						<tr key={ log.id } className={ 'msw-log-' + log.level }>
							<td className="msw-log-time">{ dateFormat( log.created_at ) }</td>
							<td className="msw-log-context">{ log.context }</td>
							<td>{ log.message }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
