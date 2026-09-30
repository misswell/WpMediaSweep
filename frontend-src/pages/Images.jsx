const { __ } = wp.i18n;
import ImageTable from '../components/ImageTable';

export default function Images() {
	return (
		<div>
			<p className="msw-page-hint">
				{ __( 'Browse every indexed image. Compress, restore originals, inspect where an image is referenced, or move candidates to the trash.', 'mediasweep' ) }
			</p>
			<ImageTable />
		</div>
	);
}
