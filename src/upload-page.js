/**
 * Media → Add Media File (media-new.php): files go to the folder picked in
 * "Upload to folder" (rendered by `Admin\upload_folder_field()`).
 */
import {
	UNASSIGNED,
	config,
	getFolder,
	getLastFolder,
	rememberFolder,
} from './store';

/**
 * Preselect the last folder and stamp core's plupload uploads with the field.
 */
export default function bootUploadPage() {
	const select = document.getElementById( 'imd-upload-folder' );

	if ( ! select ) {
		return;
	}

	const last = getLastFolder();
	const linked = new URL( window.location.href ).searchParams.has(
		config.folderVar
	);

	if ( ! linked && typeof last === 'number' && getFolder( last ) ) {
		select.value = String( last );
	}

	select.addEventListener( 'change', () => {
		const folder = Number( select.value );
		rememberFolder( folder || UNASSIGNED );
	} );

	// Like media.js: take the folder when files are dropped or picked, not
	// when plupload has finished reading them.
	let picked = null;
	const pick = ( event ) => {
		const isFileDrop =
			event.type === 'drop' &&
			event.dataTransfer?.types?.includes( 'Files' );
		const isFilePick =
			event.type === 'change' && event.target?.type === 'file';

		if ( isFileDrop || isFilePick ) {
			picked = select.value;
		}
	};

	document.addEventListener( 'drop', pick, true );
	document.addEventListener( 'change', pick, true );

	// `window.uploader` is the global plupload instance core's handlers.js
	// creates on jQuery ready.
	const bind = () => {
		const uploader = window.uploader;

		if ( ! uploader?.bind ) {
			return false;
		}

		uploader.bind(
			'FilesAdded',
			( up, files ) => {
				const folder = picked ?? select.value;
				picked = null;
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

		return true;
	};

	window.jQuery( () => bind() || window.jQuery( window ).on( 'load', bind ) );
}
