<?php
/*
Plugin Name: Attachments from FTP - import from folder
Description: Create attachment posts from files in a folder on the server.
Plugin URI: https://github.com/markhowellsmead/mhm-attachment-from-ftp
Text Domain: mhm-attachment-from-ftp
Author: Mark Howells-Mead
Author URI: https://permanenttourist.ch/
Version: 0.6.0
*/

namespace MHM\WordPress\AttachmentFromFtp;

use WP_Query;

class Plugin
{
	public $wpversion = '5.3';
	public $frequency = 'minute';
	private $sourceFolder = '';
	private $author_id = -1;
	private $allowed_file_types = [];
	private $options = [];
	private $flickr_config = [];

	public function __construct()
	{
		register_activation_hook(__FILE__, [$this, 'activation']);
		register_deactivation_hook(__FILE__, [$this, 'deactivation']);

		require_once 'OptionsPage.php';

		$this->options = get_option('mhm_attachment_from_ftp');

		$this->setThings();

		add_action('admin_init', [$this, 'checkVersion']);
		add_action('admin_menu', [$this, 'adminListViewPages']);
		add_filter('cron_schedules', [$this, 'cronInterval']);
		add_action('mhm_attachment_from_ftp_check_folder', [$this, 'checkFolder']);
		add_filter('wp_read_image_metadata', [$this, 'additionalImageMeta'], 10, 3);
		add_action('admin_enqueue_scripts', [$this, 'flickrScripts'], 10, 1);
		add_action('rest_api_init', [$this, 'addMetaFields']);
		add_action('rest_insert_post', [$this, 'maybeAddTerms'], 1, 3);
	}

	private function error_log($title, $content = '')
	{
		if (is_array($content)) {
			$content = print_r($content, 1);
		}
		error_log(date('r').chr(9).$title.chr(9).$content.chr(10), 3, WP_CONTENT_DIR.'/mhm_attachment_from_ftp.error.log');
	}

	private function success_log($title, $content = '')
	{
		if (is_array($content)) {
			$content = print_r($content, 1);
		}
		error_log(date('r').chr(9).$title.chr(9).$content.chr(10), 3, WP_CONTENT_DIR.'/mhm_attachment_from_ftp.success.log');
	}

	public function activation()
	{
		$this->checkVersion();

		if (!wp_next_scheduled('mhm_attachment_from_ftp_check_folder')) {
			wp_schedule_event(time(), $this->frequency, 'mhm_attachment_from_ftp_check_folder');
		}
		$this->setThings();
	}

	public function deactivation()
	{
		wp_unschedule_event(time(), 'mhm_attachment_from_ftp_check_folder');
		wp_clear_scheduled_hook('mhm_attachment_from_ftp_check_folder');
	}

	public function checkVersion()
	{
		// Check that this plugin is compatible with the current version of WordPress
		if (!$this->compatibleVersion()) {
			if (is_plugin_active('mhm-attachment-from-ftp')) {
				deactivate_plugins('mhm-attachment-from-ftp');
				add_action('admin_notices', [$this, 'disabledNotice']);
				if (isset($_GET['activate'])) {
					unset($_GET['activate']);
				}
			}
		}
	}

	public function disabledNotice()
	{
		$message = sprintf(
			__('The plugin “%1$s” requires WordPress %2$s or higher!', 'mhm-attachment-from-ftp'),
			_x('Attachments from FTP', 'The name of the plugin', 'mhm-attachment-from-ftp'),
			$this->wpversion
		);

		printf(
			'<div class="notice notice-error is-dismissible"><p>%1$s</p></div>',
			$message
		);
	}

	private function compatibleVersion()
	{
		if (version_compare($GLOBALS['wp_version'], $this->wpversion, '<')) {
			return false;
		}

		return true;
	}

	public function cronInterval()
	{
		if (!isset($schedules['minute'])) {
			$schedules['minute'] = [
				'interval' => 60,
				'display' => __('Every minute')
			];
		}
		return $schedules;
	}

	private function sanitizeFileName($file)
	{
		$path_original = $file->getPathName();
		$filtered_filename = preg_replace('~\s~', '_', $file->getFileName());
		$filtered_path = str_replace($file->getFilename(), $filtered_filename, $path_original);

		rename($path_original, $filtered_path);

		return $filtered_path;
	}

