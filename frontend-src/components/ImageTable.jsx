const { useState, useEffect } = wp.element;
const { Button, Spinner, SelectControl } = wp.components;
const { __ } = wp.i18n;

import { api } from '../api';
import { sizeFormat, numberFormat, dateFormat } from '../format';
import { StatusBadge, RiskBadge, Pagination } from './common';
import ReferenceModal from './ReferenceModal';

/**
 * Shared image index table with filters, pagination and bulk actions.
 *
 * props: lockedStatus (optional, hides the status filter), showReferenceActions
 */
export default function ImageTable( { lockedStatus } ) {
	const [ items, setItems ] = useState( null );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState( lockedStatus || 'all' );
	const [ compressed, setCompressed ] = useState( 'all' );
	const [ search, setSearch ] = useState( '' );
	const [ selected, setSelected ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ refImage, setRefImage ] = useState( null );

	const perPage = 50;

	const load = async ( opts = {} ) => {
		setBusy( true );
		try {
			const data = await api.images( {
				status,
				compressed,
				search,
				page,
				per_page: perPage,
				...opts,
			} );
			setItems( data.items );
			setTotal( data.total );
			setTotalPages( data.total_pages );
			setSelected( [] );
		} catch ( e ) {
			setNotice( { type: 'error', text: e.message } );
		} finally {
			setBusy( false );
		}
	};

	// Reload when filters/page change.
	useEffect( () => {
		load();
	}, [ status, compressed, page ] );

	const withBusy = async ( fn, okText ) => {
		setBusy( true );
		setNotice( null );
		try {
			const result = await fn();
			if ( okText ) setNotice( { type: 'success', text: okText } );
			return result;
		} catch ( e ) {
			setNotice( { type: 'error', text: e.message } );
		} finally {
			setBusy( false );
			load();
		}
	};

	const compressOne = ( row ) =>
		withBusy(
			() => api.compressOne( row.id ),
			__( 'Image compressed.', 'mediasweep' )
		);

	const restoreOne = ( row ) =>
		withBusy(
			() => api.restore( row.id ),
			__( 'Original restored.', 'mediasweep' )
		);

	const analyzeOne = ( row ) =>
		withBusy(
			() => api.analyze( row.id ),
			__( 'References analyzed.', 'mediasweep' )
		);

	const trashSelected = () => {
		if ( ! selected.length ) return;
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Move the selected images to the MediaSweep trash? You can restore them for 30 days.', 'mediasweep' ) ) ) {
			return;
		}
		return withBusy(
			() => api.trash( selected ),
			__( 'Moved to trash.', 'mediasweep' )
		);
	};

	const compressSelected = () => {
		if ( ! selected.length ) return;
		return withBusy(
			() => api.compressIds( selected ),
			__( 'Compression task queued.', 'mediasweep' )
		);
	};

	const toggleAll = () => {
		if ( ! items ) return;
		if ( selected.length === items.length ) {
			setSelected( [] );
		} else {
			setSelected( items.map( ( row ) => row.id ) );
		}
	};

	const toggleOne = ( id ) => {
		setSelected( ( prev ) =>
			prev.includes( id ) ? prev.filter( ( x ) => x !== id ) : [ ...prev, id ]
		);
	};

	return (
		<div className="msw-images">
			<div className="msw-toolbar">
				{ ! lockedStatus && (
					<SelectControl
						value={ status }
						onChange={ ( v ) => { setPage( 1 ); setStatus( v ); } }
						options={ [
							{ value: 'all', label: __( 'All statuses', 'mediasweep' ) },
							{ value: 'used', label: __( 'In use', 'mediasweep' ) },
							{ value: 'maybe', label: __( 'Maybe used', 'mediasweep' ) },
							{ value: 'unused', label: __( 'No references', 'mediasweep' ) },
							{ value: 'orphan', label: __( 'Orphan files', 'mediasweep' ) },
						] }
					/>
				) }
				<SelectControl
					value={ compressed }
					onChange={ ( v ) => { setPage( 1 ); setCompressed( v ); } }
					options={ [
						{ value: 'all', label: __( 'All compression states', 'mediasweep' ) },
						{ value: '0', label: __( 'Not compressed', 'mediasweep' ) },
						{ value: '1', label: __( 'Compressed', 'mediasweep' ) },
					] }
				/>
				<input
					type="search"
					className="msw-search"
					placeholder={ __( 'Search file name…', 'mediasweep' ) }
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' ) {
							setPage( 1 );
							load( { search: e.target.value, page: 1 } );
						}
					} }
				/>
				<Button variant="primary" onClick={ () => { setPage( 1 ); load( { search, page: 1 } ); } }>
					{ __( 'Search', 'mediasweep' ) }
				</Button>
			</div>

			{ notice && (
				<div className={ 'msw-notice msw-notice-' + notice.type }>{ notice.text }</div>
			) }

			{ selected.length > 0 && (
				<div className="msw-bulkbar">
					<span>
						{ __( 'Selected', 'mediasweep' ) }: { numberFormat( selected.length ) }
					</span>
					<Button variant="secondary" onClick={ compressSelected } disabled={ busy }>
						{ __( 'Compress selected', 'mediasweep' ) }
					</Button>
					<Button variant="secondary" isDestructive onClick={ trashSelected } disabled={ busy }>
						{ __( 'Move to trash', 'mediasweep' ) }
					</Button>
				</div>
			) }

			{ busy && ! items && <Spinner /> }

			{ items && items.length === 0 && (
				<p className="msw-empty">
					{ __( 'No images match. Run a media scan from the Dashboard first.', 'mediasweep' ) }
				</p>
			) }

			{ items && items.length > 0 && (
				<table className="msw-table wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td className="msw-col-check">
								<input type="checkbox" checked={ selected.length === items.length } onChange={ toggleAll } />
							</td>
							<th className="msw-col-thumb">{ __( 'Preview', 'mediasweep' ) }</th>
							<th>{ __( 'File', 'mediasweep' ) }</th>
							<th className="msw-col-num">{ __( 'Size', 'mediasweep' ) }</th>
							<th>{ __( 'Compression', 'mediasweep' ) }</th>
							<th>{ __( 'References', 'mediasweep' ) }</th>
							<th>{ __( 'Actions', 'mediasweep' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( row ) => (
							<tr key={ row.id }>
								<td className="msw-col-check">
									<input
										type="checkbox"
										checked={ selected.includes( row.id ) }
										onChange={ () => toggleOne( row.id ) }
									/>
								</td>
								<td className="msw-col-thumb">
									{ row.thumbnail_url ? (
										<img src={ row.thumbnail_url } alt="" className="msw-thumb" loading="lazy" />
									) : (
										<span className="msw-thumb msw-thumb-empty">{ row.mime_type }</span>
									) }
								</td>
								<td>
									<strong>{ row.file_name }</strong>
									<div className="msw-file-meta">
										{ row.file_rel_path }
										<br />
										{ row.width || row.height
											? `${ row.width }×${ row.height } · `
											: '' }
										{ dateFormat( row.updated_at ) }
									</div>
								</td>
								<td className="msw-col-num">{ sizeFormat( row.file_size ) }</td>
								<td>
									{ row.compressed ? (
										<span className="msw-saved">
											{ __( 'Compressed', 'mediasweep' ) } · { row.compression_ratio }%
											<br />
											<span className="msw-file-meta">
												{ sizeFormat( row.original_size ) } → { sizeFormat( row.compressed_size ) }
											</span>
										</span>
									) : (
										<span className="msw-muted">{ __( 'Not compressed', 'mediasweep' ) }</span>
									) }
								</td>
								<td>
									<StatusBadge status={ row.reference_status } />
									{ row.reference_count > 0 && (
										<div className="msw-file-meta">{ row.reference_count }×</div>
									) }
									<div className="msw-risk">
										<RiskBadge level={ row.risk_level } />
									</div>
								</td>
								<td>
									<div className="msw-row-actions">
										{ ! row.compressed && (
											<Button size="small" onClick={ () => compressOne( row ) } disabled={ busy }>
												{ __( 'Compress', 'mediasweep' ) }
											</Button>
										) }
										{ row.compressed && (
											<Button size="small" onClick={ () => restoreOne( row ) } disabled={ busy }>
												{ __( 'Restore', 'mediasweep' ) }
											</Button>
										) }
										<Button size="small" onClick={ () => setRefImage( row ) }>
											{ __( 'References', 'mediasweep' ) }
										</Button>
										<Button size="small" onClick={ () => analyzeOne( row ) } disabled={ busy }>
											{ __( 'Analyze', 'mediasweep' ) }
										</Button>
									</div>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<div className="msw-table-footer">
				<span className="msw-muted">
					{ numberFormat( total ) } { __( 'images', 'mediasweep' ) }
				</span>
				<Pagination page={ page } totalPages={ totalPages } onPage={ setPage } />
			</div>

			{ refImage && (
				<ReferenceModal
					image={ refImage }
					onClose={ () => setRefImage( null ) }
					onAnalyzed={ () => load() }
				/>
			) }
		</div>
	);
}
