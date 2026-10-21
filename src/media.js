/**
 * Hook the folders into core's Backbone media views.
 *
 * AttachmentsBrowser is the file grid in both the Media Library and every
 * media modal, so patching it once covers all of them.
 */
import { createRoot } from '@wordpress/element';

import Sidebar from './components/sidebar';
import ContentHeader from './components/content-header';
import {
	ALL,
	UNASSIGNED,
	can,
	config,
	getFolder,
	getLastFolder,
	getPath,
	getState,
	isVisibleIn,
	isWithin,
	normalizeFolder,
	refresh,
	rememberFolder,
	subscribe,
} from './store';
import { createView, folderProps, getActiveView, setActiveView } from './view';
import { startFiles } from './drag';
import fitPanel from './fit-panel';

const { media } = window.wp;
const browsers = new Set();

/**
 * Backbone view that hosts a React root.
 *
 * @param {string}   className Class.
 * @param {Function} render    Returns the element to render.
 * @param {Function} [onMount] Runs once mounted; may return a cleanup.
 * @return {Function} View class.
 */
function reactView( className, render, onMount ) {
	return media.View.extend( {
		className,
		render() {
			if ( ! this.root ) {
				this.root = createRoot( this.el );
				this.root.render( render() );
				this.cleanup = onMount?.( this.el );
			}
			return this;
		},
		remove() {
			this.cleanup?.();
			this.root?.unmount();
			this.root = null;
			return media.View.prototype.remove.apply( this, arguments );
		},
	} );
}

/* ----------------------------------------------------------------------------
 * Folder label on tiles: where a file actually lives.
 * ------------------------------------------------------------------------- */

/**
 * Text for a tile's folder badge, or null when it would say nothing new.
 *
 * @param {number[]}           folders Folders of the file.
 * @param {number|string|null} current Folder being viewed.
 * @return {Object|null} { label, title }.
 */
function badgeFor( folders, current ) {
	const known = folders.filter( ( id ) => getFolder( id ) );
	const fullPath = ( id ) =>
		getPath( id )
			.map( ( item ) => item.name )
			.join( ' / ' );

	if ( ! known.length || current === UNASSIGNED ) {
		return null;
	}

	if ( typeof current === 'number' ) {
		if ( known.includes( current ) ) {
			return null;
		}

		const inside = known.find( ( id ) => isWithin( id, current ) );

		if ( ! inside ) {
			return null;
		}

		const path = getPath( inside );
		const start = path.findIndex( ( item ) => item.id === current ) + 1;

		const title = known.map( fullPath ).join( '\n' );

		return {
			label: path
				.slice( start )
				.map( ( item ) => item.name )
				.join( ' / ' ),
			title,
		};
	}

	const title = known.map( fullPath ).join( '\n' );
	const first = getFolder( known[ 0 ] ).name;

	return {
		label: known.length > 1 ? `${ first } +${ known.length - 1 }` : first,
		title,
	};
}

function decorate( element, model, current ) {
	const preview = element.querySelector( '.attachment-preview' );

	if ( ! preview || ! model ) {
		return;
	}

	preview.querySelector( '.imd-badge' )?.remove();

	const badge = badgeFor( model.get( 'imdFolders' ) || [], current );

	if ( ! badge ) {
		return;
	}

	const span = document.createElement( 'span' );
	span.className = 'imd-badge';
	span.title = badge.title;
	span.textContent = badge.label;
	preview.appendChild( span );
}

function decorateAll() {
	browsers.forEach( ( browser ) => {
		const current = browser.imdView.getFolder();

		browser
			.$( 'li.attachment[data-id]' )
			.each( ( i, element ) =>
				decorate(
					element,
					media.attachment( element.dataset.id ),
					current
				)
			);
	} );
}

const Attachment = media.view.Attachment;
const attachmentInit = Attachment.prototype.initialize;
const attachmentRender = Attachment.prototype.render;

Attachment.prototype.initialize = function () {
	attachmentInit.apply( this, arguments );
	this.listenTo( this.model, 'change:imdFolders', this.render );
};

Attachment.prototype.render = function () {
	attachmentRender.apply( this, arguments );

	const props = this.collection?.props;

	if ( props?.get( 'query' ) ) {
		decorate(
			this.el,
			this.model,
			normalizeFolder( props.get( config.folderVar ) )
		);
	}

	return this;
};

let lastTree = getState().tree;

subscribe( ( state ) => {
	if ( state.tree !== lastTree ) {
		lastTree = state.tree;
		decorateAll();
	}
} );

/* ----------------------------------------------------------------------------
 * The browser itself.
 * ------------------------------------------------------------------------- */

