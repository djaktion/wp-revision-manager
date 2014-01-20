<?php
/*
Plugin Name: CF Revision Manager
Plugin URI: http://crowdfavorite.com
Description: Revision management functionality so that plugins can add metadata to revisions as well as restore that metadata from revisions
Version: 1.0.3-dev
Author: Crowd Favorite
Author URI: http://crowdfavorite.com 
*/
if (!class_exists('cf_revisions')) {
	
	define('CF_REVISIONS_DEBUG', false);
	
	function cfr_register_metadata($postmeta_key, $display_func = '') {
		static $cfr;
		if (empty($cfr)) {
			$cfr = cf_revisions::get_instance();
		} 
		return $cfr->register($postmeta_key, $display_func);
	}

	class cf_revisions {
		private static $_instance;
		protected $postmeta_keys = array();
	
		public function __construct() {
			# save & restore
			add_action('save_post', array($this, 'save_post_revision'), 10, 2);
			add_action('wp_restore_post_revision', array($this, 'restore_post_revision'), 10, 2);

			if (is_admin()) {		
				# revision display
				global $pagenow;
				if ($pagenow == 'revision.php') {
					add_filter('_wp_post_revision_fields', array($this, 'post_revision_fields'), 10, 1);
					add_filter('_wp_post_revision_field_postmeta', array($this, 'post_revision_field'), 1, 2);
				}
			}
		}
	
		public function register($postmeta_key, $display_func = '') {
			if (!in_array($postmeta_key, $this->postmeta_keys, true)) {
				$this->postmeta_keys[] = compact('postmeta_key', 'display_func');
			}
			return true;
		}
	
		/**
		 * This is a paranoid check. There will be no object to register the 
		 * actions and filters if nobody adds any postmeta to be handled
		 *
		 * @return bool
		 */
		public function have_keys() {
			return (bool) count($this->postmeta_keys);
		}
	

		/**
		* The opposite of WordPress stripslashes_deep, since wp_slash
		* only works on arrays of strings.
		**/
		public function slash_deep($value) {
			if (is_array($value)) {
				$value = array_map(array($this, 'slash_deep'), $value);
			}
			else if (is_object($value)) {
				$vars = get_object_vars($value);
				foreach ($vars as $key => $data) {
					$value->{$key} = $this->slash_deep($data);
				}
			} else if (is_string($value)) {
				$value = wp_slash($value);
			}

			return $value;
		}


		/**
		 * Save the revision data
		 *
		 * @param int $post_id 
		 * @param object $post 
		 * @return void
		 */
		public function save_post_revision($post_id, $post) {
			if ($post->post_type != 'revision' || !$this->have_keys()) {
				return false;
			}
		
			foreach ($this->postmeta_keys as $postmeta_type) {
				$postmeta_key = $postmeta_type['postmeta_key'];
			
				if ($postmeta_values = get_post_meta($post->post_parent, $postmeta_key)) {
					foreach ($postmeta_values as $postmeta_value) {
						add_metadata('post', $post_id, $this->slash_deep($postmeta_key), $this->slash_deep($postmeta_value));
					}
					$this->log('Added postmeta for: '.$postmeta_key.' to revision: '.$post_id.' from post: '.$post->post_parent);
				}
			}
		}
	
		/**
		 * Revert the revision data
		 *
		 * @param int $post_id 
		 * @param int $revision_id 
		 * @return void
		 */
		public function restore_post_revision($post_id, $revision_id) {
			if (!$this->have_keys()) {
				return false;
			}
		
			foreach ($this->postmeta_keys as $postmeta_type) {
				$postmeta_key = $postmeta_type['postmeta_key'];
				delete_metadata('post', $post_id, $this->slash_deep($postmeta_key));
				// get_metadata does not unslash
				if ($postmeta_values = get_metadata('post', $revision_id, $postmeta_key)) {
					foreach ($postmeta_values as $postmeta_value) {
						$this->log('Setting postmeta: '.$postmeta_key.' for post: '.$post_id);
						add_metadata('post', $post_id, $this->slash_deep($postmeta_key), $this->slash_deep($postmeta_value), true);
					}
					$this->log('Restored post_id: '.$post_id.' metadata from: '.$postmeta_key);
				}
			}
		}
	
		public function post_revision_fields($fields) {
			$fields['postmeta'] = __('Post Meta');
			return $fields;
		}
	
		public function post_revision_field($field_id, $field) {
			if ($field != 'postmeta' || !$this->have_keys()) {
				return;
			}
		
			remove_filter('_wp_post_revision_field_postmeta', 'htmlspecialchars', 10, 2);
				
			$html = '<ul style="white-space: normal; margin-left: 1.5em; list-style: disc outside;">';
			foreach ($this->postmeta_keys as $postmeta_type) {
				$postmeta_html = '';
				$postmeta_key = $postmeta_type['postmeta_key'];
				$postmeta_values = get_metadata('post', intval($_GET['revision']), $postmeta_key);
				if (is_array($postmeta_values)) {
					foreach ($postmeta_values as $postmeta_value) {
						$postmeta_html .= '<div>';
						$postmeta_value = maybe_unserialize($postmeta_value);
						if (!empty($postmeta_value)) {
							if (!empty($postmeta_type['display_func']) && function_exists($postmeta_type['display_func'])) {
								$postmeta_html .= $postmeta_type['display_func']($postmeta_value);
							}
							else {
								$postmeta_rendered = (is_array($postmeta_value) || is_object($postmeta_value) ? print_r($postmeta_value, true) : $postmeta_value);
								$postmeta_html .= apply_filters('_wp_post_revision_field_postmeta_display', htmlspecialchars($postmeta_rendered), $postmeta_key, $postmeta_value);
							}
						}
						$postmeta_html .= '</div>';
					}
				}
				else {
					$postmeta_html .= '*empty postmeta value*';
				}
			
				$html .= '
					<li>
						<h3><a href="#postmeta-'.$postmeta_key.'" onclick="jQuery(\'#postmeta-'.$postmeta_key.'\').slideToggle(); return false;">'.$postmeta_key.'</a></h3>
						<div id="postmeta-'.$postmeta_key.'" style="display: none;">'.$postmeta_html.'</div>
					</li>
					';
			}
			$html .= '</ul>';
		
			return $html;
		}
	
		/**
		 * Singleton
		 *
		 * @return object
		 */
		public function get_instance() {
			if (!(self::$_instance instanceof cf_revisions)) {
				self::$_instance = new cf_revisions;
			}
			return self::$_instance;
		}
	
		protected function log($message) {
			if (CF_REVISIONS_DEBUG) {
				error_log($message);
			}
		}
	}

	if (defined('CF_REVISIONS_DEBUG') && CF_REVISIONS_DEBUG) {
		include('tests.php');
	}
}
