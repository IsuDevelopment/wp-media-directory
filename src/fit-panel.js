/**
 * Keep the folder panel in view and stretched to the bottom of the window,
 * so the tree scrolls inside it instead of being cut off.
 *
 * CSS sticky cannot do this: core's media frame hides overflow, which makes
 * the grid, not the window, the sticky container.
 *
 * @param {HTMLElement} sidebar Element holding `.imd-panel`.
 * @return {Function} Cleanup.
 */
export default function fitPanel( sidebar ) {
	let frame = null;
	const gap = 12;

	function fit() {
		frame = null;

		const panel = sidebar.querySelector( '.imd-panel' );

		// React may not have rendered yet.
		if ( ! panel ) {
			window.setTimeout( schedule, 50 );
			return;
		}

		// Narrow screens stack the tree above the files; nothing to follow.
		if ( window.matchMedia( '(max-width: 782px)' ).matches ) {
			panel.style.transform = '';
			return;
		}

		const adminBar =
			document.getElementById( 'wpadminbar' )?.offsetHeight || 0;
		const stickAt = adminBar + gap;
		const box = sidebar.getBoundingClientRect();
		const height = Math.max(
			200,
			window.innerHeight - Math.max( box.top, stickAt ) - gap
		);
		const offset = Math.min(
			Math.max( 0, stickAt - box.top ),
			Math.max( 0, box.height - height )
		);

		sidebar.style.setProperty( '--imd-panel-height', `${ height }px` );
		panel.style.transform = offset ? `translateY(${ offset }px)` : '';
	}

	function schedule() {
		if ( frame === null ) {
			frame = window.requestAnimationFrame( fit );
		}
	}

	const observer = new window.ResizeObserver( schedule );
	observer.observe( sidebar );

	window.addEventListener( 'scroll', schedule, { passive: true } );
	window.addEventListener( 'resize', schedule );
	schedule();

	return () => {
		observer.disconnect();
		window.removeEventListener( 'scroll', schedule );
		window.removeEventListener( 'resize', schedule );
		window.cancelAnimationFrame( frame );
	};
}