/**
 * Load files for new query props, then swap them in at once.
 *
 * Setting props on the live collection empties the grid first and refills
 * it when the server answers, which flashes and collapses the page. Here the
 * old files stay until the new page is ready.
 *
 * @param {Object} browser AttachmentsBrowser.
 * @param {Object} [next]  Props to change; none reloads the current ones.
 * @return {Promise} Resolves once swapped; never for a superseded load.
 */
function load( browser, next = {} ) {
	const collection = browser.collection;
	const token = ( browser.imdToken = ( browser.imdToken || 0 ) + 1 );
	const query = media.model.Query.get( {
		...collection.props.toJSON(),
		...next,
	} );
	const spinner = browser.toolbar?.get( 'spinner' );

	spinner?.show();
	browser.$el.addClass( 'imd-is-loading' );

	return new Promise( ( resolve ) => {
		query.more().always( () => {
			if ( token !== browser.imdToken ) {
				return;
			}

			spinner?.hide();
			browser.$el.removeClass( 'imd-is-loading' );
			collection.props.set( next, { silent: true } );
			collection.mirror( query );
			resolve();
		} );
	} );
}

function createBrowserView( browser, kind, initial ) {
	const show = ( folder, nested ) =>
		load( browser, folderProps( config, folder, nested ) ).then( () =>
			view.setShown( { folder, nested } )
		);

	const view = createView( {
		kind,
		initial,
		onChange( folder ) {
			show( folder, getState().nested );

			if ( kind === 'grid' ) {
				const url = new URL( window.location.href );

				if ( folder === ALL ) {
					url.searchParams.delete( config.folderVar );
				} else {
					url.searchParams.set( config.folderVar, folder );
				}

				window.history.replaceState( window.history.state, '', url );
			}
		},
		handlers: {
			onNestedChange( nested ) {
				show( view.getFolder(), nested );
			},
			onFiled( map, isUndo ) {
				const current = view.getFolder();
				const gone = [];

				Object.entries( map ).forEach( ( [ id, folders ] ) => {
					const model = media.model.Attachment.get( Number( id ) );
					model.set( 'imdFolders', folders.map( Number ) );

					if (
						! isVisibleIn(
							model.get( 'imdFolders' ),
							current,
							getState().nested
						)
					) {
						gone.push( model );
					}
				} );

				if ( isUndo ) {
					load( browser );
					return;
				}

				if ( gone.length ) {
					browser.options.selection?.remove( gone );
					browser.collection.remove( gone );
				}
			},
			onFoldersRemoved( ids ) {
				const current = view.getFolder();

				if ( ids.includes( current ) ) {
					view.setFolder( ALL );
				} else if ( current !== ALL ) {
					load( browser );
				}
			},
			onTreeMoved() {
				if (
					typeof view.getFolder() === 'number' &&
					getState().nested
				) {
					load( browser );
				}
			},
			onFilesDeleted( ids ) {
				ids.forEach( ( id ) => {
					const model = media.model.Attachment.get( id );
					// Collections drop a model on its destroy event.
					model.trigger( 'destroy', model, model.collection );
				} );
			},
		},
	} );

	view.browser = browser;

	return view;
}

const SidebarView = ( view ) =>
	reactView(
		'imd-sidebar',
		() => <Sidebar view={ view } />,
		view.kind === 'grid' ? fitPanel : undefined
	);
// The modal's "No items found" box sits over the files; keep it below the header.
const HeaderView = ( view, browser ) =>
	reactView(
		'imd-content-header-wrap',
		() => <ContentHeader view={ view } />,
		( element ) => {
			const observer = new window.ResizeObserver( () =>
				browser.el.style.setProperty(
					'--imd-header-height',
					`${ element.offsetHeight }px`
				)
			);
			observer.observe( element );
			return () => observer.disconnect();
		}
	);

/**
 * In the modal core scrolls the file list itself, which would leave the
 * header behind it. Scroll the wrapper instead, header included.
 *
 * @param {Object} browser AttachmentsBrowser.
 */
function scrollWrapper( browser ) {
	const list = browser.attachments;
	const wrapper = browser.attachmentsWrapper?.el;

	if ( ! list?.options.infiniteScrolling || ! wrapper ) {
		return;
	}

	window.jQuery( list.options.scrollElement ).off( 'scroll', list.scroll );
	list.options.scrollElement = wrapper;
	window.jQuery( wrapper ).on( 'scroll', list.scroll );
	browser.$el.addClass( 'imd-scroll-wrapper' );
}

const Browser = media.view.AttachmentsBrowser;
const browserInit = Browser.prototype.initialize;
const browserDispose = Browser.prototype.dispose;

