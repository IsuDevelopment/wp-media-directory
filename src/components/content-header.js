/**
 * Breadcrumb and subfolders shown above the files.
 *
 * Subfolders are the part people missed in the tree, so they sit right where
 * the files are, and work as drop targets too.
 */
import {
	Button,
	MenuGroup,
	MenuItem,
	NavigableMenu,
	Popover,
} from '@wordpress/components';
import { useState, useSyncExternalStore } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';
import clsx from 'clsx';

import {
	ALL,
	UNASSIGNED,
	can,
	canDeleteFolder,
	createFolder,
	getChildren,
	getFolder,
	getPath,
	removeFolder,
	renameFolder,
	setNested,
} from '../store';
import { NameInput, folderIcon, useDropTarget, useStore } from './common';

function Crumb( { folder, label, onSelect, isCurrent } ) {
	const [ isOver, dropProps ] = useDropTarget( {
		folder: folder || null,
		folderDrop: folder === ALL ? 0 : folder,
	} );

	if ( isCurrent ) {
		return (
			<span className="imd-crumbs__current" aria-current="page">
				{ label }
			</span>
		);
	}

	return (
		<Button
			variant="link"
			className={ clsx( 'imd-crumbs__link', {
				'is-drop-target': isOver,
			} ) }
			onClick={ () => onSelect( folder ) }
			{ ...dropProps }
		>
			{ label }
		</Button>
	);
}

/**
 * Right-click menu for a tile, opened at the pointer.
 *
 * @param {Object}   props
 * @param {Object}   props.folder   Folder.
 * @param {Object}   props.anchor   Virtual anchor at the pointer.
 * @param {Function} props.onRename Start renaming.
 * @param {Function} props.onClose  Close the menu.
 */
function TileMenu( { folder, anchor, onRename, onClose } ) {
	return (
		<Popover
			anchor={ anchor }
			placement="bottom-start"
			onClose={ onClose }
			className="imd-tile-menu"
		>
			<NavigableMenu role="menu">
				<MenuGroup>
					{ can.manage && (
						<MenuItem
							shortcut="F2"
							onClick={ () => {
								onClose();
								onRename();
							} }
						>
							{ __( 'Rename', 'isudev-media-directory' ) }
						</MenuItem>
					) }
					{ canDeleteFolder( folder ) && (
						<MenuItem
							isDestructive
							onClick={ () => {
								onClose();
								removeFolder( folder );
							} }
						>
							{ __( 'Delete', 'isudev-media-directory' ) }
						</MenuItem>
					) }
				</MenuGroup>
			</NavigableMenu>
		</Popover>
	);
}

function tileMeta( folder, nested ) {
	const subfolders = getChildren( folder.id ).length;
	const count = nested ? folder.total : folder.count;

	return [
		sprintf(
			/* translators: %d: number of files. */
			_n( '%d file', '%d files', count, 'isudev-media-directory' ),
			count
		),
		subfolders
			? sprintf(
					/* translators: %d: number of folders. */
					_n(
						'%d folder',
						'%d folders',
						subfolders,
						'isudev-media-directory'
					),
					subfolders
			  )
			: null,
	]
		.filter( Boolean )
		.join( ' · ' );
}

function Tile( { folder, nested, onSelect } ) {
	const [ isOver, dropProps ] = useDropTarget( {
		folder: folder.id,
		folderDrop: folder.id,
	} );
	const [ editing, setEditing ] = useState( false );
	const [ menu, setMenu ] = useState( null );
	const hasMenu = can.manage || canDeleteFolder( folder );

	function openMenu( event ) {
		event.preventDefault();

		// Keyboard context-menu key reports no pointer position; use the tile.
		const box = event.currentTarget.getBoundingClientRect();
		const x = event.clientX || box.left + 16;
		const y = event.clientY || box.bottom;

		setMenu( {
			getBoundingClientRect: () => new window.DOMRect( x, y, 0, 0 ),
			ownerDocument: event.currentTarget.ownerDocument,
		} );
	}

	if ( editing ) {
		return (
			<li>
				<div className="imd-tile is-editing">
					<span className="imd-tile__icon">{ folderIcon }</span>
					<NameInput
						value={ folder.name }
						label={ __( 'Folder name', 'isudev-media-directory' ) }
						onSubmit={ ( name ) => {
							setEditing( false );
							renameFolder( folder.id, name ).catch( () => {} );
						} }
						onCancel={ () => setEditing( false ) }
					/>
				</div>
			</li>
		);
	}

	return (
		<li>
			<button
				type="button"
				className={ clsx( 'imd-tile', { 'is-drop-target': isOver } ) }
				onClick={ () => onSelect( folder.id ) }
				onContextMenu={ hasMenu ? openMenu : undefined }
				onKeyDown={ ( event ) => {
					if ( event.key === 'F2' && can.manage ) {
						event.preventDefault();
						setEditing( true );
					}
				} }
				{ ...dropProps }
			>
				<span className="imd-tile__icon">{ folderIcon }</span>
				<span className="imd-tile__text">
					<span className="imd-tile__name">{ folder.name }</span>
					<span className="imd-tile__meta">
						{ tileMeta( folder, nested ) }
					</span>
				</span>
			</button>
			{ menu && (
				<TileMenu
					folder={ folder }
					anchor={ menu }
					onRename={ () => setEditing( true ) }
					onClose={ () => setMenu( null ) }
				/>
			) }
		</li>
	);
}

