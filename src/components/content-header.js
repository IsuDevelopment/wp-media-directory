/**
 * Breadcrumb and subfolders shown above the files.
 *
 * Subfolders are the part people missed in the tree, so they sit right where
 * the files are, and work as drop targets too.
 */
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import clsx from 'clsx';

import {
	ALL,
	UNASSIGNED,
	getChildren,
	getFolder,
	getPath,
	setNested,
} from '../store';
import { useSyncExternalStore } from '@wordpress/element';

import { folderIcon, useDropTarget, useStore } from './common';

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

function Tile( { folder, nested, onSelect } ) {
	const [ isOver, dropProps ] = useDropTarget( {
		folder: folder.id,
		folderDrop: folder.id,
	} );
	const subfolders = getChildren( folder.id ).length;
	const count = nested ? folder.total : folder.count;

	return (
		<li>
			<button
				type="button"
				className={ clsx( 'imd-tile', { 'is-drop-target': isOver } ) }
				onClick={ () => onSelect( folder.id ) }
				{ ...dropProps }
			>
				<span className="imd-tile__icon">{ folderIcon }</span>
				<span className="imd-tile__text">
					<span className="imd-tile__name">{ folder.name }</span>
					<span className="imd-tile__meta">
						{ [
							sprintf(
								/* translators: %d: number of files. */
								_n(
									'%d file',
									'%d files',
									count,
									'isudev-media-directory'
								),
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
							.join( ' · ' ) }
					</span>
				</span>
			</button>
		</li>
	);
}

export default function ContentHeader( { view } ) {
	// Re-render on tree changes; folder and nested follow what the grid shows.
	useStore();
	const { folder: current, nested } = useSyncExternalStore(
		view.subscribe,
		view.getShown
	);
	const folder = typeof current === 'number' ? getFolder( current ) : null;
	const tiles =
		current === UNASSIGNED ? [] : getChildren( folder ? folder.id : 0 );
	const hiddenFiles = folder && ! nested ? folder.total - folder.count : 0;

	if ( current === ALL && ! tiles.length ) {
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

			{ tiles.length > 0 && (
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
				</ul>
			) }
		</div>
	);
}
