/**
 * Shared state for every folder sidebar on the page.
 *
 * The media library and each media modal mount their own sidebar, but they
 * all read the same tree, the same "include subfolders" toggle and the same
 * undo history from here.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

const config = window.isudevMediaDirectory;
const LAST_FOLDER_KEY = 'imd:last-folder';
const EXPANDED_KEY = 'imd:expanded';

export const ALL = null;

/**
 * What the current user may do (from the Permissions matrix). The server
 * enforces it; the UI only hides what would be refused.
 */
export const can = {
	view: true,
	organize: true,
	manage: true,
	delete: true,
	deleteFiles: true,
	...( window.isudevMediaDirectory?.can || {} ),
};
export const UNASSIGNED = 'unassigned';

function readStorage( key, fallback ) {
	try {
		const value = window.localStorage.getItem( key );
		return value === null ? fallback : JSON.parse( value );
	} catch ( error ) {
		return fallback;
	}
}

function writeStorage( key, value ) {
	try {
		window.localStorage.setItem( key, JSON.stringify( value ) );
	} catch ( error ) {
		// Private mode or blocked storage: the UI just forgets.
	}
}

let state = {
	tree: config.tree,
	nested: !! config.nested,
	prefs: {
		nested: !! config.nested,
		tiles: true,
		reveal: true,
		perPostType: false,
		...( config.prefs || {} ),
	},
	expanded: readStorage( EXPANDED_KEY, [] ),
	notices: [],
	busy: false,
	deleting: null,
};

const listeners = new Set();
const undoStack = [];

export function getState() {
	return state;
}

export function subscribe( listener ) {
	listeners.add( listener );
	return () => listeners.delete( listener );
}

function setState( patch ) {
	state = { ...state, ...patch };
	listeners.forEach( ( listener ) => listener( state ) );
}

/* ----------------------------------------------------------------------------
 * Tree helpers.
 * ------------------------------------------------------------------------- */

const collator = new Intl.Collator( undefined, {
	numeric: true,
	sensitivity: 'base',
} );

let indexFor = null;
let index = null;

/**
 * Lookup tables for the current tree, rebuilt only when the tree changes.
 */
function getIndex() {
	if ( indexFor === state.tree ) {
		return index;
	}

	const byId = new Map();
	const children = new Map();

	state.tree.folders.forEach( ( folder ) => {
		byId.set( folder.id, folder );

		if ( ! children.has( folder.parent ) ) {
			children.set( folder.parent, [] );
		}

		children.get( folder.parent ).push( folder );
	} );

	children.forEach( ( list ) =>
		list.sort( ( a, b ) => collator.compare( a.name, b.name ) )
	);

	indexFor = state.tree;
	index = { byId, children };

	return index;
}

/**
 * Whether the current user may delete a folder. Empty folders only need
 * "Create and rename", matching Permissions\\can_delete().
 *
 * @param {Object} folder Folder.
 * @return {boolean} Allowed.
 */
export function canDeleteFolder( folder ) {
	return (
		can.delete ||
		( can.manage && ! folder.total && ! getChildren( folder.id ).length )
	);
}

/**
 * Delete from the UI: folders holding files ask what to do with them, empty
 * ones go straight away (Undo is there).
 *
 * @param {Object} folder Folder.
 */
export function removeFolder( folder ) {
	if ( folder.total ) {
		requestDelete( folder.id );
	} else {
		deleteFolder( folder.id ).catch( () => {} );
	}
}

export function getFolder( id ) {
	return getIndex().byId.get( id ) || null;
}

export function getChildren( parent = 0 ) {
	return getIndex().children.get( parent ) || [];
}

/**
 * Folders from the root down to `id`.
 *
 * @param {number} id Folder ID.
 * @return {Object[]} Ancestors, `id` last.
 */
export function getPath( id ) {
	const path = [];
	let current = getFolder( id );

	while ( current && path.length < 100 ) {
		path.unshift( current );
		current = getFolder( current.parent );
	}

	return path;
}

/**
 * Whether `id` is `ancestor` or sits somewhere below it.
 *
 * @param {number} id       Folder ID.
 * @param {number} ancestor Folder ID.
 * @return {boolean} Inside.
 */
export function isWithin( id, ancestor ) {
	return getPath( id ).some( ( folder ) => folder.id === ancestor );
}

/**
 * Whether a file filed in `folders` shows up while looking at `view`.
 *
 * @param {number[]}           folders Folder IDs of the file.
 * @param {number|string|null} view    Folder being viewed.
 * @param {boolean}            nested  Include subfolders.
 * @return {boolean} Visible.
 */
