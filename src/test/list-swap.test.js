/**
 * The list view swaps rows instead of reloading; a throw here means a full
 * page reload for the user (see src/list-swap.js).
 */
import { swapTable } from '../list-swap';

// Shape of upload.php?mode=list as WordPress renders it.
function page( rows, displaying ) {
	const tablenav = ( which ) => `
		<div class="tablenav ${ which }">
			<div class="alignleft actions bulkactions"></div>
			<div class="tablenav-pages one-page"><span class="displaying-num">${ displaying }</span></div>
		</div>`;

	return `
		<form id="posts-filter">
			${ tablenav( 'top' ) }
			<table class="wp-list-table widefat">
				<thead><tr><td class="check-column"><input type="checkbox" id="cb-select-all-1"></td></tr></thead>
				<tbody id="the-list">${ rows
					.map(
						( id ) =>
							`<tr id="post-${ id }"><th class="check-column"><input type="checkbox" name="media[]" value="${ id }"></th></tr>`
					)
					.join( '' ) }</tbody>
				<tfoot><tr><td class="check-column"><input type="checkbox" id="cb-select-all-2"></td></tr></tfoot>
			</table>
			${ tablenav( 'bottom' ) }
		</form>`;
}

describe( 'swapTable', () => {
	beforeEach( () => {
		document.body.innerHTML = page( [ 1, 2, 3 ], '3 items' );
	} );

	it( 'replaces rows and pagination, unticks select-all', () => {
		const form = document.getElementById( 'posts-filter' );
		form.querySelector( '#cb-select-all-1' ).checked = true;

		const next = new window.DOMParser().parseFromString(
			page( [ 7, 8 ], '2 items' ),
			'text/html'
		);

		expect( swapTable( form, next ) ).toBe( true );
		expect(
			[ ...form.querySelectorAll( '#the-list tr' ) ].map(
				( row ) => row.id
			)
		).toEqual( [ 'post-7', 'post-8' ] );
		expect(
			[ ...form.querySelectorAll( '.displaying-num' ) ].map(
				( el ) => el.textContent
			)
		).toEqual( [ '2 items', '2 items' ] );
		expect( form.querySelector( '#cb-select-all-1' ).checked ).toBe(
			false
		);
	} );

	it( 'reports a page without a list instead of throwing', () => {
		const form = document.getElementById( 'posts-filter' );
		const next = new window.DOMParser().parseFromString(
			'<p>Login</p>',
			'text/html'
		);

		expect( swapTable( form, next ) ).toBe( false );
		expect( form.querySelectorAll( '#the-list tr' ) ).toHaveLength( 3 );
	} );
} );
