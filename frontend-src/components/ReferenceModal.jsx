const { useState, useEffect } = wp.element;
const { Modal, Spinner } = wp.components;
const { __ } = wp.i18n;

import { api } from '../api';
import { dateFormat } from '../format';

const TYPE_LABELS = {
	post_content: __( 'Post content', 'mediasweep' ),
	post_content_maybe: __( 'Post content (name match)', 'mediasweep' ),
	gutenberg: __( 'Gutenberg block', 'mediasweep' ),
	featured: __( 'Featured image', 'mediasweep' ),
	woocommerce: __( 'WooCommerce', 'mediasweep' ),
	elementor: __( 'Elementor', 'mediasweep' ),
	theme: __( 'Theme file', 'mediasweep' ),
	plugin: __( 'Plugin file', 'mediasweep' ),
};

export default function ReferenceModal({ image, onClose, onAnalyzed }) {
	const [ refs, setRefs ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	const load = async ( analyze ) => {
		setLoading( true );
		setError( null );
		try {
			let data;
			if ( analyze ) {
				data = await api.analyze( image.id );
				if ( onAnalyzed ) onAnalyzed( data );
			} else {
				data = await api.references( image.id );
			}
			setRefs( data.references || [] );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		load( false );
	}, [ image.id ] );

	return (
		<Modal
			title={ __( 'References: ', 'mediasweep' ) + ( image.file_name || '' ) }
			onRequestClose={ onClose }
			className="msw-ref-modal"
		>
			<p className="msw-ref-path">{ image.file_rel_path }</p>

			<div className="msw-ref-actions">
				<button className="components-button is-secondary" onClick={ () => load( true ) } disabled={ loading }>
					{ __( 'Rescan references', 'mediasweep' ) }
				</button>
				<span className="msw-ref-note">
					{ __( 'The full scan (incl. theme/plugin files) runs from the Dashboard.', 'mediasweep' ) }
				</span>
			</div>

			{ loading && ! refs && <Spinner /> }
			{ error && <p className="msw-error">{ error }</p> }

			{ refs && refs.length === 0 && (
				<p className="msw-empty-refs">
					{ __( 'No references found. This image looks unused.', 'mediasweep' ) }
				</p>
			) }

			{ refs && refs.length > 0 && (
				<table className="msw-ref-table">
					<thead>
						<tr>
							<th>{ __( 'Type', 'mediasweep' ) }</th>
							<th>{ __( 'Source', 'mediasweep' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ refs.map( ( ref ) => (
							<tr key={ ref.id }>
								<td>{ TYPE_LABELS[ ref.reference_type ] || ref.reference_type }</td>
								<td>
									{ ref.url ? (
										<a href={ ref.url } target="_blank" rel="noreferrer">{ ref.label }</a>
									) : (
										ref.label
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<p className="msw-ref-meta">
				{ __( 'Analyzed', 'mediasweep' ) }: { dateFormat( image.analyzed_at ) }
			</p>
		</Modal>
	);
}