export function isVisibleIn( folders, view, nested ) {
	if ( view === ALL ) {
		return true;
	}

	if ( view === UNASSIGNED ) {
		return folders.length === 0;
	}

	return folders.some( ( id ) =>
		nested ? isWithin( id, view ) : id === view
	);
}

/**
 * Normalise a folder value coming from props, URLs or storage.
 *
 * @param {*} value Raw value.
 * @return {number|string|null} Folder ID, UNASSIGNED or ALL.
 */
export function normalizeFolder( value ) {
	if ( value === UNASSIGNED ) {
		return UNASSIGNED;
	}

	const id = parseInt( value, 10 );

	return id && getFolder( id ) ? id : ALL;
}

/**
 * Storage key for the last open folder; one per content type when the user
 * opted in ("Remember the last folder separately for each content type").
 *
 * @return {string} Key.
 */
function lastFolderKey() {
	if ( ! state.prefs.perPostType ) {
		return LAST_FOLDER_KEY;
	}

	// `typenow` is the post type of the screen (product, post, page…); the
	// Media Library itself has none.
	return `${ LAST_FOLDER_KEY }:${ window.typenow || 'library' }`;
}

export function getLastFolder() {
	return normalizeFolder( readStorage( lastFolderKey(), null ) );
}

export function rememberFolder( folder ) {
	writeStorage( lastFolderKey(), folder );
}

/* ----------------------------------------------------------------------------
 * UI state.
 * ------------------------------------------------------------------------- */

export function isExpanded( id ) {
	return state.expanded.includes( id );
}

export function setExpanded( id, expanded ) {
	const next = state.expanded.filter( ( item ) => item !== id );

	if ( expanded ) {
		next.push( id );
	}

	writeStorage( EXPANDED_KEY, next );
	setState( { expanded: next } );
}

/**
 * Open every folder that has subfolders.
 */
export function expandAll() {
	const next = state.tree.folders
		.filter( ( folder ) => getChildren( folder.id ).length )
		.map( ( folder ) => folder.id );

	writeStorage( EXPANDED_KEY, next );
	setState( { expanded: next } );
}

export function collapseAll() {
	writeStorage( EXPANDED_KEY, [] );
	setState( { expanded: [] } );
}

/**
 * Open every ancestor of a folder so it can be seen in the tree.
 *
 * @param {number} id Folder ID.
 */
export function reveal( id ) {
	const ancestors = getPath( id )
		.slice( 0, -1 )
		.map( ( folder ) => folder.id );
	const missing = ancestors.filter( ( item ) => ! isExpanded( item ) );

	if ( missing.length ) {
		const next = [ ...state.expanded, ...missing ];
		writeStorage( EXPANDED_KEY, next );
		setState( { expanded: next } );
	}
}

/**
 * Open the "what about the files?" dialog for a folder.
 *
 * @param {number|null} id Folder ID, null to close.
 */
export function requestDelete( id ) {
	setState( { deleting: id } );
}

let noticeId = 0;

/**
 * Show a snackbar.
 *
 * @param {string}  content    Message.
 * @param {boolean} [withUndo] Show an Undo button.
 */
export function notify( content, withUndo = false ) {
	const id = `imd-${ ++noticeId }`;
	const notice = { id, content };

	if ( withUndo ) {
		notice.actions = [
			{
				label: __( 'Undo', 'isudev-media-directory' ),
				onClick: () => {
					dismiss( id );
					undo();
				},
			},
		];
	}

	setState( { notices: [ ...state.notices.slice( -2 ), notice ] } );
}

export function dismiss( id ) {
	setState( {
		notices: state.notices.filter( ( notice ) => notice.id !== id ),
	} );
}

/* ----------------------------------------------------------------------------
 * Views (media library grids, modals, list table) listen for these.
 * ------------------------------------------------------------------------- */

const views = new Set();

export function registerView( view ) {
	views.add( view );
	return () => views.delete( view );
}

function eachView( method, ...args ) {
	views.forEach( ( view ) => view[ method ]?.( ...args ) );
}

/* ----------------------------------------------------------------------------
 * Server actions.
 * ------------------------------------------------------------------------- */

function request( path, options = {} ) {
	return apiFetch( {
		path: `/${ config.namespace }${ path }`,
		method: 'POST',
		...options,
	} );
}

function fail( error ) {
	notify(
		error?.message ||
			__(
				'Something went wrong. Please try again.',
				'isudev-media-directory'
			)
	);
	throw error;
}

let refreshTimer = null;

/**
 * Reload the tree; debounced so a batch of uploads costs one request.
 */
export function refresh() {
	window.clearTimeout( refreshTimer );
	refreshTimer = window.setTimeout( () => {
		request( '/folders', { method: 'GET' } ).then( ( tree ) =>
			setState( { tree } )
		);
	}, 400 );
}

