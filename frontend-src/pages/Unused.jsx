const { __ } = wp.i18n;

import ImageTable from '../components/ImageTable';

export default function Unused() {
	return (
		<div>
			<p className="msw-page-hint">
				{ __( 'Run a reference scan to find unused images. Review their references before moving them to the trash.', 'mediasweep' ) }
			</p>
			<ImageTable lockedStatus="unused" />
		</div>
	);
}
