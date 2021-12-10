<?php
defined( 'ABSPATH' ) or exit;

/**
 * Plugin Name: Inventory Presser - Photo Arranger
 * Plugin URI: https://github.com/fridaysystems/invp-photo-arranger
 * Description: Allows users to drag and drop rearrange photos using a Gallery block in the post content of a vehicle.
 * Version: 0.1.0
 * Author: Corey Salzano
 * Author URI: https://inventorypresser.com
 * Text Domain: invp-photo-arranger
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

class Inventory_Presser_Photo_Arranger
{
	const CSS_CLASS = 'invp-rearrange';

	public function add_hooks()
	{
		add_action( 'invp_loaded', function() {

			if( self::is_enabled() )
			{
				//Yet to tested:
				//Add photos via REST API with parent, those photos get put in the gallery

				//Make sure all attachment IDs are stored in the Gallery block when photos are attached, detached, or deleted
				add_action( 'add_attachment',  array( $this, 'add_attachment_to_gallery' ), 11, 1 );
				add_action( 'delete_attachment', array( $this, 'delete_attachment_handler' ), 10, 2 );
				add_action( 'wp_media_attach_action', array( $this, 'maintain_gallery_during_attach_and_detach' ), 10, 3 );

				//When a vehicle is saved, the gallery should be examined and...
				// - change their post_parent values to the vehicle post ID
				// - make sure they have sequence numbers, VINs, and hashes
				// - update all the sequence numbers to match the gallery order
				add_action( 'edit_post', array( $this, 'change_parents_and_sequence' ), 10, 2 );
				
				/**
				 * When the vehicle is opened in the block editor, make sure the
				 * gallery block is there waiting for the user.
				 */
				add_action( 'the_post', array( $this, 'create_gallery' ), 10, 1 );
			}

			//Add a switch to turn this feature on and off in Vehicles > Options
			add_action( 'admin_init', array( $this, 'add_settings_switch' ), 11 );
			add_filter( 'invp_options_page_sanitized_values', array( $this, 'add_settings_value_before_saving' ), 10, 2 );
		});
	}

	public function add_attachment_to_gallery( $post_id, $parent_id = null )
	{
		$attachment = get_post( $post_id );

		//Is this attachment an image?
		if( ! wp_attachment_is_image( $attachment ) )
		{
			//No
			return;
		}

		//Is this new attachment even attached to a post?
		$parent;
		if( ! empty( $attachment->post_parent ) )
		{
			$parent = get_post( $attachment->post_parent );
		}
		else if( ! empty( $parent_id ) )
		{
			$parent = get_post( $parent_id );
		}
		else
		{
			return;
		}

		//Is the new attachment attached to a vehicle?		
		if( empty( $parent->post_type ) || INVP::POST_TYPE != $parent->post_type )
		{
			//Parent post isn't a vehicle
			return;
		}

		//Update the photo to have a post_parent
		$attachment->post_parent = $parent->ID;
		$this->safe_update_post( $attachment );

		$this->maybe_add_gallery( $parent );

		//Loop over all the post's blocks in search of our Gallery
		$blocks = parse_blocks( $parent->post_content );
		foreach( $blocks as $index => $block )
		{
			//Is this a core gallery block? With a specific CSS class?
			if( ! $this->is_gallery_block_with_specific_css_class( $block ) )
			{
				continue;
			}

			//Does the block already have this attachment ID?
			if( empty( $block['attrs']['ids'] ) || ! in_array( $post_id, $block['attrs']['ids'] ) )
			{
				//Add the uploaded attachment ID to this gallery
				$block['attrs']['ids'][] = $post_id;
			}

			//Change a CSS class to reflect the number of photos in the Gallery
			//Replace all 'columns-#' 
			$block['innerContent'][0] = preg_replace(
				'/ columns-[0-9]+/',
				' columns-' . sizeof( $block['attrs']['ids'] ) ?? 1,
				$block['innerContent'][0]
			);
			//Do it again to replace the first with a max of 'columns-3'
			$block['innerContent'][0] = preg_replace(
				'/ columns-[0-9]+/',
				' columns-' . min( 3, sizeof( $block['attrs']['ids'] ) ?? 4 ),
				$block['innerContent'][0],
				1
			);
			if( false === strpos( $block['attrs']['className'], 'columns-' ) )
			{
				$block['attrs']['className'] = sprintf(
					'columns-%s %s',
					sizeof( $block['attrs']['ids'] ) ?? 1,
					$block['attrs']['className']
				);
			}
			else
			{
				$block['attrs']['className'] = preg_replace(
					'/columns-[0-9]+/',
					'columns-' . sizeof( $block['attrs']['ids'] ) ?? 1,
					$block['attrs']['className'],
					1
				);
			}

			//Add HTML that renders the image in the gallery
			//Is this image already in the Gallery HTML though?
			if( false === mb_strpos( $block['innerContent'][0], "class=\"wp-image-$post_id\"" ) )
			{
				//No
				$position_list_end = mb_strpos( $block['innerContent'][0], '</ul></figure>' );
				$new_html = sprintf( 
					'<li class="blocks-gallery-item"><figure><img src="%1$s" alt="" data-id="%2$s" data-link="%3$s" class="wp-image-%2$s"/></figure></li>',
					$attachment->guid,
					$post_id,
					get_permalink( $attachment )
				);
				$block['innerContent'][0] = 
					substr( $block['innerContent'][0], 0, $position_list_end ) 
					. $new_html
					. substr( $block['innerContent'][0], ( $position_list_end ) );
			}
			
			//Update the block in the $blocks array
			$blocks[$index] = $block;

			//and then update the post
			$parent->post_content = serialize_blocks( $blocks );
			$this->safe_update_post( $parent );
			break;
		}
	}

	public function add_settings_value_before_saving( $sanitary_values, $input )
	{
		$sanitary_values['use_arranger_gallery'] = isset( $input['use_arranger_gallery'] );
		return $sanitary_values;
	}

	public function add_settings_switch()
	{
		add_settings_field(
			'use_arranger_gallery', // id
			__( 'Rearrange Photos Block', 'invp-photo-arranger' ), // title
			array( $this, 'callback_use_arranger_gallery' ), // callback
			INVP::option_page(), // page
			'dealership_options_setting_section' // section
		);
	}

	public function callback_use_arranger_gallery()
	{
		$setting_name = 'use_arranger_gallery';
		$checkbox_label = __( 'Add a Gallery Block to vehicle post content to allow the photo order to be changed', 'invp-photo-arranger' );
		$options = INVP::settings();
		printf(
			'<p><input type="checkbox" name="%s[%s]" id="%s" %s> <label for="%s">%s</label></p>',
			INVP::OPTION_NAME,
			$setting_name,
			$setting_name,
			isset( $options[$setting_name] ) ? checked( $options[$setting_name], true, false ) : '',
			$setting_name,
			$checkbox_label
		);
	}

	public function change_parents_and_sequence( $post_id, $post )
	{
		//Runs on edit_post hook. Changes the post_parent values on photos in 
		//our magic Gallery block.

		//Is this a vehicle?
		if( empty( $post->post_type ) || INVP::POST_TYPE != $post->post_type )
		{
			//No, it's not a vehicle
			return;
		}

		//Make sure the photos in the gallery block have the post_parent value set
		$blocks = parse_blocks( $post->post_content );
		foreach( $blocks as $index => $block )
		{
			//Is this a core gallery block? With a specific CSS class?
			if( ! $this->is_gallery_block_with_specific_css_class( $block ) )
			{
				continue;
			}

			//Set a post_parent value on every photo in the gallery block
			foreach( $block['attrs']['ids'] as $index => $id )
			{
				$attachment = get_post( $id );
				$attachment->post_parent = $post_id;

				$this->safe_update_post( $attachment );

				Inventory_Presser_Photo_Numberer::maybe_number_photo( $id );
				//Make sure this photo has the right sequence number
				Inventory_Presser_Photo_Numberer::save_meta_photo_number( $id, $post_id, $index + 1 );
			}
			
			/**
			 * Are there attachments to this post that are no longer in the 
			 * gallery? We should detach those from this vehicle.
			 */
			$attachment_ids = get_children( array(
				'fields'         => 'ids',
				'post_parent'    => $attachment->post_parent,
				'post_type'      => 'attachment',
				'posts_per_page' => 500,
			) );

			foreach( array_diff( $attachment_ids, $block['attrs']['ids'] ) as $attachment_id )
			{
				$post = get_post( $attachment_id );
				$post->post_parent = 0;
				$this->safe_update_post( $post );
			}
		}
	}

	/**
	 * create_gallery
	 * 
	 * Makes sure the Gallery Block is waiting for the user when they open a
	 * vehicle post in the block editor.
	 *
	 * @param  WP_Post $post
	 * @return void
	 */
	public function create_gallery( $post )
	{
		if( ! function_exists( 'get_current_screen' ) )
		{
			return;
		}

		$screen = get_current_screen();
		if( is_admin() && ! empty( $screen ) && $screen->is_block_editor
			&& get_post_type() == INVP::POST_TYPE )
		{
			$this->maybe_add_gallery( $post );

			//Add all this vehicle's photos
			$posts = get_children( array(
				'meta_key'    => apply_filters( 'invp_prefix_meta_key', 'photo_number' ),
				'order'       => 'ASC',
				'orderby'     => 'meta_value_num',
				'post_parent' => $post->ID,
				'post_type'   => 'attachment',
			) );
			foreach( $posts as $post )
			{
				$this->add_attachment_to_gallery( $post->ID, $post->post_parent );
			}
		}
	}

	public function delete_attachment_handler( $post_id, $post )
	{
		$attachment = get_post( $post_id );
		if( empty( $attachment->post_parent ) )
		{
			return;
		}
		$this->remove_attachment_from_gallery( $post_id, $attachment->post_parent );
	}

	/**
	 * is_enabled
	 * 
	 * Is the photo arranger via a Gallery Block feature enabled?
	 *
	 * @return bool
	 */
	public static function is_enabled()
	{
		return INVP::settings()['use_arranger_gallery'] ?? false;
	}

	protected function is_gallery_block_with_specific_css_class( $block )
	{
		return ! empty( $block['blockName'] )
			&& 'core/gallery' == $block['blockName']
			&& ! empty( $block['attrs']['className'] )
			&& false !== mb_strpos( $block['attrs']['className'], self::CSS_CLASS );
	}

	public function maintain_gallery_during_attach_and_detach( $action, $attachment_id, $parent_id )
	{
		$parent_id = intval( $parent_id );
		if( 'detach' == $action )
		{
			$this->remove_attachment_from_gallery( $attachment_id, $parent_id );
			$this->remove_photo_meta( $attachment_id );
			Inventory_Presser_Photo_Numberer::renumber_photos( $parent_id );
			return;
		}
		$this->add_attachment_to_gallery( $attachment_id, $parent_id );
	}

	public function maybe_add_gallery( $post )
	{
		//Does the post content of the vehicle have a Gallery with a specific CSS class?
		$blocks = parse_blocks( $post->post_content );
		$found_our_gallery = false;
		foreach( $blocks as $index => $block )
		{
			//Is this a core gallery block? With a specific CSS class?
			if( ! $this->is_gallery_block_with_specific_css_class( $block ) )
			{
				continue;
			}

			$found_our_gallery = true;
		}

		if( ! $found_our_gallery )
		{
			//No Gallery block with a specific CSS class found. Create it.
			$blocks[] = array(
				'blockName'    => 'core/gallery',
				'attrs'        => array(
					'ids'       => [],
					'linkTo'    => 'none',
					'className' => self::CSS_CLASS,
				),
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => array(
					0 => '<figure class="wp-block-gallery columns-0 is-cropped columns-0 invp-rearrange"><ul class="blocks-gallery-grid"></ul></figure>',
				),
			);

			//and then update the post
			$post->post_content = serialize_blocks( $blocks );
			$this->safe_update_post( $post );
		}
	}

	protected function remove_attachment_from_gallery( $post_id, $parent_id )
	{
		$attachment = get_post( $post_id );

		//Is this attachment an image?
		if( ! wp_attachment_is_image( $attachment ) )
		{
			//No
			return;
		}

		//Is this new attachment even attached to a post?
		$parent = get_post( $parent_id );

		//Is the new attachment attached to a vehicle?		
		if( empty( $parent->post_type ) || INVP::POST_TYPE != $parent->post_type )
		{
			//Parent post isn't a vehicle
			return;
		}

		//Does the post content of the vehicle have a Gallery with a specific CSS class?
		$blocks = parse_blocks( $parent->post_content );
		foreach( $blocks as $index => $block )
		{
			//Is this a core gallery block? With a specific CSS class?
			if( ! $this->is_gallery_block_with_specific_css_class( $block ) )
			{
				continue;
			}

			//Does the block already have this attachment ID? Remove it
			if( ! empty( $block['attrs']['ids'] ) && ( $key = array_search( $post_id, $block['attrs']['ids'] ) ) !== false )
			{
				unset( $block['attrs']['ids'][$key] );
			}

			//Change a CSS class to reflect the number of photos in the Gallery
			$block['innerContent'][0] = preg_replace(
				'/ columns-[0-9]+/',
				' columns-' . sizeof( $block['attrs']['ids'] ) ?? 0,
				$block['innerContent'][0],
				2
			);

			//Remove a list item HTML that renders the image in the gallery			
			$pattern = sprintf( 
				'/<li class="blocks-gallery-item"><figure><img src="[^"]+" alt="" data-id="%1$s" data-full-url="[^"]+" data-link="[^"]+" class="wp-image-%1$s"\/><\/figure><\/li>/',
				$post_id
			);
			$block['innerContent'][0] = preg_replace(
				$pattern,
				'',
				$block['innerContent'][0]
			);
			
			//Update the block in the $blocks array
			$blocks[$index] = $block;

			//and then update the post
			$parent->post_content = serialize_blocks( $blocks );
			$this->safe_update_post( $parent );	
			break;
		}
	}

	/**
	 * remove_photo_meta
	 * 
	 * Removes vehicle-specific meta values from a photo photo_number and vin.
	 *
	 * @param  int $post_id The post ID of the photo from which meta values will be removed.
	 * @return void
	 */
	protected function remove_photo_meta( $post_id )
	{
		delete_post_meta( $post_id, apply_filters( 'invp_prefix_meta_key', 'photo_number' ) );
		delete_post_meta( $post_id, apply_filters( 'invp_prefix_meta_key', 'vin' ) );
	}

	/**
	 * safe_update_post
	 * 
	 * Removes our `edit_post` hook, calls `wp_update_post()`, and re-adds the 
	 * `edit_post` hook.
	 *
	 * @param  WP_Post $post
	 * @return void
	 */
	protected function safe_update_post( $post )
	{
		//Don't cause this hook to fire again
		remove_action( 'edit_post', array( $this, 'change_parents_and_sequence' ), 10, 2 );

		wp_update_post( $post );
		
		//Re-add the hook now that we're done making changes
		add_action( 'edit_post', array( $this, 'change_parents_and_sequence' ), 10, 2 );
	} 	
}
$invp_rearranger_0234 = new Inventory_Presser_Photo_Arranger();
$invp_rearranger_0234->add_hooks();