Browser.prototype.initialize = function () {
	const props = this.collection?.props;
	// Only real library queries; skip "Edit gallery" and other selection-only grids.
	const enabled = !! props?.get( 'query' );
	let view = null;

	if ( enabled ) {
		const kind = this.controller.isModeActive?.( 'grid' )
			? 'grid'
			: 'modal';
		const initial =
			kind === 'grid' && config.folder !== null
				? normalizeFolder( config.folder )
				: getLastFolder();

		view = createBrowserView( this, kind, initial );
		rememberFolder( initial );

		// Before the first fetch, so the grid opens straight in the folder.
		props.set( folderProps( config, initial, getState().nested ) );
	}

	browserInit.apply( this, arguments );

	if ( ! view ) {
		return;
	}

	this.imdView = view;
	this.el.imdView = view;
	this.$el.addClass( 'imd-has-folders' );
	browsers.add( this );

	this.views.add( new ( SidebarView( view ) )(), { at: 0 } );
	this.views.add(
		'.attachments-wrapper',
		new ( HeaderView( view, this ) )(),
		{ at: 0 }
	);

	if ( view.kind === 'modal' ) {
		scrollWrapper( this );
	}

	// Uploads go to the folder of the view the pointer is in.
	this.$el.on( 'mouseenter dragenter', () => setActiveView( view ) );
};

Browser.prototype.dispose = function () {
	if ( this.imdView ) {
		this.imdView.unregister();
		browsers.delete( this );
	}

	return browserDispose.apply( this, arguments );
};

/* ----------------------------------------------------------------------------
 * Dragging tiles, shift/cmd selecting in the library grid.
 * ------------------------------------------------------------------------- */

function browserFor( element ) {
	return element.closest( '.imd-has-folders' )?.imdView?.browser || null;
}

document.addEventListener( 'mouseover', ( event ) => {
	const tile = event.target.closest?.( '.imd-has-folders li.attachment' );

	if ( tile && ! tile.draggable && can.organize ) {
		tile.draggable = true;
	}
} );

document.addEventListener( 'dragstart', ( event ) => {
	const tile = event.target.closest?.( '.imd-has-folders li.attachment' );
	const browser = tile && browserFor( tile );

	if ( ! browser || ! can.organize ) {
		return;
	}

	const id = Number( tile.dataset.id );
	const selection = browser.options.selection;
	const ids =
		selection?.get( id ) && selection.length > 1
			? selection.pluck( 'id' )
			: [ id ];

	startFiles( event, ids, browser.imdView.getFolder() );
} );

// In the library grid a plain click opens the file; shift/cmd/ctrl-click
// switches to bulk select instead, so ranges work without hunting for the button.
document.addEventListener(
	'click',
	( event ) => {
		if ( ! ( event.shiftKey || event.metaKey || event.ctrlKey ) ) {
			return;
		}

		const tile = event.target.closest?.( '.imd-has-folders li.attachment' );
		const frame = tile && browserFor( tile )?.controller;

		if (
			frame?.isModeActive?.( 'grid' ) &&
			! frame.isModeActive( 'select' )
		) {
			frame.deactivateMode( 'edit' ).activateMode( 'select' );
		}
	},
	true
);

/* ----------------------------------------------------------------------------
 * Uploads: stamp each file with its folder when queued.
 * ------------------------------------------------------------------------- */

// plupload reads dropped files asynchronously, so FilesAdded can fire after
// the user has already clicked another folder. Take the folder at drop/pick time.
let pickedFolder = null;

function pickFolder( event ) {
	const isFileDrop =
		event.type === 'drop' && event.dataTransfer?.types?.includes( 'Files' );
	const isFilePick = event.type === 'change' && event.target?.type === 'file';

	if ( isFileDrop || isFilePick ) {
		pickedFolder = getActiveView()?.uploadFolder() || 0;
	}
}

document.addEventListener( 'drop', pickFolder, true );
document.addEventListener( 'change', pickFolder, true );

const uploaderInit = window.wp.Uploader.prototype.init;

window.wp.Uploader.prototype.init = function () {
	uploaderInit.apply( this, arguments );

	const uploader = this.uploader;

	uploader.bind(
		'FilesAdded',
		( up, files ) => {
			const folder =
				pickedFolder ?? ( getActiveView()?.uploadFolder() || 0 );
			pickedFolder = null;
			files.forEach( ( file ) => ( file.imdFolder = folder ) );
		},
		null,
		100
	);

	uploader.bind(
		'BeforeUpload',
		( up, file ) => {
			up.setOption( 'multipart_params', {
				...( up.getOption( 'multipart_params' ) || {} ),
				[ config.folderVar ]: file.imdFolder || '',
			} );
		},
		null,
		100
	);

	uploader.bind( 'UploadComplete', refresh );
};
