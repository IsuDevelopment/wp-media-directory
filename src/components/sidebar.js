/**
 * The folder tree shown left of the files.
 */
import {
	Button,
	DropdownMenu,
	MenuGroup,
	MenuItem,
	SearchControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	check,
	chevronDown,
	chevronRight,
	cog,
	moreVertical,
	plus,
} from '@wordpress/icons';
import clsx from 'clsx';

import {
	ALL,
	UNASSIGNED,
	config,
	createFolder,
	getChildren,
	getFolder,
	getPath,
	isExpanded,
	renameFolder,
	removeFolder,
	can,
	canDeleteFolder,
	setExpanded,
	setNested,
	setPref,
} from '../store';
import { startFolder } from '../drag';
import {
	NameInput,
	allFilesIcon,
	folderIcon,
	unfiledIcon,
	useDropTarget,
	useStore,
	useViewFolder,
} from './common';

/**
 * Move keyboard focus between visible rows.
 *
 * @param {HTMLElement} from  Focused row button.
 * @param {number}      delta +1 / -1.
 */
function focusSibling( from, delta ) {
	const rows = Array.from(
		from.closest( '.imd-tree' ).querySelectorAll( '.imd-row__main' )
	);
	rows[ rows.indexOf( from ) + delta ]?.focus();
}

function Count( { value, title } ) {
	return (
		<span className="imd-row__count" title={ title }>
			{ value }
		</span>
	);
}

// A fixed entry: All files / Unfiled.
function SpecialRow( { icon, label, count, selected, onSelect, folderDrop } ) {
	const [ isOver, dropProps ] = useDropTarget( {
		folder: null,
		folderDrop,
	} );

	return (
		<li
			className={ clsx( 'imd-row', {
				'is-selected': selected,
				'is-drop-target': isOver,
			} ) }
			role="treeitem"
			aria-selected={ selected }
			{ ...dropProps }
		>
			<button
				type="button"
				className="imd-row__main"
				onClick={ onSelect }
				onKeyDown={ ( event ) => {
					if (
						event.key === 'ArrowDown' ||
						event.key === 'ArrowUp'
					) {
						event.preventDefault();
						focusSibling(
							event.currentTarget,
							event.key === 'ArrowDown' ? 1 : -1
						);
					}
				} }
			>
				<span className="imd-row__toggle-spacer" />
				<span className="imd-row__icon">{ icon }</span>
				<span className="imd-row__name">{ label }</span>
				<Count value={ count } />
			</button>
		</li>
	);
}

