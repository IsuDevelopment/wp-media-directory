/**
 * Swap the list table's rows and pagination from a fetched copy of the page.
 *
 * Pure DOM, no WordPress imports, so it is unit-tested (src/test/list-swap.test.js).
 * Only standard CSS selectors here: jQuery's `:checkbox` throws in
 * querySelectorAll, which once turned every folder switch into a full reload.
 */

/**
 * Swap rows and pagination from a freshly fetched copy of the page.
 *
 * @param {HTMLFormElement} form List form.
 * @param {Document}        next Fetched page.
 * @return {boolean} Whether the fetched page had a list to swap in.
 */
export function swapTable( form, next ) {
	const rows = next.getElementById( 'the-list' );
	const list = form.querySelector( '#the-list' );

	if ( ! rows || ! list ) {
		return false;
	}

	list.innerHTML = rows.innerHTML;

	const pages = form.querySelectorAll( '.tablenav .tablenav-pages' );
	const nextPages = next.querySelectorAll(
		'#posts-filter .tablenav .tablenav-pages'
	);

	pages.forEach( ( element, index ) => {
		if ( nextPages[ index ] ) {
			element.replaceWith(
				document.importNode( nextPages[ index ], true )
			);
		} else {
			element.remove();
		}
	} );

	form.querySelectorAll(
		'thead .check-column input[type="checkbox"], tfoot .check-column input[type="checkbox"]'
	).forEach( ( checkbox ) => ( checkbox.checked = false ) );

	return true;
}