export function setNested( nested ) {
	setState( { nested, prefs: { ...state.prefs, nested } } );
	eachView( 'onNestedChange', nested );
	request( '/preferences', { data: { nested } } ).catch( () => {} );
}

/**
 * Change one of the user's own preferences (the ⚙ menu).
 *
 * @param {string}  key   tiles | reveal | perPostType (nested: use setNested).
 * @param {boolean} value On or off.
 */
export function setPref( key, value ) {
	if ( key === 'nested' ) {
		setNested( value );
		return;
	}

	setState( { prefs: { ...state.prefs, [ key ]: value } } );
	request( '/preferences', { data: { [ key ]: value } } ).catch( () => {} );
}

function pushUndo( entry ) {
	undoStack.push( entry );

	if ( undoStack.length > 50 ) {
		undoStack.shift();
	}
}

export function canUndo() {
	return undoStack.length > 0;
}

/**
 * Revert the last change.
 *
 * @return {Promise} Resolves when reverted.
 */
export function undo() {
	const entry = undoStack.pop();

	if ( ! entry ) {
		return Promise.resolve();
	}

	return entry
		.revert()
		.then( () => notify( entry.undone ) )
		.catch( fail );
}

export function createFolder( name, parent = 0 ) {
	return request( '/folders', { data: { name, parent } } )
		.then( ( { id, tree } ) => {
			setState( { tree } );

			if ( parent ) {
				reveal( id );
			}

			pushUndo( {
				undone: sprintf(
					/* translators: %s: folder name. */
					__( 'Removed folder “%s”.', 'isudev-media-directory' ),
					name
				),
				revert: () =>
					request( `/folders/${ id }`, { method: 'DELETE' } ).then(
						( response ) => {
							setState( { tree: response.tree } );
							eachView( 'onFoldersRemoved', [ id ] );
						}
					),
			} );

			return id;
		} )
		.catch( fail );
}

export function renameFolder( id, name ) {
	const folder = getFolder( id );

	if ( ! folder || folder.name === name ) {
		return Promise.resolve();
	}

	return request( `/folders/${ id }`, { data: { name } } )
		.then( ( { previous, tree } ) => {
			setState( { tree } );
			pushUndo( {
				undone: sprintf(
					/* translators: %s: folder name. */
					__( 'Renamed back to “%s”.', 'isudev-media-directory' ),
					previous.name
				),
				revert: () =>
					request( `/folders/${ id }`, {
						data: { name: previous.name },
					} ).then( ( response ) =>
						setState( { tree: response.tree } )
					),
			} );
		} )
		.catch( fail );
}

export function moveFolder( id, parent ) {
	const folder = getFolder( id );

	if ( ! folder || folder.parent === parent || id === parent ) {
		return Promise.resolve();
	}

	if ( parent && isWithin( parent, id ) ) {
		notify(
			__(
				'A folder cannot be moved into itself.',
				'isudev-media-directory'
			)
		);
		return Promise.resolve();
	}

	return request( `/folders/${ id }`, { data: { parent } } )
		.then( ( { previous, tree } ) => {
			setState( { tree } );
			reveal( id );
			eachView( 'onTreeMoved' );

			const target = getFolder( parent );

			notify(
				target
					? sprintf(
							/* translators: 1: folder name, 2: target folder name. */
							__(
								'Moved “%1$s” into “%2$s”.',
								'isudev-media-directory'
							),
							folder.name,
							target.name
					  )
					: sprintf(
							/* translators: %s: folder name. */
							__(
								'Moved “%s” to the top level.',
								'isudev-media-directory'
							),
							folder.name
					  ),
				true
			);

			pushUndo( {
				undone: sprintf(
					/* translators: %s: folder name. */
					__( 'Moved “%s” back.', 'isudev-media-directory' ),
					folder.name
				),
				revert: () =>
					request( `/folders/${ id }`, {
						data: { parent: previous.parent },
					} ).then( ( response ) => {
						setState( { tree: response.tree } );
						eachView( 'onTreeMoved' );
					} ),
			} );
		} )
		.catch( fail );
}

/**
 * Delete a folder and its subfolders.
 *
 * @param {number} id             Folder ID.
 * @param {Object} [options]
 * @param {string} options.files  move | delete.
 * @param {number} options.target Folder for the files; -1 parent, 0 none.
 * @return {Promise} Resolves when deleted.
 */