	/**
	 * The main function, which is called by the cron task registered by
	 * mhm_attachment_from_ftp_check_folder. If all is well, no text is
	 * output (and therefore no message will appear).
	 */
	public function checkFolder()
	{
		$this->setThings();

		if (empty($this->sourceFolder) || !$this->author_id) {
			return;
		}

		$files = $this->getFiles();

		if (empty($files)) {
			do_action('mhm-attachment-from-ftp/no_files', $this->sourceFolder);
			return;
		}

		$entries = [];

		foreach ($files as $file) {
			$file_path = $this->sanitizeFileName($file);

			$exif = $this->buildEXIFArray($file_path, false);

			if (!$exif['DateTimeOriginal']) {
				/*
				 * Only images where the original capture date can be identified
				 * can currently be processed.
				 */
				do_action('mhm-attachment-from-ftp/no_file_date', $file_path, $exif);
				$this->error_log('mhm-attachment-from-ftp/no_file_date', [$file_path, $exif]);
				continue;
			}

			if ($filesize = round(filesize($file_path) / 1024 / 1024) > 10) {
				do_action('mhm-attachment-from-ftp/too_big', $file_path, $filesize.' Mb');
				$this->error_log('mhm-attachment-from-ftp/too_big', [$file_path, $filesize.' Mb']);
				continue;
			}

			$target_folder = $_SERVER['DOCUMENT_ROOT'].'/wp-content/uploads'.date('/Y/m/', strtotime($exif['DateTimeOriginal']));

			$entries[strtotime($exif['DateTime'])] = [
				'post_author' => $this->author_id,
				'post_type' => $this->post_type,
				'post_title' => (string) $exif['iptc']['graphic_name'],
				'post_content' => (string) $exif['iptc']['caption'],
				'post_tags' => $exif['iptc']['keywords'],
				'file_date' => $exif['DateTimeOriginal'],
				'source_path' => $file_path,
				'target_path' => $target_folder.$file->getFileName(),
				'target_folder' => $target_folder,
				'file_name' => $file->getFileName(),
				'post_meta' => [],
			];


			/**
			 * Attachment posts do not receive post_meta by default, as the image's EXIF metadata is stored
			 * to the database using wp_generate_attachment_metadata. This is a temporary extension to allow
			 * the $entries array to contain this information, so that additional plugin mhm-attachment-from-ftp-publish
			 * can access it.
			 */
			if (isset($exif['GPSLatitudeDecimal']) && isset($exif['GPSLongitudeDecimal']) && isset($exif['GPSCalculatedDecimal'])) {
				$entries[strtotime($exif['DateTime'])]['post_meta']['geo_latitude'] = (float) $exif['GPSLatitudeDecimal'];
				$entries[strtotime($exif['DateTime'])]['post_meta']['geo_longitude'] = (float) $exif['GPSLongitudeDecimal'];
				$entries[strtotime($exif['DateTime'])]['post_meta']['location'] = (string) $exif['GPSCalculatedDecimal'];
			}
		}

		if (empty($entries)) {
			do_action('mhm-attachment-from-ftp/no_valid_entries', $this->basePath, $files);
			$this->error_log('mhm-attachment-from-ftp/no_valid_entries', [$this->basePath, $files]);
			exit;
		}

		// Handle older photos first, sorted by EXIF DateTime parameter.
		sort($entries);

		// Limit the amount of photos parsed in one run.
		$per_batch = (int) $this->options['files_per_batch'];
		$entries = array_slice($entries, 0, max($per_batch, 0), true);

		if (count($entries)) {
			$processed_entries = 0;

			foreach ($entries as $entry) {
				$stored = (bool) $this->storeImage($entry);

				if ($stored) {
					// Create or update existing Attachment Posts and generate thumbnails
					$this->handleAttachment($entry);
					++$processed_entries;
				}
			}
		}

		do_action('mhm-attachment-from-ftp/finished', $entries, $processed_entries);
		$this->success_log('mhm-attachment-from-ftp/finished', [$entries, $processed_entries]);
	}

