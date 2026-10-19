/**
 * Folders on the Media Library list view (upload.php?mode=list).
 *
 * The table is server-rendered, so changing folder reloads the page with the
 * folder in the URL.
 */
import { createRoot } from '@wordpress/element';

import Sidebar from './components/sidebar';
import ContentHeader from './components/content-header';
import { ALL, config, getState, isVisibleIn, normalizeFolder } from './store';
import { createView, folderProps } from './view';
import { startFiles } from './drag';
import fitPanel from './fit-panel';

function go( folder, nested ) {
	const url = new URL( window.location.href );
	const props = folderProps( config, folder, nested );

	url.searchParams.delete( 'paged' );

	Object.entries( props ).forEach( ( [ key, value ] ) => {
		if ( value === null ) {
			url.searchParams.delete( key );
		} else {
			url.searchParams.set( key, value );
		}
	} );

	window.location.assign( url );
}

export default function bootList() {
	const form = document.getElementById( 'posts-filter' );

	if ( ! form ) {
		return;
	}

	const reload = () => window.location.reload();
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
					go( ALL, getState().nested );
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

	// Keep the folder when the list filters (type, date, search) submit.
	if ( config.folder !== null ) {
		Object.entries(
			folderProps(
				config,
				normalizeFolder( config.folder ),
				getState().nested
			)
		).forEach( ( [ name, value ] ) => {
			if ( value !== null ) {
				const input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = name;
				input.value = value;
				form.appendChild( input );
			}
		} );
	}

	const layout = document.createElement( 'div' );
	layout.className = 'imd-list-layout';

	const sidebar = document.createElement( 'div' );
	sidebar.className = 'imd-sidebar';

	const main = document.createElement( 'div' );
	main.className = 'imd-list-main';

	const header = document.createElement( 'div' );
	header.className = 'imd-content-header-wrap';

	form.parentNode.insertBefore( layout, form );
	layout.append( sidebar, main );
	main.append( header, form );

	createRoot( sidebar ).render( <Sidebar view={ view } /> );
	fitPanel( sidebar );
	createRoot( header ).render( <ContentHeader view={ view } /> );

	// Drag rows; checked rows travel together.
	form.querySelectorAll( '#the-list tr[id^="post-"]' ).forEach( ( row ) => {
		row.draggable = true;
	} );

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