export function deleteFolder( id, { files = 'move', target = -1 } = {} ) {
	const folder = getFolder( id );

	if ( ! folder ) {
		return Promise.resolve();
	}

	const ids = state.tree.folders
		.filter( ( item ) => isWithin( item.id, id ) )
		.map( ( item ) => item.id );

	return request( `/folders/${ id }`, {
		method: 'DELETE',
		data: { files, target },
	} )
		.then( ( response ) => {
			const { snapshot, previous, moved, deleted, tree } = response;
			setState( { tree } );
			eachView( 'onFoldersRemoved', ids );

			if ( deleted.length ) {
				eachView( 'onFilesDeleted', deleted );
				notify(
					sprintf(
						/* translators: 1: folder name, 2: number of files. */
						_n(
							'Deleted folder “%1$s” and %2$d file.',
							'Deleted folder “%1$s” and %2$d files.',
							deleted.length,
							'isudev-media-directory'
						),
						folder.name,
						deleted.length
					)
				);
				return;
			}

			const into = getFolder( response.target );
			let message;

			if ( ! moved ) {
				message = sprintf(
					/* translators: %s: folder name. */
					__( 'Deleted folder “%s”.', 'isudev-media-directory' ),
					folder.name
				);
			} else if ( into ) {
				message = sprintf(
					/* translators: 1: folder name, 2: number of files, 3: target folder name. */
					_n(
						'Deleted folder “%1$s”. %2$d file moved to “%3$s”.',
						'Deleted folder “%1$s”. %2$d files moved to “%3$s”.',
						moved,
						'isudev-media-directory'
					),
					folder.name,
					moved,
					into.name
				);
			} else {
				message = sprintf(
					/* translators: 1: folder name, 2: number of files. */
					_n(
						'Deleted folder “%1$s”. %2$d file is now not in a folder.',
						'Deleted folder “%1$s”. %2$d files are now not in a folder.',
						moved,
						'isudev-media-directory'
					),
					folder.name,
					moved
				);
			}

			notify( message, true );

			pushUndo( {
				undone: sprintf(
					/* translators: %s: folder name. */
					__( 'Restored folder “%s”.', 'isudev-media-directory' ),
					folder.name
				),
				revert: () =>
					request( '/folders/restore', { data: { snapshot } } )
						.then( ( restored ) =>
							Object.keys( previous || {} ).length
								? request( '/items/set', {
										data: { map: previous },
								  } )
								: restored
						)
						.then( ( restored ) => {
							setState( { tree: restored.tree } );

							if ( restored.current ) {
								eachView( 'onFiled', restored.current, true );
							} else {
								eachView( 'onTreeMoved' );
							}
						} ),
			} );
		} )
		.catch( fail );
}

/**
 * Move, add or remove files.
 *
 * @param {Object}             args
 * @param {number[]}           args.ids    Attachment IDs.
 * @param {number}             args.folder Target folder (0 for remove).
 * @param {string}             args.mode   move | add | remove.
 * @param {number|string|null} args.from   Folder the files were dragged out of.
 * @return {Promise} Resolves with the new folders per file.
 */
export function fileItems( { ids, folder, mode = 'move', from = ALL } ) {
	const target = getFolder( folder );

	return request( '/items', {
		data: {
			ids,
			folder: folder || 0,
			mode,
			from: typeof from === 'number' ? from : 0,
			nested: state.nested,
		},
	} )
		.then( ( { previous, current, tree } ) => {
			setState( { tree } );
			eachView( 'onFiled', current );

			const count = ids.length;
			let message;

			if ( mode === 'remove' ) {
				message = sprintf(
					/* translators: %d: number of files. */
					_n(
						'Removed %d file from the folder.',
						'Removed %d files from the folder.',
						count,
						'isudev-media-directory'
					),
					count
				);
			} else if ( mode === 'add' ) {
				message = sprintf(
					/* translators: 1: number of files, 2: folder name. */
					_n(
						'Added %1$d file to “%2$s”.',
						'Added %1$d files to “%2$s”.',
						count,
						'isudev-media-directory'
					),
					count,
					target?.name
				);
			} else {
				message = sprintf(
					/* translators: 1: number of files, 2: folder name. */
					_n(
						'Moved %1$d file to “%2$s”.',
						'Moved %1$d files to “%2$s”.',
						count,
						'isudev-media-directory'
					),
					count,
					target?.name
				);
			}

			notify( message, true );

			pushUndo( {
				undone: sprintf(
					/* translators: %d: number of files. */
					_n(
						'Put %d file back.',
						'Put %d files back.',
						count,
						'isudev-media-directory'
					),
					count
				),
				revert: () =>
					request( '/items/set', { data: { map: previous } } ).then(
						( response ) => {
							setState( { tree: response.tree } );
							eachView( 'onFiled', response.current, true );
						}
					),
			} );

			return current;
		} )
		.catch( fail );
}

export { config };
