/**
 * One place that shows files: the library grid, a media modal or the list table.
 *
 * Each keeps its own open folder; the sidebar and header subscribe to it.
 */
import {
	ALL,
	UNASSIGNED,
	getState,
	normalizeFolder,
	reveal,
	rememberFolder,
	registerView,
} from './store';

let active = null;

/**
 * The view the user last worked in. Uploads land in its folder.
 *
 * @return {Object|null} View.
 */
export function getActiveView() {
	return active;
}

export function setActiveView( view ) {
	active = view;
}

/**
 * Create a view.
 *
 * @param {Object}   args
 * @param {string}   args.kind     grid | modal | list.
 * @param {*}        args.initial  Starting folder.
 * @param {Function} args.onChange Apply a new folder to the files shown; may return a promise.
 * @param {Object}   args.handlers onNestedChange / onFiled / onFoldersRemoved / onTreeMoved.
 * @return {Object} View.
 */
/**
 * Expand the tree down to a folder, when the user wants that.
 *
 * @param {number|string|null} folder Folder.
 */
function revealIfWanted( folder ) {
	if ( typeof folder === 'number' && getState().prefs.reveal ) {
		reveal( folder );
	}
}

export function createView( { kind, initial, onChange, handlers = {} } ) {
	let folder = normalizeFolder( initial );
	// What the file grid shows right now. Lags `folder` while the next page
	// loads, so the header and the files change together.
	let shown = { folder, nested: getState().nested };
	const listeners = new Set();
	const notify = () => listeners.forEach( ( listener ) => listener() );

	const view = {
		kind,
		...handlers,
		getFolder: () => folder,
		getShown: () => shown,
		setShown( next ) {
			shown = next;
			notify();
		},
		subscribe( listener ) {
			listeners.add( listener );
			return () => listeners.delete( listener );
		},
		setFolder( next ) {
			next = normalizeFolder( next );
			active = view;

			if ( next === folder ) {
				return;
			}

			folder = next;
			rememberFolder( folder );
			revealIfWanted( folder );
			notify();
			onChange( folder );
		},
		uploadFolder() {
			return typeof folder === 'number' ? folder : 0;
		},
	};

	revealIfWanted( folder );
	view.unregister = registerView( view );
	active = view;

	return view;
}

/**
 * Query props for a folder; `nested` only matters inside a real folder.
 *
 * @param {Object}             config Plugin config.
 * @param {number|string|null} folder Folder.
 * @param {boolean}            nested Include subfolders.
 * @return {Object} Props.
 */
export function folderProps( config, folder, nested ) {
	const inFolder = folder !== ALL && folder !== UNASSIGNED;

	return {
		[ config.folderVar ]: folder === ALL ? null : folder,
		[ config.nestedVar ]: inFolder ? Number( nested ) : null,
	};
}
