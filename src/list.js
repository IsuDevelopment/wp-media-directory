/**
 * Folders on the Media Library list view (upload.php?mode=list).
 *
 * The table is server-rendered. Changing folder or the subfolder switch
 * fetches the same page for the new URL in the background and swaps only the
 * rows and pagination, so the page does not reload and flash. Core binds the
 * list table's checkbox handlers on `body`, so swapped rows keep working.
 */
import { createRoot } from '@wordpress/element';

import Sidebar from './components/sidebar';
import ContentHeader from './components/content-header';
import {
	ALL,
	can,
	config,
	getState,
	isVisibleIn,
	normalizeFolder,
} from './store';
import { createView, folderProps } from './view';
import { startFiles } from './drag';
import fitPanel from './fit-panel';
import { swapTable } from './list-swap';

/**
 * The list URL for a folder; changing folder starts again at page 1.
 *
 * @param {number|string|null} folder Folder.
 * @param {boolean}            nested Include subfolders.
 * @return {URL} URL.
 */
function urlFor( folder, nested ) {
	const url = new URL( window.location.href );

	url.searchParams.delete( 'paged' );

	Object.entries( folderProps( config, folder, nested ) ).forEach(
		( [ key, value ] ) => {
			if ( value === null ) {
				url.searchParams.delete( key );
			} else {
				url.searchParams.set( key, value );
			}
		}
	);

	return url;
}

/**
 * Keep the folder when the list filters (type, date, search) submit.
 *
 * @param {HTMLFormElement}    form   List form.
 * @param {number|string|null} folder Folder.
 * @param {boolean}            nested Include subfolders.
 */
function syncHiddenInputs( form, folder, nested ) {
	Object.entries( folderProps( config, folder, nested ) ).forEach(
		( [ name, value ] ) => {
			let input = form.querySelector(
				`input[type="hidden"][name="${ name }"]`
			);

			if ( value === null ) {
				input?.remove();
				return;
			}

			if ( ! input ) {
				input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = name;
				form.appendChild( input );
			}

			input.value = value;
		}
	);
}

function makeRowsDraggable( form ) {
	form.querySelectorAll( '#the-list tr[id^="post-"]' ).forEach( ( row ) => {
		row.draggable = can.organize;
	} );
}

export default function bootList() {
	const form = document.getElementById( 'posts-filter' );

	if ( ! form ) {
		return;
	}

	let loadToken = 0;
	let main = null;

	/**
	 * Load the list for a URL without reloading the page.
	 *
	 * @param {URL}                url    Page URL.
	 * @param {number|string|null} folder Folder it shows.
	 * @param {boolean}            nested Include subfolders.
	 */
	function load( url, folder, nested ) {
		const token = ++loadToken;

		main?.classList.add( 'imd-is-loading' );

		window
			.fetch( url, { credentials: 'same-origin' } )
			.then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
				return response.text();
			} )
			.then( ( html ) => {
				if ( token !== loadToken ) {
					return;
				}

				const next = new window.DOMParser().parseFromString(
					html,
					'text/html'
				);

				if ( ! swapTable( form, next ) ) {
					throw new Error( 'no list' );
				}

				makeRowsDraggable( form );

				window.history.replaceState( window.history.state, '', url );
				syncHiddenInputs( form, folder, nested );
				main?.classList.remove( 'imd-is-loading' );
				view.setShown( { folder, nested } );
			} )
			.catch( ( error ) => {
				// Logged out, error page…: fall back to a normal navigation.
				if ( token === loadToken ) {
					// eslint-disable-next-line no-console -- Makes a silent full reload diagnosable.
					console.warn(
						'Media Directory: list swap failed, reloading.',
						error
					);
					window.location.assign( url );
				}
			} );
	}

	const go = ( folder, nested ) =>
		load( urlFor( folder, nested ), folder, nested );
	const reload = () =>
		load(
			new URL( window.location.href ),
			view.getFolder(),
			getState().nested
		);

	const view = createView( {
		kind: 'list',
		initial: config.folder,
		onChange: ( folder ) => go( folder, getState().nested ),
		handlers: {
			onNestedChange: ( nested ) => go( view.getFolder(), nested ),
			onFiled( map, isUndo ) {
				if ( isUndo ) {
					reload();
					return;
				}

				Object.entries( map ).forEach( ( [ id, folders ] ) => {
					if (
						! isVisibleIn(
							folders.map( Number ),
							view.getFolder(),
							getState().nested
						)
					) {
						document.getElementById( `post-${ id }` )?.remove();
					}
				} );
			},
			onFoldersRemoved( ids ) {
				if ( ids.includes( view.getFolder() ) ) {
					view.setFolder( ALL );
				} else if ( view.getFolder() !== ALL ) {
					reload();
				}
			},
			onFilesDeleted: reload,
			onTreeMoved() {
				if (
					typeof view.getFolder() === 'number' &&
					getState().nested
				) {
					reload();
				}
			},
		},
	} );

	syncHiddenInputs(
		form,
		normalizeFolder( config.folder ),
		getState().nested
	);

	const layout = document.createElement( 'div' );
	layout.className = 'imd-list-layout';

	const sidebar = document.createElement( 'div' );
	sidebar.className = 'imd-sidebar';

	main = document.createElement( 'div' );
	main.className = 'imd-list-main';

	const header = document.createElement( 'div' );
	header.className = 'imd-content-header-wrap';

	form.parentNode.insertBefore( layout, form );
	layout.append( sidebar, main );
	main.append( header, form );

	createRoot( sidebar ).render( <Sidebar view={ view } /> );
	fitPanel( sidebar );
	createRoot( header ).render( <ContentHeader view={ view } /> );

	makeRowsDraggable( form );

	// Drag rows; checked rows travel together.
	form.addEventListener( 'dragstart', ( event ) => {
		const row = event.target.closest?.( '#the-list tr[id^="post-"]' );

		if ( ! row ) {
			return;
		}

		const id = Number( row.id.replace( 'post-', '' ) );
		const checked = Array.from(
			form.querySelectorAll( '#the-list input[name="media[]"]:checked' )
		).map( ( input ) => Number( input.value ) );

		startFiles(
			event,
			checked.includes( id ) ? checked : [ id ],
			view.getFolder()
		);
	} );
}
