/**
 * "Change color" for a folder: core's colour palette in a popover.
 */
import { ColorPalette, Popover } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getFolder, setFolderColor } from '../store';
import { useStore } from './common';

export function folderColors() {
	return [
		{ name: __( 'Red', 'isudev-media-directory' ), color: '#e2483d' },
		{ name: __( 'Orange', 'isudev-media-directory' ), color: '#f0883e' },
		{ name: __( 'Yellow', 'isudev-media-directory' ), color: '#f2c037' },
		{ name: __( 'Green', 'isudev-media-directory' ), color: '#3fa35b' },
		{ name: __( 'Teal', 'isudev-media-directory' ), color: '#2a9d99' },
		{ name: __( 'Blue', 'isudev-media-directory' ), color: '#3858e9' },
		{
			name: __( 'Light blue', 'isudev-media-directory' ),
			color: '#72aee6',
		},
		{ name: __( 'Purple', 'isudev-media-directory' ), color: '#7a4fd0' },
		{ name: __( 'Pink', 'isudev-media-directory' ), color: '#d6559b' },
		{ name: __( 'Brown', 'isudev-media-directory' ), color: '#a2775a' },
		{ name: __( 'Gray', 'isudev-media-directory' ), color: '#8c8f94' },
		{ name: __( 'Dark gray', 'isudev-media-directory' ), color: '#3c434a' },
	];
}

/**
 * Colour picker for one folder; every pick is saved (and undoable) at once.
 *
 * @param {Object}   props
 * @param {number}   props.id        Folder ID.
 * @param {Object}   props.anchor    Element or virtual anchor.
 * @param {string}   props.placement Popover placement.
 * @param {Function} props.onClose   Close the popover.
 */
export default function FolderColorPopover( {
	id,
	anchor,
	placement = 'right-start',
	onClose,
} ) {
	useStore();
	const folder = getFolder( id );
	const saved = folder?.color || '';
	const [ value, setValue ] = useState( saved );
	// The custom picker reports every drag step; save once it settles, and
	// whatever is still pending when the popover closes.
	const pending = useRef( {} );

	useEffect( () => setValue( saved ), [ saved ] );
	useEffect( () => {
		const box = pending.current;

		return () => {
			window.clearTimeout( box.timer );
			box.flush?.();
		};
	}, [] );

	function save( color ) {
		const box = pending.current;

		window.clearTimeout( box.timer );
		box.flush = () => {
			box.flush = null;
			setFolderColor( id, color ).catch( () => {} );
		};
		box.timer = window.setTimeout( box.flush, 400 );
	}

	if ( ! folder ) {
		return null;
	}

	return (
		<Popover
			anchor={ anchor }
			placement={ placement }
			onClose={ onClose }
			className="imd-color-popover"
		>
			<div className="imd-color-popover__inner">
				<ColorPalette
					colors={ folderColors() }
					value={ value || undefined }
					onChange={ ( color ) => {
						const next = ( color || '' ).toLowerCase();
						setValue( next );
						save( next );
					} }
					enableAlpha={ false }
					clearable
				/>
			</div>
		</Popover>
	);
}
