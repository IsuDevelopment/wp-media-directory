/**
 * IsuDev Media Directory: folders for the Media Library and media modals.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import './style.scss';
import './media';
import bootList from './list';
import Notices from './components/notices';
import { canUndo, config, getState, undo } from './store';
import { folderProps, getActiveView } from './view';

function isTyping( target ) {
	return (
		target.isContentEditable ||
		[ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( target.nodeName )
	);
}

// Cmd/Ctrl+Z undoes the last folder change. Outside the Media Library it only
// fires inside an open media modal, so the block editor keeps its own undo.
window.addEventListener(
	'keydown',
	( event ) => {
		if (
			event.key?.toLowerCase() !== 'z' ||
			! ( event.metaKey || event.ctrlKey ) ||
			event.shiftKey ||
			event.altKey ||
			isTyping( event.target ) ||
			! canUndo()
		) {
			return;
		}

		const inLibrary = config.view !== 'modal';
		const inModal = !! event.target.closest?.( '.media-modal' );

		if ( ! inLibrary && ! inModal ) {
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();
		undo();
	},
	true
);

// Switching between grid and list keeps the open folder.
document.addEventListener( 'click', ( event ) => {
	const link = event.target.closest?.( '.view-switch a' );
	const view = getActiveView();

	if ( ! link || ! view ) {
		return;
	}

	const url = new URL( link.href );

	Object.entries(
		folderProps( config, view.getFolder(), getState().nested )
	).forEach( ( [ key, value ] ) =>
		value === null
			? url.searchParams.delete( key )
			: url.searchParams.set( key, value )
	);

	link.href = url.toString();
} );

domReady( () => {
	const notices = document.createElement( 'div' );
	notices.className = 'imd-notices-root';
	document.body.appendChild( notices );
	createRoot( notices ).render( <Notices /> );

	if ( config.view === 'list' ) {
		bootList();
	}
} );