// One folder and, when open, its children.
function FolderRow( {
	folder,
	depth,
	current,
	nested,
	onSelect,
	editing,
	setEditing,
	creatingIn,
	setCreatingIn,
	filter,
} ) {
	const children = getChildren( folder.id );
	const open = filter ? true : isExpanded( folder.id );
	const selected = current === folder.id;
	const visibleChildren = filter
		? children.filter( ( child ) => filter.has( child.id ) )
		: children;

	const [ isOver, dropProps ] = useDropTarget( {
		folder: folder.id,
		folderDrop: folder.id,
		onHover: () => children.length && setExpanded( folder.id, true ),
	} );

	// Right-click opens the same menu as the ⋮ button, at the pointer.
	const [ menuOpen, setMenuOpen ] = useState( false );
	const [ menuAnchor, setMenuAnchor ] = useState( null );

	function openContextMenu( event ) {
		event.preventDefault();
		const { clientX: x, clientY: y } = event;
		setMenuAnchor( {
			getBoundingClientRect: () => new window.DOMRect( x, y, 0, 0 ),
			ownerDocument: event.currentTarget.ownerDocument,
		} );
		setMenuOpen( true );
	}

	const count = nested ? folder.total : folder.count;
	const hiddenBelow = ! nested && folder.total > folder.count;

	function startSubfolder() {
		setExpanded( folder.id, true );
		setCreatingIn( folder.id );
	}

	const mayDelete = canDeleteFolder( folder );
	const hasMenu = can.manage || mayDelete;

	function remove() {
		removeFolder( folder );
	}

	function onKeyDown( event ) {
		switch ( event.key ) {
			case 'ArrowDown':
			case 'ArrowUp':
				event.preventDefault();
				focusSibling(
					event.currentTarget,
					event.key === 'ArrowDown' ? 1 : -1
				);
				break;
			case 'ArrowRight':
				event.preventDefault();
				if ( children.length && ! open ) {
					setExpanded( folder.id, true );
				} else {
					focusSibling( event.currentTarget, 1 );
				}
				break;
			case 'ArrowLeft':
				event.preventDefault();
				if ( open && children.length ) {
					setExpanded( folder.id, false );
				}
				break;
			case 'F2':
				if ( can.manage ) {
					event.preventDefault();
					setEditing( folder.id );
				}
				break;
			case 'Delete':
			case 'Backspace':
				if ( mayDelete ) {
					event.preventDefault();
					remove();
				}
				break;
		}
	}

	return (
		<li
			className="imd-node"
			role="treeitem"
			aria-selected={ selected }
			aria-expanded={ children.length ? open : undefined }
		>
			<div
				className={ clsx( 'imd-row', {
					'is-selected': selected,
					'is-drop-target': isOver,
				} ) }
				style={ { '--imd-depth': depth } }
				onContextMenu={ hasMenu ? openContextMenu : undefined }
				{ ...dropProps }
			>
				{ children.length ? (
					<button
						type="button"
						className="imd-row__toggle"
						tabIndex={ -1 }
						aria-label={
							open
								? __( 'Collapse', 'isudev-media-directory' )
								: __( 'Expand', 'isudev-media-directory' )
						}
						onClick={ () => setExpanded( folder.id, ! open ) }
					>
						{ open ? chevronDown : chevronRight }
					</button>
				) : (
					<span className="imd-row__toggle-spacer" />
				) }

				{ editing === folder.id ? (
					<div className="imd-row__main is-editing">
						<span className="imd-row__icon">{ folderIcon }</span>
						<NameInput
							value={ folder.name }
							label={ __(
								'Folder name',
								'isudev-media-directory'
							) }
							onSubmit={ ( name ) => {
								setEditing( null );
								renameFolder( folder.id, name ).catch(
									() => {}
								);
							} }
							onCancel={ () => setEditing( null ) }
						/>
					</div>
				) : (
					<button
						type="button"
						className="imd-row__main"
						draggable={ can.manage }
						onDragStart={ ( event ) =>
							can.manage && startFolder( event, folder )
						}
						onClick={ () => onSelect( folder.id ) }
						onDoubleClick={ () =>
							can.manage && setEditing( folder.id )
						}
						onKeyDown={ onKeyDown }
						title={ getPath( folder.id )
							.map( ( item ) => item.name )
							.join( ' › ' ) }
					>
						<span className="imd-row__icon">{ folderIcon }</span>
						<span className="imd-row__name">{ folder.name }</span>
						<Count
							value={ hiddenBelow && ! count ? '' : count }
							title={
								hiddenBelow
									? sprintf(
											/* translators: %d: number of files. */
											_n(
												'%d file including subfolders',
												'%d files including subfolders',
												folder.total,
												'isudev-media-directory'
											),
											folder.total
									  )
									: undefined
							}
						/>
						{ hiddenBelow && (
							<span className="imd-row__dot" aria-hidden="true" />
						) }
					</button>
				) }

				{ hasMenu && (
					<DropdownMenu
						className="imd-row__menu"
						icon={ moreVertical }
						label={ __(
							'Folder actions',
							'isudev-media-directory'
						) }
						toggleProps={ {
							size: 'small',
							tabIndex: -1,
							onClick: () => setMenuAnchor( null ),
						} }
						open={ menuOpen }
						onToggle={ setMenuOpen }
						popoverProps={
							menuAnchor
								? {
										anchor: menuAnchor,
										placement: 'bottom-start',
								  }
								: { placement: 'bottom-end' }
						}
					>
						{ ( { onClose } ) => (
							<>
								{ can.manage && (
									<MenuGroup>
										<MenuItem
											onClick={ () => {
												onClose();
												startSubfolder();
											} }
										>
											{ __(
												'New subfolder',
												'isudev-media-directory'
											) }
										</MenuItem>
										<MenuItem
											shortcut="F2"
											onClick={ () => {
												onClose();
												setEditing( folder.id );
											} }
										>
											{ __(
												'Rename',
												'isudev-media-directory'
											) }
										</MenuItem>
									</MenuGroup>
								) }
								{ mayDelete && (
									<MenuGroup>
										<MenuItem
											isDestructive
											onClick={ () => {
												onClose();
												remove();
											} }
										>
											{ __(
												'Delete',
												'isudev-media-directory'
											) }
										</MenuItem>
									</MenuGroup>
								) }
							</>
						) }
					</DropdownMenu>
				) }
			</div>

			{ ( ( open && visibleChildren.length > 0 ) ||
				creatingIn === folder.id ) && (
				<ul role="group">
					{ creatingIn === folder.id && (
						<NewFolderRow
							parent={ folder.id }
							depth={ depth + 1 }
							onDone={ ( id ) => {
								setCreatingIn( undefined );
								if ( id ) {
									onSelect( id );
								}
							} }
						/>
					) }
					{ open &&
						visibleChildren.map( ( child ) => (
							<FolderRow
								key={ child.id }
								folder={ child }
								depth={ depth + 1 }
								current={ current }
								nested={ nested }
								onSelect={ onSelect }
								editing={ editing }
								setEditing={ setEditing }
								creatingIn={ creatingIn }
								setCreatingIn={ setCreatingIn }
								filter={ filter }
							/>
						) ) }
				</ul>
			) }
		</li>
	);
}

