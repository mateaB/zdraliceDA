<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class TETK_Theme_Demo_Eggnews_Pro extends TETK_Theme_Demo {

	public static function import_files() {
		$template_directory = get_template_directory_uri() . '/demo-content/';

		$template_directory = 'https://demo.themeegg.com/themes/eggnews-pro/wp-content/themes/eggnews-pro/demo-content/';

		$demo_urls = array(
			array(
				'import_file_name'           => 'EggNews Pro',
				'import_file_url'            => $template_directory . 'content.xml',
				'import_widget_file_url'     => $template_directory . 'widgets.wie',
				'import_customizer_file_url' => $template_directory . 'customizer.dat',
				'import_preview_image_url'   => 'http://demo.themeegg.com/themes/eggnews-pro/wp-content/themes/eggnews-pro/screenshot.png',
				'demo_url'                   => 'http://demo.themeegg.com/themes/eggnews-pro/',
				//'import_notice'              => __( 'After you import this demo, you will have to setup the slider separately.', 'your-textdomain' ),
			)
		);

		return $demo_urls;
	}

	public static function after_import( $selected_import ) {

// Assign front page and posts page (blog page).


		$installed_demos  = get_option( 'themeegg_themes', array() );
		$import_file_name = isset( $selected_import['import_file_name'] ) ? $selected_import['import_file_name'] : '';
		if ( ! empty( $import_file_name ) ) {
			array_push( $installed_demos, $import_file_name );
		}

		$installed_demos = array_unique( $installed_demos );

		// SET Menus

		$new_theme_locations = get_registered_nav_menus();

		foreach ( $new_theme_locations as $location_key => $location ) {

			$menu = get_term_by( 'name', $location, 'nav_menu' );

			if ( isset( $menu->term_id ) ) {
				set_theme_mod( 'nav_menu_locations', array(
						'primary' => $menu->term_id,
					)
				);
			}
		}

		$front_page_id = get_page_by_title( 'Front Page' );
		$blog_page_id  = get_page_by_title( 'News' );

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_page_id->ID );
		update_option( 'page_for_posts', $blog_page_id->ID );

// Assign front page and posts page (blog page).
		update_option( 'themeegg_themes', $installed_demos );
	}
}

?>