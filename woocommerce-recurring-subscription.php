<?php
/*
Plugin Name: WooCommerce Recurring Subscription
Description: Adds recurring subscription functionality to WooCommerce.
Version: 1.0
Author: Your Name
*/

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Hook into plugins_loaded to ensure WooCommerce is loaded first.
add_action('plugins_loaded', 'wc_recurring_subscription_init', 20);

function wc_recurring_subscription_init() {
	if (class_exists('WC_Product')) {
		// Custom subscription product type.
		class WC_Product_Subscription extends WC_Product {
			protected $product_type = 'subscription';

			public function __construct($product) {
				parent::__construct($product);
			}
		}

		// Initialize the plugin.
		class WC_Recurring_Subscription {
			public function __construct() {
				add_action('init', [$this, 'register_subscription_product_type']);
				add_action('woocommerce_product_data_tabs', [$this, 'add_subscription_product_tabs']);
				add_action('woocommerce_product_data_panels', [$this, 'display_subscription_product_tab']);
				add_action('woocommerce_process_product_meta', [$this, 'save_subscription_tab_fields']);
				add_action('woocommerce_single_product_summary', [$this, 'display_subscription_product_details'], 25);
				add_filter('woocommerce_add_cart_item_data', [$this, 'add_subscription_to_cart_item'], 10, 2);
				add_filter('woocommerce_get_item_data', [$this, 'display_subscription_cart_item'], 10, 2);
				add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_subscription_order_item_meta'], 10, 4);
				add_action('woocommerce_checkout_order_processed', [$this, 'process_order_no_payment']);
				add_action('admin_footer', [$this, 'enable_product_general_tab']);
			}

			function enable_product_general_tab() {
				if ('product' != get_post_type()) :
					return;
				endif;
				?>
                <script type='text/javascript'>
                    jQuery(document).ready(function () {
                        jQuery('.product_data_tabs .general_tab').addClass('show_if_subscription').show();
                        jQuery('#general_product_data .pricing').addClass('show_if_subscription').show();
                    });
                </script>
				<?php

			}

			public function register_subscription_product_type() {
				add_filter('product_type_selector', function($types) {
					$types['subscription'] = __('Subscription', 'woocommerce');
					return $types;
				});
			}

			public function add_subscription_product_tabs($tabs) {
				$tabs['subscription'] = [
					'label'    => __('Subscription', 'woocommerce'),
					'target'   => 'subscription_product_data',
					'class'    => ['show_if_subscription'],
                    'priority' => 21,
				];
				return $tabs;
			}

			public function display_subscription_product_tab() {
				global $post;

				?>
				<div id="subscription_product_data" class="panel woocommerce_options_panel hidden">
                    <input type="hidden" name="_sold_individually" id="_sold_individually" value="yes" class="checkbox">
					<div class="options_group">
						<?php
						woocommerce_wp_text_input([
							'id'          => '_subscription_interval',
							'label'       => __('Subscription Interval (in months)', 'woocommerce'),
							'description' => __('Interval for the subscription', 'woocommerce'),
							'type'        => 'number',
							'desc_tip'    => true,
						]);
						?>
					</div>
					<div class="options_group">
						<?php
						woocommerce_wp_checkbox([
							'id' => '_no_payment',
							'label' => __('No Payment Required', 'woocommerce'),
							'description' => __('Check if no payment is required for the subscription', 'woocommerce'),
							'cbvalue' => 'yes',
						]);
						?>
					</div>

					<div class="options_group">
						<?php
						$feature_count = get_post_meta($post->ID, '_feature_count', true);
						$feature_count = !empty($feature_count) ? $feature_count : 0;

						woocommerce_wp_text_input([
							'id'          => '_feature_count',
							'label'       => __('Number of Features', 'woocommerce'),
							'description' => __('Number of features included in the subscription', 'woocommerce'),
							'type'        => 'number',
							'desc_tip'    => true,
						]);

						for ($i = 1; $i <= $feature_count; $i++) {
							woocommerce_wp_text_input([
								'id'          => '_feature_name_' . $i,
								'label'       => sprintf(__('Feature %d Name', 'woocommerce'), $i),
								'description' => __('Name of the feature', 'woocommerce'),
								'type'        => 'text',
								'desc_tip'    => true,
							]);

							woocommerce_wp_select([
								'id'          => '_feature_included_' . $i,
								'label'       => sprintf(__('Feature %d Included', 'woocommerce'), $i),
								'description' => __('Whether the feature is included in the subscription', 'woocommerce'),
								'options'     => [
									'yes' => __('Yes', 'woocommerce'),
									'no'  => __('No', 'woocommerce'),
								],
							]);
						}
						?>
					</div>
				</div>
				<?php
			}

			public function save_subscription_tab_fields($post_id) {
				$subscription_interval = isset($_POST['_subscription_interval']) ? sanitize_text_field($_POST['_subscription_interval']) : '';
				update_post_meta($post_id, '_subscription_interval', $subscription_interval);
				$no_payment = isset($_POST['_no_payment']) ? 'yes' : 'no';
				update_post_meta($post_id, '_no_payment', $no_payment);

				$feature_count = isset($_POST['_feature_count']) ? absint($_POST['_feature_count']) : 0;
				update_post_meta($post_id, '_feature_count', $feature_count);

				for ($i = 1; $i <= $feature_count; $i++) {
					$feature_name = isset($_POST['_feature_name_' . $i]) ? sanitize_text_field($_POST['_feature_name_' . $i]) : '';
					$feature_included = isset($_POST['_feature_included_' . $i]) ? sanitize_text_field($_POST['_feature_included_' . $i]) : '';

					update_post_meta($post_id, '_feature_name_' . $i, $feature_name);
					update_post_meta($post_id, '_feature_included_' . $i, $feature_included);
				}
			}

			public function display_subscription_product_details() {
				global $product;

				if ('subscription' === $product->get_type()) {
					$subscription_interval = get_post_meta($product->get_id(), '_subscription_interval', true);

					echo '<div class="woocommerce-subscription-details">';


//                    dd(get_class_methods($product));
                    echo $product->get_short_description();

					$feature_count = get_post_meta($product->get_id(), '_feature_count', true);
					if ($feature_count) {
						echo '<ul class="woocommerce-product-features">';
						for ($i = 1; $i <= $feature_count; $i++) {
							$feature_name = get_post_meta($product->get_id(), '_feature_name_' . $i, true);
							$feature_included = get_post_meta($product->get_id(), '_feature_included_' . $i, true);
							echo '<li>' . esc_html($feature_name) . ': ' . ($feature_included === 'yes' ? __('Included', 'woocommerce') : __('Not Included', 'woocommerce')) . '</li>';
						}
						echo '</ul>';
					}

					// Display the add to cart form
					$this->add_subscription_add_to_cart();

					echo '</div>';
				}
			}

			public function add_subscription_add_to_cart() {
				global $product;
				if ('subscription' === $product->get_type()) {
					woocommerce_simple_add_to_cart();
				}
			}

			public function add_subscription_to_cart_item($cart_item_data, $product_id) {
				$product = wc_get_product($product_id);

				if ('subscription' === $product->get_type()) {
					$cart_item_data['subscription_interval'] = get_post_meta($product_id, '_subscription_interval', true);
				}

				return $cart_item_data;
			}

			public function display_subscription_cart_item($item_data, $cart_item) {
				if (isset($cart_item['subscription_interval'])) {
					$item_data[] = [
						'key' => __('Subscription Interval', 'woocommerce'),
						'value' => sprintf(__('%s month(s)', 'woocommerce'), $cart_item['subscription_interval']),
					];
				}

				return $item_data;
			}

			public function add_subscription_order_item_meta($item, $cart_item_key, $values, $order) {
				if (isset($values['subscription_interval'])) {
					$item->add_meta_data(__('Subscription Interval', 'woocommerce'), $values['subscription_interval']);
				}
			}

			public function process_order_no_payment($order_id) {
				$order = wc_get_order($order_id);
				foreach ($order->get_items() as $item_id => $item) {
					$product = $item->get_product();
					if ('yes' === get_post_meta($product->get_id(), '_no_payment', true)) {
						$item->set_total(0);
						$item->save();
					}
				}

				$order->calculate_totals();
				$order->save();
			}
		}

		new WC_Recurring_Subscription();
	} else {
		add_action('admin_notices', function() {
			echo '<div class="error"><p>' . __('WooCommerce Recurring Subscription requires WooCommerce to be active.', 'woocommerce') . '</p></div>';
		});
	}
}
?>