function NewFolderRow( { parent, depth, onDone } ) {
	return (
		<li className="imd-node" role="none">
			<div className="imd-row" style={ { '--imd-depth': depth } }>
				<span className="imd-row__toggle-spacer" />
				<div className="imd-row__main is-editing">
					<span className="imd-row__icon">{ folderIcon }</span>
					<NameInput
						label={ __(
							'New folder name',
							'isudev-media-directory'
						) }
						onSubmit={ ( name ) =>
							createFolder( name, parent )
								.then( onDone )
								.catch( () => onDone( null ) )
						}
						onCancel={ () => onDone( null ) }
					/>
				</div>
			</div>
		</li>
	);
}

/**
 * Folder IDs matching a search, plus their ancestors so the matches stay reachable.
 *
 * @param {Object[]} folders All folders.
 * @param {string}   search  Search text.
 * @return {Set|null} Visible IDs, or null when not searching.
 */
function matchFolders( folders, search ) {
	const needle = search.trim().toLocaleLowerCase();

	if ( ! needle ) {
		return null;
	}

	const visible = new Set();

	folders
		.filter( ( folder ) =>
			folder.name.toLocaleLowerCase().includes( needle )
		)
		.forEach( ( folder ) =>
			getPath( folder.id ).forEach( ( item ) => visible.add( item.id ) )
		);

	return visible;
}

// The user's own behaviour preferences; site defaults live in Folder settings.
function ViewOptions( { prefs } ) {
	const options = [
		[
			'tiles',
			__( 'Show subfolders above the files', 'isudev-media-directory' ),
		],
		[
			'reveal',
			__(
				'Open the current folder in the tree',
				'isudev-media-directory'
			),
		],
		[
			'perPostType',
			__(
				'Remember the last folder separately for each content type',
				'isudev-media-directory'
			),
		],
	];

	return (
		<DropdownMenu
			className="imd-panel__options"
			icon={ cog }
			label={ __( 'View options', 'isudev-media-directory' ) }
			toggleProps={ { size: 'compact' } }
			popoverProps={ { placement: 'bottom-end' } }
		>
			{ () => (
				<MenuGroup
					label={ __( 'View options', 'isudev-media-directory' ) }
				>
					{ options.map( ( [ key, label ] ) => (
						<MenuItem
							key={ key }
							role="menuitemcheckbox"
							isSelected={ !! prefs[ key ] }
							icon={ prefs[ key ] ? check : null }
							onClick={ () => setPref( key, ! prefs[ key ] ) }
						>
							{ label }
						</MenuItem>
					) ) }
				</MenuGroup>
			) }
		</DropdownMenu>
	);
}

