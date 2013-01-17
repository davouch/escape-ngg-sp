<?php
/**
 * Plugin Name: Escape NextGen Singlepic
 * Plugin Description: Converts NextGen Singlepics to native WordPress embedded IMGs. Read code for instructions.
 * Author: David Hlouch > inspired by origingal code by Konstantin Kovshenin
 * License: GPLv3
 * Version: 0.5rc2
 *
 * This plugin will scan through all your posts and pages for the [singlepic] shortcode. 
 * It will loop through all images associated with a post/page and recreate them as native 
 * WordPress attachments instead. Finally it will replace the [singlepic] shortcode with 
 * the embedded <a..><img></a> code native to WordPress.
 *
 * Instructions: This is WIP fork - Backup! Activate the plugin and browse to 
 * yourdomain.com/wp-admin/?escape_ngg_sp_please=1
 * When you're done you can delete the gallery dir, and the wp_ngg_* tables in your database. Keep the backups though.
 *
 * Limitations: 
 * - does not recognize nor solve:
 *   * ngg-based featured image
 *   * ngg-based embedded html image link (standard wp)
 *
 * - doesn't work with shortcodes other than [singlepic]
 * - doesn't work when more than one [singlepic] on page (?)
 *
 * @uses media_sideload_image to recreate your attachment posts
 */

 /* TO DO LIST
   - revise ngg-singlepic-parametres: some pics are using more than basic four (id, w, h, fl)
   - parametres are not resolved yet
     - tbn-medium-large size > check wp-media-setting before start
     - float
   - does not recognize nor solve
     - ngg-featured (i.e. ?p=497)
     - ngg-based embeds (i.e. ?p=18)
 */
 
