/**
 * Page-wide UI: snackbars with Undo and the delete dialog, above the media modal.
 */
import { SnackbarList } from '@wordpress/components';

import { dismiss, getFolder, requestDelete } from '../store';
import { useStore } from './common';
import DeleteFolderModal from './delete-folder-modal';

export default function Notices() {
	const { notices, deleting } = useStore();
	const folder = deleting ? getFolder( deleting ) : null;

	return (
		<>
			<SnackbarList
				className="imd-notices"
				notices={ notices }
				onRemove={ dismiss }
			/>
			{ folder && (
				<DeleteFolderModal
					key={ folder.id }
					folder={ folder }
					onClose={ () => requestDelete( null ) }
				/>
			) }
		</>
	);
}
