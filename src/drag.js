/**
 * What is being dragged right now.
 *
 * HTML5 drag data cannot be read during dragover, so the payload lives here
 * and the dataTransfer only carries a marker type.
 */
import { _n, sprintf } from '@wordpress/i18n';

export const DRAG_TYPE = 'application/x-imd-drag';

let payload = null;

export function getPayload() {
	return payload;
}

export function isOurDrag( event ) {
	return payload !== null && event.dataTransfer?.types?.includes( DRAG_TYPE );
}

/**
 * A small "3 files" chip instead of the browser's ghost of the whole tile.
 *
 * @param {string} label Text.
 * @return {HTMLElement} Chip, removed after the drag image is taken.
 */
function dragImage( label ) {
	const chip = document.createElement( 'div' );
	chip.className = 'imd-drag-chip';
	chip.textContent = label;
	document.body.appendChild( chip );
	window.setTimeout( () => chip.remove(), 0 );

	return chip;
}

/**
 * Start dragging files.
 *
 * @param {DragEvent}          event
 * @param {number[]}           ids   Attachment IDs.
 * @param {number|string|null} from  Folder being viewed.
 */
export function startFiles( event, ids, from ) {
	payload = { type: 'files', ids, from };

	event.dataTransfer.effectAllowed = 'copyMove';
	event.dataTransfer.setData( DRAG_TYPE, 'files' );
	event.dataTransfer.setData( 'text/plain', '' );
	event.dataTransfer.setDragImage(
		dragImage(
			sprintf(
				/* translators: %d: number of files. */
				_n(
					'%d file',
					'%d files',
					ids.length,
					'isudev-media-directory'
				),
				ids.length
			)
		),
		-12,
		-12
	);

	document.body.classList.add( 'imd-is-dragging' );
}

/**
 * Start dragging a folder.
 *
 * @param {DragEvent} event
 * @param {Object}    folder Folder.
 */
export function startFolder( event, folder ) {
	payload = { type: 'folder', id: folder.id };

	event.dataTransfer.effectAllowed = 'move';
	event.dataTransfer.setData( DRAG_TYPE, 'folder' );
	event.dataTransfer.setData( 'text/plain', folder.name );
	event.dataTransfer.setDragImage( dragImage( folder.name ), -12, -12 );

	document.body.classList.add( 'imd-is-dragging' );
}

export function end() {
	payload = null;
	document.body.classList.remove( 'imd-is-dragging' );
}

document.addEventListener( 'dragend', end );
document.addEventListener( 'drop', () => window.setTimeout( end, 0 ) );
