<?php
/**
 * Plugin Name: NHG Speed Edit
 * Description: Rapidly edit products in the backend.
 * Version: 1
 * Author: Espen Espelund
 */

namespace NHG\SpeedEdit;

defined( '\ABSPATH' ) or die();

// Defines
define(__NAMESPACE__.'\HANDLE',     'nhg-speed-edit');
define(__NAMESPACE__.'\OBJECT',     'nhg_speed_edit');
define(__NAMESPACE__.'\PATH',       plugin_dir_path( __FILE__ ));
define(__NAMESPACE__.'\URL',        plugins_url('', __FILE__).'/' );
define(__NAMESPACE__.'\VERSION',    '1.0.5');

new SpeedEdit;

class SpeedEdit {

	public function __construct() {

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', [$this, 'register_menu_page'] );
		add_action( 'admin_footer', [$this, 'setup_algolia'] );
		add_action( 'admin_enqueue_scripts', [$this, 'enqueue_scripts'] );

		add_action( 'wp_ajax_' . OBJECT . '_load_product', [$this, 'product_ajax'] );
        add_action( 'wp_ajax_' . OBJECT . '_update_stock', [$this, 'stock_update_ajax'] );

        // Add meta box to product edit screen
        add_action( 'add_meta_boxes', [ $this,'speed_edit_meta_box' ] );

        // Update stock on save post hook
        add_action( 'save_post', [ $this,'update_stock_date' ], 10, 3 );

        // create database table  on activation
        register_activation_hook( __FILE__, [$this, 'create_db_table'] );

	}

