/**
 * Small pieces shared by the sidebar and the content header.
 */
import {
	useEffect,
	useRef,
	useState,
	useSyncExternalStore,
} from '@wordpress/element';
import { SVG, Path } from '@wordpress/primitives';

import { can, getState, subscribe, fileItems, moveFolder } from '../store';
import { getPayload, isOurDrag, end } from '../drag';

export function useStore() {
	return useSyncExternalStore( subscribe, getState );
}

export function useViewFolder( view ) {
	return useSyncExternalStore( view.subscribe, view.getFolder );
}

export const folderIcon = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<Path d="M4.5 5.5h4.4l1.6 2h9a.5.5 0 0 1 .5.5v10a.5.5 0 0 1-.5.5h-15A.5.5 0 0 1 4 18V6a.5.5 0 0 1 .5-.5ZM3 6v12a1.5 1.5 0 0 0 1.5 1.5h15A1.5 1.5 0 0 0 21 18V8a1.5 1.5 0 0 0-1.5-1.5h-8.3l-1.6-2H4.5A1.5 1.5 0 0 0 3 6Z" />
	</SVG>
);

export const allFilesIcon = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<Path d="M5 5.5h4v4H5v-4Zm-1.5 0A1.5 1.5 0 0 1 5 4h4a1.5 1.5 0 0 1 1.5 1.5v4A1.5 1.5 0 0 1 9 11H5a1.5 1.5 0 0 1-1.5-1.5v-4ZM15 5.5h4v4h-4v-4Zm-1.5 0A1.5 1.5 0 0 1 15 4h4a1.5 1.5 0 0 1 1.5 1.5v4A1.5 1.5 0 0 1 19 11h-4a1.5 1.5 0 0 1-1.5-1.5v-4ZM5 14.5h4v4H5v-4Zm-1.5 0A1.5 1.5 0 0 1 5 13h4a1.5 1.5 0 0 1 1.5 1.5v4A1.5 1.5 0 0 1 9 20H5a1.5 1.5 0 0 1-1.5-1.5v-4Zm11.5 0h4v4h-4v-4Zm-1.5 0A1.5 1.5 0 0 1 15 13h4a1.5 1.5 0 0 1 1.5 1.5v4A1.5 1.5 0 0 1 19 20h-4a1.5 1.5 0 0 1-1.5-1.5v-4Z" />
	</SVG>
);

export const unfiledIcon = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<Path d="M4.5 5.5h4.4l1.6 2h9a.5.5 0 0 1 .5.5v10a.5.5 0 0 1-.5.5h-15A.5.5 0 0 1 4 18V6a.5.5 0 0 1 .5-.5ZM3 6v12a1.5 1.5 0 0 0 1.5 1.5h15A1.5 1.5 0 0 0 21 18V8a1.5 1.5 0 0 0-1.5-1.5h-8.3l-1.6-2H4.5A1.5 1.5 0 0 0 3 6Zm6.2 4.6 1.8 1.8-1.8 1.8 1 1 1.8-1.8 1.8 1.8 1-1-1.8-1.8 1.8-1.8-1-1-1.8 1.8-1.8-1.8-1 1Z" />
	</SVG>
);

/**
 * Make an element accept dragged files (and optionally folders).
 *
 * @param {Object}      args
 * @param {number|null} args.folder     Folder files land in; null rejects files.
 * @param {number|null} args.folderDrop Parent for dropped folders; null rejects folders.
 * @param {Function}    [args.onHover]  Called after hovering a while.
 * @return {Array} [ isOver, props ]
 */
export function useDropTarget( { folder, folderDrop, onHover } ) {
	const [ isOver, setIsOver ] = useState( false );
	const depth = useRef( 0 );
	const timer = useRef( null );

	useEffect( () => () => window.clearTimeout( timer.current ), [] );

	function accepts( event ) {
		const payload = getPayload();

		if ( ! payload || ! isOurDrag( event ) ) {
			return false;
		}

		if ( payload.type === 'files' ) {
			return can.organize && folder !== null && folder !== undefined;
		}

		return (
			can.manage &&
			folderDrop !== null &&
			folderDrop !== undefined &&
			payload.id !== folderDrop
		);
	}

	function reset() {
		depth.current = 0;
		setIsOver( false );
		window.clearTimeout( timer.current );
	}

	const props = {
		onDragEnter( event ) {
			if ( ! accepts( event ) ) {
				return;
			}

			event.preventDefault();
			depth.current += 1;
			setIsOver( true );

			if ( onHover && depth.current === 1 ) {
				timer.current = window.setTimeout( onHover, 700 );
			}
		},
		onDragOver( event ) {
			if ( ! accepts( event ) ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			event.dataTransfer.dropEffect =
				getPayload().type === 'files' && event.altKey ? 'copy' : 'move';
		},
		onDragLeave() {
			depth.current = Math.max( 0, depth.current - 1 );

			if ( depth.current === 0 ) {
				reset();
			}
		},
		onDrop( event ) {
			if ( ! accepts( event ) ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			reset();

			const payload = getPayload();
			end();

			if ( payload.type === 'files' ) {
				fileItems( {
					ids: payload.ids,
					folder,
					mode: event.altKey ? 'add' : 'move',
					from: payload.from,
				} ).catch( () => {} );
			} else {
				moveFolder( payload.id, folderDrop ).catch( () => {} );
			}
		},
	};

	return [ isOver, props ];
}

/**
 * Inline text field for naming a folder.
 *
 * @param {Object}   props
 * @param {string}   props.value    Starting value.
 * @param {string}   props.label    Accessible label.
 * @param {Function} props.onSubmit Called with the trimmed name.
 * @param {Function} props.onCancel Called on Escape or an empty name.
 */
export function NameInput( { value = '', label, onSubmit, onCancel } ) {
	const ref = useRef();
	const done = useRef( false );

	useEffect( () => {
		ref.current?.focus();
		ref.current?.select();
	}, [] );

	function finish( save ) {
		if ( done.current ) {
			return;
		}

		done.current = true;

		const name = ref.current.value.trim();

		if ( save && name && name !== value ) {
			onSubmit( name );
		} else {
			onCancel();
		}
	}

	return (
		<input
			ref={ ref }
			type="text"
			className="imd-name-input components-text-control__input"
			defaultValue={ value }
			aria-label={ label }
			maxLength={ 200 }
			onKeyDown={ ( event ) => {
				event.stopPropagation();

				if ( event.key === 'Enter' ) {
					event.preventDefault();
					finish( true );
				}

				if ( event.key === 'Escape' ) {
					event.preventDefault();
					finish( false );
				}
			} }
			onBlur={ () => finish( true ) }
			onClick={ ( event ) => event.stopPropagation() }
		/>
	);
}