add_action( 'admin_init', function() {
	global $post, $wpdb;

	if ( ! isset( $_GET['escape_ngg_sp_please'] ) || ! current_user_can( 'install_plugins' ) )
		return;

	error_reporting( E_ALL );
	ini_set( 'display_errors', 1 );
	set_time_limit( 600 );
  
	$uploads = wp_upload_dir();
	$baseurl = $uploads['baseurl'];
	$count = array(
		'posts' => 0,
		'images' => 0,
    'matches' => 0,
	);

	$query = array(
		's' => '[singlepic', //changed from [nggallery
		'post_type' => array( 'post', 'page' ),
		'post_status' => 'any',
		'posts_per_page' => 50,
		'offset' => 0,
	);

	while ( $posts = get_posts( $query ) ) {
		foreach ( $posts as $post ) {
			$query['offset']++;
			$matches = null;

      // debug: show current post id and URL
      printf( '<a href="%s">Post %d</a><br />', get_permalink( $post->ID ) , $post->ID );
      
      // in curr post look up all appearances of [singlepic.. and store attributes in <..>'s
      preg_match_all('#singlepic id(\s)*="?(\s)*(?P<id>\d+)"?(\s)*w(\s)*="?(\s)*(?P<width>\d+)"?(\s)*h(\s)*="?(\s)*(?P<height>\d+)"?(\s)*float(\s)*="?(\s)*(?P<float>\w+)"?]#i', $post->post_content, $matches );
      
      // curiously eough, if something goes wrong and no [singlepic in post available
			if ( ! isset( $matches['id'] ) ) {
				printf( "Could not any matches of [singlepic..] shortcode in Post ID: %d<br />", $post->ID );
				continue;
			}
      else {
        $matchcount = count($matches['id']);
        printf( "Matches: <b>%d</b><br />", $matchcount );

        // now cycle through all found shortcodes
        for ($i = 0; $i < $matchcount; ++$i) {
          
          // debug: print matched values
          printf("id: %d, w: %d, h: %d, f: %s <br />", $matches['id'][$i], $matches['width'][$i], $matches['height'][$i], $matches['float'][$i]);
        
          // check ngg repository for presence of current image
          $picture_id = $matches['id'][$i];
          $ngg_image = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ngg_pictures WHERE pid = ". intval( $picture_id ) . " ORDER BY sortorder, pid ASC" );
          if (!$ngg_image) {
            printf( "Could not find image in ngg repository for pid %d<br />", $picutre_id );
            continue;            
          }
          else {
          
            // foreach just for conversion of fetched row ngg_image.. (probably lame :) )
            foreach ($ngg_image as $image) {
            
              // get path & URL for the sideload transfer
              $path = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}ngg_gallery WHERE gid = ". intval( $image->galleryid ), ARRAY_A  );
              $healed_filename = preg_replace( '/ /', '%20', $image->filename );
              $url = home_url( trailingslashit( $path['path'] ) . $healed_filename );
              printf ("The ngg file URL: %s<br />",$url);
              
              // check if the image is already in wp repo
              $wp_image = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}posts WHERE guid RLIKE '". ( $healed_filename ) ."$' ORDER BY ID ASC" );
                foreach ($wp_image as $result) {
                  $wp_img_id = $result -> ID;
                }
              if (!$wp_image) {
                printf( "The picture filed as '%s' does not exist in wp repo yet. Let's get it there, shall we?<br />", $healed_filename );
                
                // Let's use a hash trick here to find our attachment post after it's been sideloaded.
                $hash = md5( 'attachment-hash' . $url . $image->description . time() . rand( 1, 999 ) );
                
                $sideloaded_img = media_sideload_image( $url, $post->ID, $hash );
                $attachments = get_posts( array(
                  'post_parent' => $post->ID,
                  's' => $hash,
                  'post_type' => 'attachment',
                  'posts_per_page' => -1,
                ) );

                if ( ! $attachments || ! is_array( $attachments ) || count( $attachments ) != 1 ) {
                  printf( "Could not insert attachment for %d<br />", $post->ID );
                  continue;
                }

                // Titles should fallback to the filename.
                if ( ! trim( $image->alttext ) ) {
                  $image->alttext = $healed_filename;
                }

                $attachment = $attachments[0];
                $attachment->post_title = $image->alttext;
                $attachment->post_content = $image->description;
                $attachment->menu_order = $image->sortorder;

                update_post_meta( $attachment->ID, '_wp_attachment_image_alt', $image->alttext );

                wp_update_post( $attachment );
                
                $count['images']++;
                printf( "Added attachment for %d<br />", $post->ID );
                printf( "%s<br />", $sideloaded_img);

                // Lastly store a link to the new attachement
                $att_link = sprintf(wp_get_attachment_link( $attachment->ID, 'thumbnail', true ));
                
                continue;            
              }// end-if: image !exists in wp repo
              else {
                  printf( "Image '%s' already exists in wp repo and sideload has been skipped therefore.<br />", $healed_filename );
                  // Now it would be nice to get the whole new WP URL of the new Attachement
                  $att_link = sprintf( wp_get_attachment_link( $wp_img_id, 'thumbnail', true ) );
                  echo "<hr /><hr />";
                  printf('%s', $att_link);
                  echo "<hr /><hr />";
              }
            }//end-foreach: just for conversion of fetch (lame but working :) )
          }//end-else: existence of file in ngg repo confirmed

          printf("<br />");
          $count['matches'] += count($matches['id']);
          
          
          // Construct the [gallery] shortcode
          // Construct the EMBEDDED html
          /*$attr = array();*/
          /*if ( $existing_attachments_ids )
            $attr['exclude'] = implode( ',', $existing_attachments_ids );*/
          /*$gallery = '[gallery';
          $
          foreach ( $attr as $key => $value )
            $gallery .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
          $gallery .= ']';*/
          
          /*$embedded = '<a href="http://david.hlouch.cz/wp-content/uploads/2013/01/bbu-pf2013.jpg"><img title="bbu pf2013" alt="" src="http://david.hlouch.cz/wp-content/uploads/2013/01/bbu-pf2013-720x720.jpg" width="450" height="450" /></a>*/
          

          // Booyaga!
          //$replacements = array();
          //$patterns = array();
          $post->post_content = preg_replace( '#\[singlepic[^\]]*\]#i', $att_link, $post->post_content, 1 );
          $update_result = wp_update_post( $post );
          $query['offset']--; // Since this post will no longer contain the [nggallery] it won't count against our offset
          $count['posts']++;
          if ( $update_result != 0 ) printf( "Updated post %d<br />", $post->ID );
          else echo 'Oh boy, something went wrong.';
          echo '<hr style="border: 1px solid #f00;" />';
        }//end-for: cycling through all [singlepic...] shortcodes

      }//end-else: 'double check' image not found in post
      $count['posts']++;
    } //end-foreach: posts as post
  } //end-while: posts
  
	//printf( "Updated %d posts with %d images.", $count['posts'], $count['images'] );
  printf("<hr>Posts: %d<br>",$count['posts']);
  printf("<hr>Matches: %d<br>",$count['matches']);
	die();
});