    public function create_db_table(){

        global $wpdb;
        $table_name = $wpdb->prefix . 'nhg_speed_edit_log';

        if( $wpdb->get_var( "show tables like '{$table_name}'" ) != $table_name ) {
            $sql = "CREATE TABLE " . $table_name . " (
                id BIGINT(20) NOT NULL AUTO_INCREMENT,
                product_id BIGINT(20) NOT NULL,
                variation_id BIGINT(20) NOT NULL,
                action VARCHAR(100) NOT NULL,
                old_value VARCHAR(255) NOT NULL,
                new_value VARCHAR(255) NOT NULL,
                user_id BIGINT(20) NOT NULL,
                log_time DATETIME NOT NULL,
                PRIMARY KEY  (id)
            );";

            $wpdb->query( $sql );
        }
    }

	public function register_menu_page() {
		add_menu_page(
			'Speed Edit',
			'Speed Edit',
			'manage_woocommerce',
			HANDLE,
			[$this, 'display_admin_page'],
			'dashicons-smiley',
			5
		);
	}

	public function display_admin_page() {
		require( PATH . 'views/page.php' );
	}

	public function setup_algolia($hook) {

		if ( get_current_screen()->parent_base !== HANDLE ) {
			return;
		}

		include( PATH . 'views/algolia-setup.php' );
	}

	public function enqueue_scripts($hook) {

        $screen = get_current_screen();
        if ( $screen->post_type == 'product' && $_GET['action'] == 'edit'  ) {

            wp_enqueue_style( HANDLE, URL . 'css/metabox.css', false, VERSION );
            wp_enqueue_script( HANDLE, URL . 'js/admin.js', 'jquery', VERSION );
            wp_localize_script( HANDLE, OBJECT, [
                'nonce' => wp_create_nonce(HANDLE)
            ]);
        }
        elseif ( $hook !== 'toplevel_page_' . HANDLE ) {
			return;
		}

		wp_enqueue_style( HANDLE, URL . 'css/admin.css', false, VERSION );

		wp_enqueue_script( HANDLE, URL . 'js/admin.js', 'jquery', VERSION );
		wp_localize_script( HANDLE, OBJECT, [
			'nonce' => wp_create_nonce(HANDLE)
		]);

	}

	public function product_ajax() {

		check_ajax_referer(HANDLE);

		$product_id = intval( $_GET['product_id'] );
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		ob_start();
		require( PATH . 'views/product.php' );
		$html = ob_get_clean();

        ob_start();
        require( PATH . 'views/edit-log.php' );
        $html .= ob_get_clean();

		wp_send_json([
			'html' => $html
		]);
	}

    public function stock_update_ajax() {

        check_ajax_referer(HANDLE);

        $products = array();

        if( count($_POST['products']) > 0 ) {

            foreach( $_POST['products'] as $pro ) {

                $product_id = intval($pro['product_id']);
                $stock_action = $pro['stock_action'];
                $edit_value = $pro['edit_value'];
                $open_orders_stock = intval($pro['open_orders_stock']);
                $product = wc_get_product($product_id);


                if (!$product) {

                    continue;
                } else {

                    $old_quantity = $quantity = $product->get_stock_quantity();

                    if( $stock_action == 'location' ){
                        $old_location = get_post_meta( $product->get_id(), 'location', true);

                        update_post_meta( $product_id, 'location', $edit_value );
                        $this->create_log( $product, $stock_action, $old_location, $edit_value );
                        $products[] = array('product_id' => $product_id, 'quantity' => $quantity);
                    }
                    else {

                        if ($stock_action == 'add_stock') {
                            $quantity = $quantity + $edit_value;
                        } elseif ($stock_action == 'delete_stock') {
                            $quantity = $quantity - $edit_value;
                        } elseif ($stock_action == 'replace_stock') {
                            $quantity = $edit_value - $open_orders_stock;
                        }


                        $new_quantity = wc_update_product_stock($product, $quantity);
                        $this->create_log( $product, $stock_action, $old_quantity, $new_quantity );
                        $products[] = array('product_id' => $product_id, 'quantity' => $new_quantity );
                        /*if( $new_quantity <= 0 ){

                            $out_of_stock_staus = 'outofstock';
                            update_post_meta( $product_id, '_stock_status', wc_clean( $out_of_stock_staus ) );
                            wp_set_post_terms( $product_id, 'outofstock', 'product_visibility', true );
                            wc_delete_product_transients( $product_id ); // Clear/refresh the variation cache
                        }*/


                    }

                }
            }
        }

        ob_start();
        require( PATH . 'views/edit-log.php' );
        $log_html = ob_get_clean();

        wp_send_json([
            'products' => $products,
            'log_html' => $log_html
        ]);
    }

    public function create_log( $product, $action, $old_value, $new_value ) {

        global $wpdb;
        $table_name = $wpdb->prefix . 'nhg_speed_edit_log';

        if( $action ) {

            $parent_id = $product->get_parent_id();
            if( $parent_id ){
                $variation_id = $product->get_id();
                $product_id = $parent_id;
            }
            else{
                $variation_id = 0;
                $product_id = $product->get_id();
            }

            $wpdb->insert(
                $table_name,
                array(
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'action' => $action,
                    'old_value' => $old_value,
                    'new_value' => $new_value,
                    'user_id' => get_current_user_id(),
                    'log_time' => date('Y-m-d H:i:s')
                ),
                array(
                    '%d',
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%s'
                )
            );
        }
    }

    // add meta box in product edit page
    public function speed_edit_meta_box() {

        $screen = get_current_screen();
        if ( $screen->post_type == 'product' && $_GET['action'] == 'edit'  ) {
            add_meta_box(
                'nhg-speed-edit-metabox',
                __('Stock Edit', 'nhg'),
                array($this, 'nhg_speed_edit_metabox_callback'),
                'product'
            );
        }

    }

    public function nhg_speed_edit_metabox_callback( ) {

        global $post;

        $product_id = intval( $post->ID );
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return;
        }

        $product_edit_screen = 'product_edit_screen';
        echo '<div class="speed-edit-screen">';
        require( PATH . 'views/product-metabox.php' );
        require( PATH . 'views/edit-log.php' );
        echo '</div>';


    }

    public function update_stock_date( $post_id, $post, $update ) {

        $post_type = get_post_type( $post_id );



        // If this isn't a 'product' post or not in update mode, then return.
        if ( "product" != $post_type || !$update ) return;

        $product = wc_get_product( $post_id );

        if ( $product->product_type === 'variable' ) {

            //return if no variations
            if ( count( $variations = $product->get_children() ) <= 0 ) return;

            foreach ( $variations as $variation_id ) {

                $variation = new \WC_Product_Variation($variation_id);
                $old_quantity = $quantity = $variation->get_stock_quantity();

                $stock_action = '';
                if ( !empty( $_POST['add_stock_'.$variation_id] ) ) {
                    $quantity = $quantity + intval( $_POST['add_stock_'.$variation_id] );
                    $stock_action = 'add_stock';
                } elseif ( !empty( $_POST['delete_stock_'.$variation_id] ) ) {
                    $quantity = $quantity - intval( $_POST['delete_stock_'.$variation_id] );
                    $stock_action = 'delete_stock';
                } elseif ( !empty( $_POST['replace_stock_'.$variation_id] ) ) {
                    $quantity = intval( $_POST['replace_stock_'.$variation_id] ) - intval( $_POST['on_orders_'.$variation_id] );
                    $stock_action = 'replace_stock';
                }


                if( $stock_action ) {
                    $new_quantity = wc_update_product_stock($variation, $quantity);
                    $this->create_log($variation, $stock_action, $old_quantity, $new_quantity);
                }
            }


        }
        elseif( $product->product_type === 'simple' ){

            $old_quantity = $quantity = $product->get_stock_quantity();

            if ( !empty( $_POST['add_stock'] ) ) {
                $quantity = $quantity + intval( $_POST['add_stock'] );
                $stock_action = 'add_stock';
            } elseif ( !empty( $_POST['delete_stock'] ) ) {
                $quantity = $quantity - intval( $_POST['delete_stock'] );
                $stock_action = 'delete_stock';
            } elseif ( !empty( $_POST['replace_stock'] ) ) {
                $quantity = intval( $_POST['replace_stock'] ) - intval(  $_POST['on_orders'] );
                $stock_action = 'replace_stock';
            }


            if( $stock_action ) {
                $new_quantity = wc_update_product_stock($product, $quantity);
                $this->create_log($product, $stock_action, $old_quantity, $new_quantity);
            }
        }


    }

}