// "+ New folder" as the last tile: creates a subfolder of the open folder.
function NewFolderTile( { parent } ) {
	const [ editing, setEditing ] = useState( false );

	if ( editing ) {
		return (
			<li>
				<div className="imd-tile is-editing">
					<span className="imd-tile__icon">{ folderIcon }</span>
					<NameInput
						label={ __(
							'New folder name',
							'isudev-media-directory'
						) }
						onSubmit={ ( name ) => {
							setEditing( false );
							createFolder( name, parent ).catch( () => {} );
						} }
						onCancel={ () => setEditing( false ) }
					/>
				</div>
			</li>
		);
	}

	return (
		<li>
			<button
				type="button"
				className="imd-tile imd-tile--new"
				onClick={ () => setEditing( true ) }
			>
				<span className="imd-tile__icon">{ plus }</span>
				<span className="imd-tile__name">
					{ __( 'New folder', 'isudev-media-directory' ) }
				</span>
			</button>
		</li>
	);
}

export default function ContentHeader( { view } ) {
	// Re-render on tree changes; folder and nested follow what the grid shows.
	const { prefs } = useStore();
	const { folder: current, nested } = useSyncExternalStore(
		view.subscribe,
		view.getShown
	);
	const folder = typeof current === 'number' ? getFolder( current ) : null;
	// Tiles only inside a folder: on "All files" every root folder would be a tile.
	const showTiles = !! folder && prefs.tiles;
	const tiles = showTiles ? getChildren( folder.id ) : [];
	const hiddenFiles = folder && ! nested ? folder.total - folder.count : 0;

	if ( current === ALL ) {
		return null;
	}

	return (
		<div className="imd-content-header">
			{ current !== ALL && (
				<nav
					className="imd-crumbs"
					aria-label={ __( 'Folder path', 'isudev-media-directory' ) }
				>
					<Crumb
						folder={ ALL }
						label={ __( 'All files', 'isudev-media-directory' ) }
						onSelect={ view.setFolder }
					/>
					{ current === UNASSIGNED && (
						<>
							<span
								className="imd-crumbs__sep"
								aria-hidden="true"
							>
								/
							</span>
							<Crumb
								isCurrent
								label={ __(
									'Not in a folder',
									'isudev-media-directory'
								) }
							/>
						</>
					) }
					{ folder &&
						getPath( folder.id ).map( ( item ) => (
							<span key={ item.id } className="imd-crumbs__item">
								<span
									className="imd-crumbs__sep"
									aria-hidden="true"
								>
									/
								</span>
								<Crumb
									folder={ item.id }
									label={ item.name }
									isCurrent={ item.id === folder.id }
									onSelect={ view.setFolder }
								/>
							</span>
						) ) }
				</nav>
			) }

			{ hiddenFiles > 0 && (
				<div className="imd-hidden-files">
					<span>
						{ sprintf(
							/* translators: %d: number of files. */
							_n(
								'%d more file is in subfolders.',
								'%d more files are in subfolders.',
								hiddenFiles,
								'isudev-media-directory'
							),
							hiddenFiles
						) }
					</span>
					<Button variant="link" onClick={ () => setNested( true ) }>
						{ __( 'Show them here', 'isudev-media-directory' ) }
					</Button>
				</div>
			) }

			{ showTiles && ( tiles.length > 0 || can.manage ) && (
				<ul
					className="imd-tiles"
					aria-label={ __( 'Subfolders', 'isudev-media-directory' ) }
				>
					{ tiles.map( ( item ) => (
						<Tile
							key={ item.id }
							folder={ item }
							nested={ nested }
							onSelect={ view.setFolder }
						/>
					) ) }
					{ can.manage && <NewFolderTile parent={ folder.id } /> }
				</ul>
			) }
		</div>
	);
}
