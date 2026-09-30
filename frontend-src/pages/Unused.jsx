const { useState, useEffect } = wp.element;
const { Button, Spinner } = wp.components;
const { __ } = wp.i18n;

import { api } from '../api';
import { sizeFormat, numberFormat, dateFormat } from '../format';
import ImageTable from '../components/ImageTable';

function UnusedTable() {
	return <ImageTable lockedStatus="unused" />;
}

export default function Unused() {
	const [ entries, setEntries ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const load = async () => {
		setBusy( true );
		try {
			const data = await api.trashList();
			setEntries( data.entries || [] );
		} catch ( e ) {
			setNotice( { type: 'error', text: e.message } );
		} finally {
			setBusy( false );
		}
	};

	useEffect( () => {
		load();
	}, [] );

	const restore = async ( token ) => {
		setBusy( true );
		try {
			await api.trashRestore( token );
			setNotice( { type: 'success', text: __( 'Restored from trash.', 'mediasweep' ) } );
			await load();
		} catch ( e ) {
			setNotice( { type: 'error', text: e.message } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div>
			<p className="msw-page-hint">
				{ __( 'Candidates you trashed live here (files moved to uploads/ms-trash, attachments parked in the WordPress trash). They are purged automatically after the retention period — restore anytime before that. Run a reference scan first to fill the candidates list.', 'mediasweep' ) }
			</p>

			{ notice && <div className={ 'msw-notice msw-notice-' + notice.type }>{ notice.text }</div> }

			<h3 className="msw-section-title">{ __( 'Trash', 'mediasweep' ) }</h3>

			{ busy && ! entries && <Spinner /> }

			{ entries && entries.length === 0 && (
				<p className="msw-empty">{ __( 'The trash is empty.', 'mediasweep' ) }</p>
			) }

			{ entries && entries.length > 0 && (
				<table className="msw-table wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>{ __( 'File', 'mediasweep' ) }</th>
							<th>{ __( 'Attachment', 'mediasweep' ) }</th>
							<th>{ __( 'Trashed at', 'mediasweep' ) }</th>
							<th>{ __( 'Files', 'mediasweep' ) }</th>
							<th>{ __( 'Actions', 'mediasweep' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ entries.map( ( entry ) => (
							<tr key={ entry.token }>
								<td><strong>{ entry.rel_path }</strong></td>
								<td>{ entry.attachment_id ? '#' + entry.attachment_id : __( '— (orphan)', 'mediasweep' ) }</td>
								<td>{ dateFormat( entry.trashed_at ) }</td>
								<td>{ numberFormat( ( entry.files || [] ).length ) }</td>
								<td>
									<Button size="small" variant="secondary" onClick={ () => restore( entry.token ) } disabled={ busy }>
										{ __( 'Restore', 'mediasweep' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<h3 className="msw-section-title">{ __( 'Candidates with no references', 'mediasweep' ) }</h3>
			<p className="msw-page-hint">
				{ __( 'Review each candidate (check its references first!), then move the ones you are sure about to the trash.', 'mediasweep' ) }
			</p>
			<UnusedTable />
		</div>
	);
}