	/**
	 * Display a message in wp-admin to advise the user that the source folder
	 * has not been selected in the plugin options.
	 */
	private function sourceFolderUndefined()
	{
		add_action('admin_notices', function () {
			$class = 'notice notice-error';
			$message = sprintf(
				__('Please %1$s for the plugin “%2$s”.', 'mhm-attachment-from-ftp'),
				sprintf(
					'<a href="%s">%s</a>',
					admin_url('options-general.php?page=mhm_attachment_from_ftp'),
					__('select a source folder', 'mhm-attachment-from-ftp')
				),
				__('Attachments from FTP', 'mhm-attachment-from-ftp')
			);

			printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);
		});
		do_action('mhm-attachment-from-ftp/source-folder-undefined', $this->sourceFolder);
		$this->error_log('mhm-attachment-from-ftp/source-folder-undefined', $this->sourceFolder);
	}

	/**
	 * Display a message in wp-admin to advise the user that the post author
	 * has not been selected in the plugin options.
	 */
	private function postAuthorUndefined()
	{
		add_action('admin_notices', function () {
			$class = 'notice notice-error';
			$message = sprintf(
				__('Please %1$s for the plugin “%2$s”.', 'mhm-attachment-from-ftp'),
				sprintf(
					'<a href="%s">%s</a>',
					admin_url('options-general.php?page=mhm_attachment_from_ftp'),
					__('select the post author', 'mhm-attachment-from-ftp')
				),
				__('Attachments from FTP', 'mhm-attachment-from-ftp')
			);

			printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);
		});
		do_action('mhm-attachment-from-ftp/post-author-undefined', $this->sourceFolder);
		$this->error_log('mhm-attachment-from-ftp/post-author-undefined', $this->settings);
	}

	private function setAllowedFileTypes()
	{
		$this->allowed_file_types = apply_filters('mhm-attachment-from-ftp/allowed-file-types', [
			'image/jpeg',
			'image/gif',
			'image/png',
			'image/bmp',
			'image/tiff',
		]);
	}

	private function setThings()
	{
		$this->setPostAuthor();
		$this->setAllowedFileTypes();
		$this->setSourceFolder();
	}

	/**
	 * Get the source folder value from the plugin options.
	 */
	public function setSourceFolder()
	{
		$upload_dir = wp_upload_dir();
		$sourceFolder = esc_attr($this->options['source_folder']);

		if (!$sourceFolder) {
			$this->sourceFolderUndefined();
		} else {
			$this->sourceFolder = trailingslashit($upload_dir['basedir']).esc_attr($this->options['source_folder']);

			if (!is_dir($this->sourceFolder)) {
				@mkdir($this->sourceFolder, 0755, true);
				if (is_dir($this->sourceFolder)) {
					do_action('mhm-attachment-from-ftp/source-folder-unavailable', $this->sourceFolder);
					$this->error_log('mhm-attachment-from-ftp/source-folder-unavailable', $this->sourceFolder);
				}
			}
		}
	}

	/**
	 * Get the post author value from the plugin options.
	 */
	public function setPostAuthor()
	{
		$this->options = get_option('mhm_attachment_from_ftp');
		$this->author_id = (int) $this->options['author_id'];

		if (!$this->author_id) {
			$this->postAuthorUndefined();
		}
	}

	/**
	 * Get a list of files from the source folder.
	 *
	 * @return array A list of the files which are available to be processed.
	 */
	private function getFiles()
	{
		if (!is_dir($this->sourceFolder)) {
			return false;
		}
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->sourceFolder), \RecursiveIteratorIterator::CHILD_FIRST);
		$out = [];
		foreach ($iterator as $path) {
			if (!is_file($path) || strpos($path, '.DS_Store') !== false) {
				continue;
			}
			$filetype = wp_check_filetype($path);
			if (in_array($filetype['type'], $this->allowed_file_types)) {
				$out[] = $path;
			} else {
				do_action('mhm-attachment-from-ftp/filetype-not-allowed', $path, $filetype['type'], $this->allowed_file_types);
				$this->error_log('mhm-attachment-from-ftp/filetype-not-allowed', [$path, $filetype, $this->allowed_file_types]);
			}
		}

		return apply_filters('mhm-attachment-from-ftp/files-in-folder', $out);
	}

	/**
	 * Move a file from one folder to another on the server.
	 *
	 * @param string $currentpath     The fully-qualified path of the file's current location.
	 * @param string $destinationpath The fully-qualified path of the file's new location, including the file name.
	 *
	 * @return bool True if successful, false if not.
	 */
	private function moveFile($currentpath, $destinationpath)
	{
		if (!is_file($currentpath)) {
			return false;
		}

		return @rename($currentpath, $destinationpath) ? $destinationpath : false;
	}

	/**
	 * Extend the basic meta data stored in the database with additional values.
	 *
	 * @param array  $meta            The array of meta data which WordPress usually stores in the database.
	 * @param string $file            The fully-qualified path to the file being processed.
	 * @param int    $sourceImageType The MIME type of the file being processed, in binary integer format.
	 *
	 * @return array The processed and extended meta data.
	 */
	public function additionalImageMeta($meta, $file, $sourceImageType)
	{
		$image_file_types = apply_filters(
			'wp_read_image_metadata_types',
			[IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM]
		);

		if (in_array($sourceImageType, $image_file_types) && function_exists('iptcparse')) {
			getimagesize($file, $info);
			if (isset($info['APP13'])) {
				$iptc = iptcparse($info['APP13']);
				if ($iptc && isset($iptc['2#015'])) {
					$meta['category'] = $iptc['2#015'];
					$meta['keywords'] = $iptc['2#025'];
				}
			}
		}

		return $meta;
	}

	/**
	 * Moves the file from the original location to the target location.
	 * If a file in the target location already exists, it will be overwritten.
	 *
	 * @param array $post_data The image data build in checkFolder()
	 *
	 * @return bool True on success, false on fail.
	 */
	public function storeImage($post_data)
	{
		/*
		 * Make sure that the target folder exists.
		 */
		if (!is_dir($post_data['target_folder'])) {
			@mkdir($post_data['target_folder'], 0755, true);
			if (!is_dir($post_data['target_folder'])) {
				do_action('mhm-attachment-from-ftp/target_folder_missing', $post_data['target_folder']);
				$this->error_log('mhm-attachment-from-ftp/target_folder_missing', $post_data);
				return false;
			}
		}

		$file_moved = $this->moveFile($post_data['source_path'], $post_data['target_path']);

		if ($file_moved) {
			do_action('mhm-attachment-from-ftp/file_moved', $post_data['source_path'], $post_data['target_path']);
		} else {
			do_action('mhm-attachment-from-ftp/file_not_moved', $post_data['source_path'], $post_data['target_path']);
			$this->error_log('mhm-attachment-from-ftp/file_not_moved', [$post_data['source_path'], $post_data['target_path']]);
		}

		return $file_moved;
	}

	/**
	 * Helper function which “cleans” the entries in $array by passing them
	 * through the function $function.
	 *
	 * @param string $function The name of the function to use when “cleaning”
	 *                         the array.
	 * @param array  $array    The array to be cleaned.
	 *
	 * @return array The cleaned array.
	 */
	public function arrayMapRecursive($function, $array)
	{
		$newArr = [];

		foreach ($array as $key => $value) {
			$newArr[ $key ] = (is_array($value) ? $this->arrayMapRecursive($function, $value) : (is_array($function) ? call_user_func_array($function, $value) : $function($value)));
		}

		return $newArr;
	}

	public function sanitizeData(&$data)
	{
		$data = $this->arrayMapRecursive('strip_tags', $data);
	}

	/**
	 * Get the Attachment which matches the specified file path.
	 * If no matching Attachment is found, the function returns 0.
	 *
	 * @param string $path The fully-qualified path to the file.
	 *
	 * @return int The ID of the Attachment, or 0 if none is found.
	 */
	private function getAttachmentId($path)
	{
		$attachment_id = 0;
		$dir = wp_upload_dir();

		// Is the file somewhere in the in uploads directory?
		if (false !== strpos($path, $dir['basedir'].'/')) {
			$file = basename($path);
			$query_args = [
				'post_type' => 'attachment',
				'post_status' => 'inherit',
				'fields' => 'ids',
				'meta_query' => [
					[
						'value' => $file,
						'compare' => 'LIKE',
						'key' => '_wp_attachment_metadata',
					],
				],
			];
			$query = new WP_Query($query_args);
			if ($query->have_posts()) {
				foreach ($query->posts as $post_id) {
					$meta = wp_get_attachment_metadata($post_id);
					$original_file = basename($meta['file']);
					$cropped_image_files = wp_list_pluck($meta['sizes'], 'file');
					if ($original_file === $file || in_array($file, $cropped_image_files)) {
						$attachment_id = $post_id;
						break;
					}
				}
			}
		}

		return $attachment_id;
	}

	/**
	 * Add or update a database Post entry of type Attachment.
	 *
	 * @param array $post_data An associative array containing all the data to be stored.
	 *
	 * @return int The ID of the new (or pre-existing) Post.
	 */
	public function handleAttachment($post_data)
	{
		$target_path = $post_data['target_path'];

		$attachment_id = $this->getAttachmentId($target_path);

		if ($attachment_id) {
			/*
			 * Entry exists. Update it and re-generate thumbnails. Title and description are only upated if the
			 * appropriate blocking option “no_overwrite_title_description” is not activated in the plugin options.
			 */

			if (!(bool) $this->options['no_overwrite_title_description']) {
				$wp_filetype = wp_check_filetype(basename($target_path), null);
				$info = pathinfo($target_path);
				$attachment = [
					'ID' => $attachment_id,
					'post_author' => $post_data['post_author'],
					'post_content' => $post_data['post_content'],
					'post_excerpt' => $post_data['post_content'],
					'post_mime_type' => $wp_filetype['type'],
					'post_name' => $info['filename'],
					'post_status' => 'inherit',
					'post_title' => $post_data['post_title'],
				];
				$attachment_id = wp_update_post($attachment);
				do_action('mhm-attachment-from-ftp/title_description_overwritten', $attachment_id, $attachment);
				$this->success_log('mhm-attachment-from-ftp/title_description_overwritten', [$attachment_id, $attachment]);
			}
			$this->thumbnailsAndMeta($attachment_id, $target_path);
			do_action('mhm-attachment-from-ftp/attachment_updated', $attachment_id);
			$this->success_log('mhm-attachment-from-ftp/attachment_updates', [$attachment_id]);
		} else {
			/*
			 * Create new attachment entry and generate thumbnails.
			 */
			$wp_filetype = wp_check_filetype(basename($target_path), null);
			$info = pathinfo($target_path);
			$attachment = [
				'post_author' => $post_data['post_author'],
				'post_content' => $post_data['post_content'],
				'post_excerpt' => $post_data['post_content'],
				'post_mime_type' => $wp_filetype['type'],
				'post_name' => $info['filename'],
				'post_status' => 'inherit',
				'post_title' => $post_data['post_title'],
			];
			$attachment_id = wp_insert_attachment($attachment, $target_path);
			$this->thumbnailsAndMeta($attachment_id, $target_path);
			do_action('mhm-attachment-from-ftp/attachment_created', $attachment_id);
			$this->success_log('mhm-attachment-from-ftp/attachment_created', [$attachment_id]);
		}

		/*
		  * Add the image's title attribute to the alt text field of the Attachment.
		  * This only happens if there is no pre-existing alt text stored for the image.
		  */
		if (!get_post_meta($attachment_id, '_wp_attachment_image_alt', true)) {
			add_post_meta($attachment_id, '_wp_attachment_image_alt', $post_data['post_title']);
		}
	}

	/**
	 * Update metadata entries in the database and generate
	 * thumbnails from the new, parsed image file.
	 *
	 * @param int    $post_id The ID of the Attachment post
	 * @param string $path    The path to the new image file.
	 */
	public function thumbnailsAndMeta($post_id, $path)
	{
		require_once ABSPATH.'wp-admin/includes/image.php';

		// This generates the thumbnail file/s.
		$attach_data = wp_generate_attachment_metadata($post_id, $path);

		wp_update_attachment_metadata($post_id, $attach_data);

		do_action('mhm-attachment-from-ftp/updated_attachment_metadata', $post_id, $path, $attach_data);
		$this->success_log('mhm-attachment-from-ftp/updated_attachment_metadata', [$post_id, $path, $attach_data]);
	}

	/**
	 * Convert GPS DMS (degrees, minutes, seconds) to decimal format
	 * (longitude/latitude).
	 *
	 * @param int $deg Degrees
	 * @param int $min Minutes
	 * @param int $sec Seconds
	 *
	 * @return int The converted decimal-format value.
	 */
	private static function DMStoDEC($deg, $min, $sec)
	{
		return $deg + ((($min * 60) + ($sec)) / 3600);
	}

	/**
	 * Read the EXIF/IPTC data from a file on the file system and
	 * return it as an associative array.
	 *
	 * @param string $source_path     The fully-qualified path to the file.
	 * @param bool   $onlyWithGPSData Should the associative array be left
	 *                                empty if there is no GPS meta data
	 *                                available in the source file?
	 *
	 * @return array The array containing the parsed EXIF/IPTC data
	 */
	public function buildEXIFArray($source_path, $onlyWithGPSData = false)
	{
		$exif = @exif_read_data($source_path, 'ANY_TAG');

		if (!$exif || ($onlyWithGPSData && (!isset($exif['GPSLongitude']) || !isset($exif['GPSLongitude'])))) {
			return false;
		}

		/*
		Example of the values in the file's EXIF data:

		[GPSLatitudeRef] => N
		[GPSLatitude] => Array
		(
		[0] => 57/1
		[1] => 31/1
		[2] => 21334/521
		)

		[GPSLongitudeRef] => W
		[GPSLongitude] => Array
		(
		[0] => 4/1
		[1] => 16/1
		[2] => 27387/1352
		)
		*/

		$GPS = [];

		if (isset($exif['GPSLatitude'])) {
			$GPS['lat']['deg'] = explode('/', $exif['GPSLatitude'][0]);
			$GPS['lat']['deg'] = $GPS['lat']['deg'][1] > 0 ? $GPS['lat']['deg'][0] / $GPS['lat']['deg'][1] : 0;
			$GPS['lat']['min'] = explode('/', $exif['GPSLatitude'][1]);
			$GPS['lat']['min'] = $GPS['lat']['min'][1] > 0 ? $GPS['lat']['min'][0] / $GPS['lat']['min'][1] : 0;
			$GPS['lat']['sec'] = explode('/', $exif['GPSLatitude'][2]);

			$lat_sec_0 = floatval($GPS['lat']['sec'][0]);
			$lat_sec_1 = floatval($GPS['lat']['sec'][1]);

			if ($lat_sec_0 > 0 && $lat_sec_1 > 0) {
				$GPS['lat']['sec'] = $lat_sec_0 / $lat_sec_1;
			} else {
				$GPS['lat']['sec'] = 0;
			}

			$exif['GPSLatitudeDecimal'] = self::DMStoDEC($GPS['lat']['deg'], $GPS['lat']['min'], $GPS['lat']['sec']);
			if ($exif['GPSLatitudeRef'] == 'S') {
				$exif['GPSLatitudeDecimal'] = 0 - $exif['GPSLatitudeDecimal'];
			}
		} else {
			$exif['GPSLatitudeDecimal'] = null;
			$exif['GPSLatitudeRef'] = null;
		}

		if (isset($exif['GPSLongitude'])) {
			$GPS['lon']['deg'] = explode('/', $exif['GPSLongitude'][0]);
			$GPS['lon']['deg'] = $GPS['lon']['deg'][1] > 0 ? $GPS['lon']['deg'][0] / $GPS['lon']['deg'][1] : 0;
			$GPS['lon']['min'] = explode('/', $exif['GPSLongitude'][1]);
			$GPS['lon']['min'] = $GPS['lon']['min'][1] > 0 ? $GPS['lon']['min'][0] / $GPS['lon']['min'][1] : 0;
			$GPS['lon']['sec'] = explode('/', $exif['GPSLongitude'][2]);

			$lon_sec_0 = floatval($GPS['lon']['sec'][0]);
			$lon_sec_1 = floatval($GPS['lon']['sec'][1]);

			if ($lon_sec_0 > 0 && $lon_sec_1 > 0) {
				$GPS['lon']['sec'] = $lon_sec_0 / $lon_sec_1;
			} else {
				$GPS['lon']['sec'] = 0;
			}

			$exif['GPSLongitudeDecimal'] = $this->DMStoDEC($GPS['lon']['deg'], $GPS['lon']['min'], $GPS['lon']['sec']);
			if ($exif['GPSLongitudeRef'] == 'W') {
				$exif['GPSLongitudeDecimal'] = 0 - $exif['GPSLongitudeDecimal'];
			}
		} else {
			$exif['GPSLongitudeDecimal'] = null;
			$exif['GPSLongitudeRef'] = null;
		}

		if ($exif['GPSLatitudeDecimal'] && $exif['GPSLongitudeDecimal']) {
			$exif['GPSCalculatedDecimal'] = $exif['GPSLatitudeDecimal'].','.$exif['GPSLongitudeDecimal'];
		} else {
			$exif['GPSCalculatedDecimal'] = null;
		}

		$size = @getimagesize($source_path, $info);
		if ($size && isset($info['APP13'])) {
			$iptc = iptcparse($info['APP13']);

			if (is_array($iptc)) {
				$exif['iptc']['caption'] = isset($iptc['2#120']) ? $iptc['2#120'][0] : '';
				$exif['iptc']['graphic_name'] = isset($iptc['2#005']) ? $iptc['2#005'][0] : '';
				$exif['iptc']['urgency'] = isset($iptc['2#010']) ? $iptc['2#010'][0] : '';
				$exif['iptc']['category'] = @$iptc['2#015'][0];

				// supp_categories sometimes contains multiple entries!
				$exif['iptc']['supp_categories'] = @$iptc['2#020'][0];
				$exif['iptc']['spec_instr'] = @$iptc['2#040'][0];
				$exif['iptc']['creation_date'] = @$iptc['2#055'][0];
				$exif['iptc']['photog'] = @$iptc['2#080'][0];
				$exif['iptc']['credit_byline_title'] = @$iptc['2#085'][0];
				$exif['iptc']['city'] = @$iptc['2#090'][0];
				$exif['iptc']['state'] = @$iptc['2#095'][0];
				$exif['iptc']['country'] = @$iptc['2#101'][0];
				$exif['iptc']['otr'] = @$iptc['2#103'][0];
				$exif['iptc']['headline'] = @$iptc['2#105'][0];
				$exif['iptc']['source'] = @$iptc['2#110'][0];
				$exif['iptc']['photo_source'] = @$iptc['2#115'][0];
				$exif['iptc']['caption'] = @$iptc['2#120'][0];

				$exif['iptc']['keywords'] = @$iptc['2#025'];
			}
		}

		return $exif;
	}

	private function pathToUrl($path)
	{
		$dirs = wp_upload_dir();
		return str_replace($dirs['basedir'], $dirs['baseurl'], $path);
	}

	public function adminListViewPages()
	{
		add_submenu_page('upload.php', 'Images for import', 'For import', 'upload_files', 'importphotos', [$this, 'adminListView']);
		add_submenu_page('upload.php', 'Flickr images', 'Flickr images', 'upload_files', 'flickrphotos', [$this, 'flickrListView']);
		add_submenu_page('upload.php', 'Flickr sets', 'Flickr sets', 'upload_files', 'flickrsets', [$this, 'flickrSetView']);
	}

	public function adminListView()
	{
		$files = $this->getFiles();

		if (empty($files)) {
			echo '<p>No files</p>';
		} else {
			$file_html = [];
			foreach ($files as $file) {
				$file_path = $this->sanitizeFileName($file);
				$file_url = $this->pathToUrl($file_path);
				$exif = $this->buildEXIFArray($file_path, false);

				$keywords = '<p>No keywords</p>';
				if (!empty($exif['iptc']['keywords'])) {
					foreach ($exif['iptc']['keywords'] as &$keyword) {
						if (mb_detect_encoding($keyword) === 'ASCII') {
							$keyword = iconv('ASCII', 'UTF-8//IGNORE', $keyword);
						}
					}
					$keywords = '<p><em>Keywords</em>: ' . implode(', ', array_values($exif['iptc']['keywords'])).'</p>';
				}
				$calculated_decimal = '<p>No location data</p>';
				if (!empty($exif['GPSCalculatedDecimal'])) {
					$calculated_decimal = '<p><em>GPSCalculatedDecimal</em>: ' . $exif['GPSCalculatedDecimal'].'</p>';
				}

				$make_model = implode(' ', [$exif['Make'] ?? '', $exif['Model'] ?? '']);
				if (empty($make_model)) {
					$make_model = 'Unknown';
				}
				$make_model = '<p><em>Camera</em>: ' . $make_model.'</p>';

				$file_name = '<p>' . $file->getFileName().'</p>';

				$file_html[] = '<tr id="post-IMAGEID" class="post-IMAGEID"><th scope="row" class="check-column"><input type="checkbox" name="image[]" value="IMAGEID"></th><td><img style="max-width: 300px" src="' .$file_url. '"></td><td><p><strong>' .(!empty($exif['iptc']['graphic_name']) ? $exif['iptc']['graphic_name'] : 'No image title').'</strong></p>'.$keywords.$calculated_decimal.$make_model.$file_name. '</td><!--<td><pre>' .print_r($exif, 1). '</pre></td>--></tr>';
			}
			printf(
				'<div class="wrap">
					<h1>%1$s</h1>
					<table class="wp-list-table widefat fixed striped">
						<thead><tr>
							<td id="cb" class="manage-column column-cb check-column"><label class="screen-reader-text" for="cb-select-all-1">Select All</label><input id="cb-select-all-1" type="checkbox"></td>
							<th scope="col" id="image" class="manage-column"><span>Preview</span></th>
							<th scope="col" id="title" class="manage-column column-title column-primary"><span>Title</span></th>
							<!--<th scope="col" id="exif" class="manage-column"><span>EXIF data</span></th>-->
						</tr></thead>
						<tbody id="the-list" class="ui-sortable">
							%2$s
						</tbody>
					</table>
				</div>',
				get_admin_page_title(),
				implode(chr(10), $file_html)
			);
		}
	}

	public function postLink($photo)
	{
		$existing = new WP_Query([
			'post_type' => 'photo',
			'meta_query' => [
				[
					'key' => 'video_ref',
					'value' => $this->flickrEmbedUrl($photo),
					'compare' => '=',
				]
			]
		]);

		if (!empty($existing->posts)) {
			return get_permalink($existing->posts[0]);
		} else {
			if (is_array($photo['extradata']['tags'])) {
				$photo['extradata']['tags'][] = 'Imported from Flickr';
			} else {
				$photo['extradata']['tags'] = ['Imported from Flickr'];
			}

			$post_id = wp_insert_post([
				'post_title' => empty($photo['title'])? 'Untitled' : $photo['title'],
				'post_content' => isset($photo['description']) && isset($photo['description']['_content']) ? $photo['description']['_content'] : '',
				'post_status' => 'publish',
				'post_type' => 'photo',
				'post_author' => get_current_user_id(),
				'post_date' => date('Y-m-d H:i:s', $photo['dateupload']),
				'post_name' => $photo['id'],
				'tax_input' => [
					'collection' => (array)$photo['extradata']['tags'],
				],
				'meta_input' => [
					'video_ref' => $this->flickrEmbedUrl($photo)
				]
			]);

			if (is_array($photo['extradata']['location']) && isset($photo['extradata']['location']['latitude']) && isset($photo['extradata']['location']['longitude'])) {
				update_post_meta($post_id, 'location', [
					'address' => $photo['extradata']['location']['latitude'].','.$photo['extradata']['location']['longitude'],
					'lat' => $photo['extradata']['location']['latitude'],
					'lng' => $photo['extradata']['location']['longitude'],
				]);
			}

			return get_permalink($post_id);
		}
	}

	public function flickrListView()
	{
		$this->flickr_config = [
			'flickr_key' => esc_attr(get_option('flickr_key')),
			'flickr_secret' => esc_attr(get_option('flickr_secret')),
			'flickr_userid' => esc_attr(get_option('flickr_userid'))
		];

		if (!empty($this->flickr_config['flickr_key']) && !empty($this->flickr_config['flickr_secret']) && !empty($this->flickr_config['flickr_userid'])) {
			//$upload_dir = wp_upload_dir();
			$per_page = 100;

			if (isset($_GET['pagenumber'])) {
				$pagenumber = (int)$_GET['pagenumber'];
			} else {
				$pagenumber = 0;
			}

			$FlickrRequestString='https://api.flickr.com/services/rest/?method=flickr.photos.search&format=json&nojsoncallback=1&api_key='.$this->flickr_config['flickr_key'].'&secret='.$this->flickr_config['flickr_secret'].'&user_id='.$this->flickr_config['flickr_userid'].'&extras=description,license,date_upload,date_taken,owner_name,icon_server,original_format,last_update,geo,tags,machine_tags,o_dims,views,media,path_alias,url_sq,url_t,url_s,url_q,url_m,url_n,url_z,url_c,url_l,url_o&page=' .$pagenumber. '&per_page='.$per_page;

			$out=[];

			if (($image_data=$this->getRemoteFileContents($FlickrRequestString))) {
				$data = json_decode($image_data, true);

				$page_list = '<ul class="inline">';
				for ($page=0; $page <= $data['photos']['pages']; $page++) {
					if ($page === $data['photos']['page']) {
						$page_list .='<li><strong>'.$page.'</strong></li>';
					} else {
						$page_list .='<li><a href="/wp-admin/upload.php?page=flickrphotos&pagenumber=' .$page. '">'.$page.'</a></li>';
					}
				}
				$page_list .= '</ul>';

				echo $page_list;

				if ($pagenumber>0) {
					if ($data['stat']=='ok') {
						foreach ($data['photos']['photo'] as $photo) {
							$photo['extradata'] = $this->getFlickrExtraData($photo['id']);

							// if ((int)$photo['datetakenunknown'] || !$photo['datetaken']) {
							// 	$date = date('Y-m-d H:i:s', $photo['dateupload']);
							// } else {
							// 	$date = date('Y-m-d H:i:s', strtotime($photo['datetaken']));
							// }

							$post_link = $this->postLink($photo);

							$out[] = '<tr id="post-' .$photo['id']. '" class="post-' .$photo['id']. '">
								<th scope="row" class="check-column">
									<input type="checkbox" name="image[]" value="' .$photo['id']. '">
								</th>
								<td>
									<img src="' .$photo['url_s']. '">
								</td>
								<td>
									<p><strong>' .$photo['title']. '</strong></p>
									<p>Tags: '.implode(', ', $photo['extradata']['tags']).'</p>
									<p>Location: '.implode(', ', $photo['extradata']['location']).'</p>
									<p>oEmbed URL: '.$this->flickrEmbedUrl($photo).'</p>
									<p>Post URL: <a href="' .$post_link. '">' .$post_link. '</a></p>
								</td>
								<td>
									<!--<pre>' .print_r($photo, 1). '</pre>-->
								</td>
							</tr>
							';
						}

						printf(
							'<div class="wrap">
							<h1>%1$s</h1>
							<script>
							var posts_for_import = [];
							</script>
							<button class="button button-primary" data-import-from-flickr>Import</button>
							<table class="wp-list-table widefat fixed striped">
								<thead><tr>
									<td id="cb" class="manage-column column-cb check-column"><label class="screen-reader-text" for="cb-select-all-1">Select All</label><input id="cb-select-all-1" type="checkbox"></td>
									<th scope="col" id="image" class="manage-column"><span>Preview</span></th>
									<th scope="col" id="title" class="manage-column column-title column-primary"><span>Title</span></th>
									<th scope="col" id="exif" class="manage-column"><span>All data</span></th>
								</tr></thead>
								<tbody id="the-list" class="ui-sortable">
									%2$s
								</tbody>
							</table>
						</div>',
							get_admin_page_title(),
							implode(chr(10), $out)
						);
					}
				}
			}
		}
	}

	public function flickrSetView()
	{
		$this->flickr_config = [
			'flickr_key' => esc_attr(get_option('flickr_key')),
			'flickr_secret' => esc_attr(get_option('flickr_secret')),
			//'flickr_userid' => esc_attr(get_option('flickr_userid'))
			'flickr_userid' => '87637435@N00'
		];

		if (!empty($this->flickr_config['flickr_key']) && !empty($this->flickr_config['flickr_secret']) && !empty($this->flickr_config['flickr_userid'])) {
			$per_page = 500;

			$FlickrRequestString='https://api.flickr.com/services/rest/?method=flickr.photosets.getList&format=json&nojsoncallback=1&api_key='.$this->flickr_config['flickr_key'].'&secret='.$this->flickr_config['flickr_secret'].'&user_id='.$this->flickr_config['flickr_userid'].'&primary_photo_extras=license,date_upload,date_taken,owner_name,icon_server,original_format,last_update,geo,tags,machine_tags,o_dims,views,media,path_alias,url_sq,url_t,url_s,url_m,url_o&page=1&per_page='.$per_page;

			if (($sets_data=$this->getRemoteFileContents($FlickrRequestString))) {
				$data = json_decode($sets_data, true);
				if ($data['stat']=='ok') {
					$out = [];
					foreach ($data['photosets']['photoset'] as $set) {
						$out[] = '<tr id="set-' .$set['id']. '" class="set-' .$set['id']. '"' .($set['photos'] > 500 ? ' style="background-color:#fcc"' : ''). '>
								<th scope="row" class="check-column">
									<input type="checkbox" name="image[]" value="' .$set['id']. '">
								</th>
								<td><strong>' .$set['title']['_content']. '</strong></td>
								<td>' .wpautop($set['description']['_content']).'</td>
								<td>' .$set['photos']. '</td>
							</tr>
							';
					}
					printf(
						'<div class="wrap">
							<h1>%1$s</h1>
							<script>
							var posts_for_import = [];
							</script>
							<table class="wp-list-table widefat fixed striped">
								<thead><tr>
									<td id="cb" class="manage-column column-cb check-column"><label class="screen-reader-text" for="cb-select-all-1">Select All</label><input id="cb-select-all-1" type="checkbox"></td>
									<th scope="col" id="title" class="manage-column column-title"><span>Flickr set</span></th>
									<th scope="col" id="description" class="manage-column column-description"><span>Description</span></th>
									<th scope="col" id="photocount" class="manage-column column-photocount"><span>Photo count</span></th>
								</tr></thead>
								<tbody id="the-list" class="ui-sortable">
									%2$s
								</tbody>
							</table>
						</div>',
						get_admin_page_title(),
						implode(chr(10), $out)
					);
				}
			}

			if (isset($_GET['createsets'])) {
				foreach ($data['photosets']['photoset'] as $set) {
					wp_insert_term(
						$set['title']['_content'],
						'album',
						[
							'description' => $set['description']['_content']
						]
					);
				}
			}

			if (isset($_GET['updatephotos'])) {
				foreach ($data['photosets']['photoset'] as $set) {
					$photos_data = $this->getRemoteFileContents('https://api.flickr.com/services/rest/?method=flickr.photosets.getPhotos&format=json&nojsoncallback=1&api_key='.$this->flickr_config['flickr_key'].'&secret='.$this->flickr_config['flickr_secret'].'&user_id='.$this->flickr_config['flickr_userid'].'&photoset_id='.$set['id'].'&per_page=500');
					$photos_data = json_decode($photos_data, true);
					foreach ($photos_data['photoset']['photo'] as $flickr_photo) {
						$photo_posts = get_posts([
							'post_type' => 'photo',
							'post_status' => 'publish',
							'meta_query' => [
								'relation' => 'AND',
								[
									'key' => 'video_ref',
									'compare' => 'EXISTS',
								],
								[
									'key' => 'video_ref',
									'compare' => '=',
									'value' => 'https://www.flickr.com/photos/87637435@N00/' .$flickr_photo['id']. '/'
								]
							]
						]);
						foreach ($photo_posts as $post) {
							wp_set_object_terms($post->ID, [$set['title']['_content']], 'album', true);
						}
					}
				}
			}
		}
	}

	private function getFlickrExtraData($photo_id)
	{
		$url = 'https://api.flickr.com/services/rest/?method=flickr.photos.getInfo&format=json&nojsoncallback=1&api_key='.$this->flickr_config['flickr_key'].'&secret='.$this->flickr_config['flickr_secret'].'&photo_id='.$photo_id;
		$data = $this->getRemoteFileContents($url);
		$data = json_decode($data, true);
		$out = [];
		$out['tags'] = [];
		if (isset($data['photo']) && isset($data['photo']['tags']) && is_array($data['photo']['tags']['tag']) && !empty($data['photo']['tags']['tag'])) {
			foreach ($data['photo']['tags']['tag'] as $tag) {
				if (isset($tag['raw'])) {
					$out['tags'][] = $tag['raw'];
				}
			}
		}
		$out['location'] = [];
		if (isset($data['photo']) && isset($data['photo']['location']) && is_array($data['photo']['location']) && isset($data['photo']['location']['latitude']) && isset($data['photo']['location']['longitude'])) {
			$out['location'] = [
				'latitude' => $data['photo']['location']['latitude'],
				'longitude' => $data['photo']['location']['longitude'],
			];
		}
		return $out;
	}

	private function getRemoteFileContents($url)
	{
		if (false === ( $contents = get_transient('flickr_'.md5($url)) )) {
			$curl_instance = curl_init();
			curl_setopt($curl_instance, CURLOPT_URL, $url);
			curl_setopt($curl_instance, CURLOPT_CONNECTTIMEOUT, 1);
			curl_setopt($curl_instance, CURLOPT_RETURNTRANSFER, 1);
			$contents = curl_exec($curl_instance);
			//$response = curl_getinfo($curl_instance);
			curl_close($curl_instance);
			set_transient('flickr_'.md5($url), $contents, HOUR_IN_SECONDS);
		}
		return $contents;
	}

	private function flickrEmbedUrl($data)
	{
		return sprintf(
			'https://www.flickr.com/photos/%1$s/%2$s/',
			$data['owner'],
			$data['id']
		);
	}

	public function flickrScripts($hook)
	{
		if ($hook !== 'media_page_flickrphotos') {
			return;
		}
		wp_enqueue_style('media_page_flickrphotos', plugins_url('assets/media_page_flickrphotos.css', __FILE__));
	}

	public function addMetaFields()
	{
		register_rest_field('post', 'video_ref', [
			'get_callback' => function ($args) {
				return get_post_meta($args['id'], 'video_ref', true);
			},
			'update_callback' => function ($value, $post) {
				if (get_post_meta($post->ID, 'video_ref', true) === $value) {
					return true;
				}
				if (!update_post_meta($post->ID, 'video_ref', $value)) {
					return new \WP_Error(
						'rest_comment_video_ref_failed',
						__('Post ' .$post->ID. ' was not updated video_ref with value “' .$value. '”.'),
						['status' => 500]
					);
				}
				return true;
			},
			'schema' => [
				'description' => __('Video reference'),
				'type'        => 'string'
			],
		]);

		register_rest_field('post', 'location', [
			'get_callback' => function ($args) {
				return get_post_meta($args['id'], 'video_ref', true);
			},
			'update_callback' => function ($value, $post) {
				if (get_post_meta($post->ID, 'location', true) === $value) {
					return true;
				}
				if (!update_post_meta($post->ID, 'location', $value)) {
					return new \WP_Error(
						'rest_comment_location_failed',
						__('Post ' .$post->ID. ' was not updated location with value “' .$value. '”.'),
						['status' => 500]
					);
				}
				return true;
			},
			'schema' => [
				'description' => __('Geographic location'),
				'type'        => 'string'
			],
		]);
	}

	public function maybeAddTerms($post, $request, $update = true)
	{
		$params = $request->get_params();
		if (isset($params['meta']) && isset($params['meta']['tags']) && ! empty($params['meta']['tags'])) {
			wp_set_object_terms($post->ID, $params['meta']['tags'], 'post_tag', $update);
		}
		if (isset($params['meta']) && isset($params['meta']['categories']) && ! empty($params['meta']['categories'])) {
			wp_set_object_terms($post->ID, $params['meta']['categories'], 'category', $update);
		}
	}
}

new Plugin();