export default function Sidebar( { view } ) {
	const { tree, nested, prefs } = useStore();
	const current = useViewFolder( view );
	const [ editing, setEditing ] = useState( null );
	const [ creatingIn, setCreatingIn ] = useState( undefined );
	const [ search, setSearch ] = useState( '' );
	const treeRef = useRef();

	// "Open the current folder in the tree": keep the open folder in view.
	useEffect( () => {
		if ( prefs.reveal && typeof current === 'number' ) {
			treeRef.current
				?.querySelector( '.imd-row.is-selected' )
				?.scrollIntoView( { block: 'nearest' } );
		}
	}, [ current, prefs.reveal ] );

	const filter = matchFolders( tree.folders, search );
	const roots = getChildren( 0 ).filter(
		( folder ) => ! filter || filter.has( folder.id )
	);

	function newFolder() {
		// New folders go inside the open folder, like "New Folder" in a file manager.
		const parent = typeof current === 'number' ? current : 0;

		if ( parent ) {
			setExpanded( parent, true );
		}

		setSearch( '' );
		setCreatingIn( parent );
	}

	return (
		<div className="imd-panel">
			<div className="imd-panel__header">
				<h2 className="imd-panel__title">
					{ __( 'Folders', 'isudev-media-directory' ) }
				</h2>
				{ can.manage && (
					<Button
						icon={ plus }
						size="compact"
						label={
							typeof current === 'number'
								? sprintf(
										/* translators: %s: folder name. */
										__(
											'New folder in “%s”',
											'isudev-media-directory'
										),
										getFolder( current )?.name
								  )
								: __( 'New folder', 'isudev-media-directory' )
						}
						onClick={ newFolder }
					/>
				) }
				<ViewOptions prefs={ prefs } />
			</div>

			<div className="imd-panel__nested">
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Show files from subfolders',
						'isudev-media-directory'
					) }
					checked={ nested }
					onChange={ setNested }
				/>
			</div>

			{ tree.folders.length > 6 && (
				<div className="imd-panel__search">
					<SearchControl
						__nextHasNoMarginBottom
						size="compact"
						label={ __(
							'Search folders',
							'isudev-media-directory'
						) }
						placeholder={ __(
							'Search folders',
							'isudev-media-directory'
						) }
						value={ search }
						onChange={ setSearch }
					/>
				</div>
			) }

			<ul
				className="imd-tree"
				role="tree"
				aria-label={ __( 'Folders', 'isudev-media-directory' ) }
				ref={ treeRef }
			>
				<SpecialRow
					icon={ allFilesIcon }
					label={ __( 'All files', 'isudev-media-directory' ) }
					count={ tree.all }
					selected={ current === ALL }
					onSelect={ () => view.setFolder( ALL ) }
					folderDrop={ 0 }
				/>
				<SpecialRow
					icon={ unfiledIcon }
					label={ __( 'Not in a folder', 'isudev-media-directory' ) }
					count={ tree.unassigned }
					selected={ current === UNASSIGNED }
					onSelect={ () => view.setFolder( UNASSIGNED ) }
					folderDrop={ null }
				/>

				<li className="imd-tree__separator" role="none" />

				{ creatingIn === 0 && (
					<NewFolderRow
						parent={ 0 }
						depth={ 0 }
						onDone={ ( id ) => {
							setCreatingIn( undefined );
							if ( id ) {
								view.setFolder( id );
							}
						} }
					/>
				) }

				{ roots.map( ( folder ) => (
					<FolderRow
						key={ folder.id }
						folder={ folder }
						depth={ 0 }
						current={ current }
						nested={ nested }
						onSelect={ view.setFolder }
						editing={ editing }
						setEditing={ setEditing }
						creatingIn={ creatingIn }
						setCreatingIn={ setCreatingIn }
						filter={ filter }
					/>
				) ) }

				{ can.manage &&
					! tree.folders.length &&
					creatingIn === undefined && (
						<li className="imd-tree__empty" role="none">
							<Button variant="link" onClick={ newFolder }>
								{ __(
									'Create your first folder',
									'isudev-media-directory'
								) }
							</Button>
						</li>
					) }

				{ filter && ! roots.length && (
					<li className="imd-tree__empty" role="none">
						{ __( 'No folders found.', 'isudev-media-directory' ) }
					</li>
				) }
			</ul>

			{ config.settings && view.kind !== 'modal' && (
				<div className="imd-panel__footer">
					<a href={ config.settings }>
						{ __( 'Folder settings', 'isudev-media-directory' ) }
					</a>
				</div>
			) }
		</div>
	);
}
