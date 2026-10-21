/**
 * Ask what happens to the files when a folder is deleted.
 */
import { Button, Modal, RadioControl, TreeSelect } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	can,
	deleteFolder,
	getChildren,
	getFolder,
	isWithin,
	getState,
} from '../store';

/**
 * Folder tree for TreeSelect, without the folder being deleted.
 *
 * @param {number} parent  Parent ID.
 * @param {number} exclude Folder being deleted.
 * @return {Object[]} Tree.
 */
function targets( parent, exclude ) {
	return getChildren( parent )
		.filter( ( folder ) => folder.id !== exclude )
		.map( ( folder ) => ( {
			id: String( folder.id ),
			name: folder.name,
			children: targets( folder.id, exclude ),
		} ) );
}

export default function DeleteFolderModal( { folder, onClose } ) {
	const parent = getFolder( folder.parent );
	const [ choice, setChoice ] = useState( 'parent' );
	const [ target, setTarget ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const files = folder.total;
	const subfolders =
		getState().tree.folders.filter( ( item ) =>
			isWithin( item.id, folder.id )
		).length - 1;

	const summary = [
		sprintf(
			/* translators: %d: number of files. */
			_n( '%d file', '%d files', files, 'isudev-media-directory' ),
			files
		),
		subfolders
			? sprintf(
					/* translators: %d: number of folders. */
					_n(
						'%d subfolder',
						'%d subfolders',
						subfolders,
						'isudev-media-directory'
					),
					subfolders
			  )
			: null,
	]
		.filter( Boolean )
		.join( ', ' );

	const allOptions = [
		{
			value: 'parent',
			label: parent
				? sprintf(
						/* translators: %s: parent folder name. */
						__( 'Move them up to “%s”', 'isudev-media-directory' ),
						parent.name
				  )
				: __(
						'Keep them in the library, without a folder',
						'isudev-media-directory'
				  ),
		},
		{
			value: 'other',
			label: __(
				'Move them to another folder',
				'isudev-media-directory'
			),
		},
		{
			value: 'delete',
			label: __(
				'Delete the files permanently',
				'isudev-media-directory'
			),
		},
	];

	// Without the right to delete files, that option is not offered at all.
	const options = allOptions.filter(
		( option ) => option.value !== 'delete' || can.deleteFiles
	);

	function submit() {
		setBusy( true );

		const args = {
			parent: { files: 'move', target: -1 },
			other: { files: 'move', target: Number( target ) },
			delete: { files: 'delete', target: 0 },
		}[ choice ];

		deleteFolder( folder.id, args )
			.then( onClose )
			.catch( () => setBusy( false ) );
	}

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: folder name. */
				__( 'Delete “%s”?', 'isudev-media-directory' ),
				folder.name
			) }
			onRequestClose={ busy ? () => {} : onClose }
			size="medium"
			className="imd-delete-modal"
			overlayClassName="imd-delete-overlay"
		>
			<p>
				{ sprintf(
					/* translators: 1: folder name, 2: e.g. "12 files, 2 subfolders". */
					__(
						'“%1$s” holds %2$s. The folder and its subfolders will be removed. What should happen to the files?',
						'isudev-media-directory'
					),
					folder.name,
					summary
				) }
			</p>

			<RadioControl
				label={ __( 'Files', 'isudev-media-directory' ) }
				hideLabelFromVision
				selected={ choice }
				options={ options }
				onChange={ setChoice }
			/>

			{ choice === 'other' && (
				<div className="imd-delete-modal__target">
					<TreeSelect
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Folder', 'isudev-media-directory' ) }
						noOptionLabel={ __(
							'— Choose a folder —',
							'isudev-media-directory'
						) }
						tree={ targets( 0, folder.id ) }
						selectedId={ target }
						onChange={ setTarget }
					/>
				</div>
			) }

			{ choice === 'delete' && (
				<p className="imd-delete-modal__warning">
					{ __(
						'The files are removed from the whole library, including other folders and any posts that use them. This cannot be undone.',
						'isudev-media-directory'
					) }
				</p>
			) }

			<div className="imd-delete-modal__actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onClose }
					disabled={ busy }
				>
					{ __( 'Cancel', 'isudev-media-directory' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive
					isBusy={ busy }
					disabled={ busy || ( choice === 'other' && ! target ) }
					onClick={ submit }
				>
					{ choice === 'delete'
						? sprintf(
								/* translators: %d: number of files. */
								_n(
									'Delete folder and %d file',
									'Delete folder and %d files',
									files,
									'isudev-media-directory'
								),
								files
						  )
						: __( 'Delete folder', 'isudev-media-directory' ) }
				</Button>
			</div>
		</Modal>
	);
}
