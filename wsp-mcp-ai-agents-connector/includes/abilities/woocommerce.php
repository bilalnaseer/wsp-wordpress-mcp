<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_woo_sideload_image_by_url( $url, $post_id ) {
    if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
        return false;
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // Scoped SSL bypass only for this specific image download
    $ssl_bypass = function( $args, $req_url ) use ( $url ) {
        if ( $req_url === $url ) {
            if ( function_exists( 'wp_get_environment_type' ) ) {
                if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
                    $args['sslverify'] = false;
                }
            }
        }
        return $args;
    };
    add_filter( 'http_request_args', $ssl_bypass, 10, 2 );

    $tmp = download_url( $url );

    // Remove the filter immediately so it doesn't affect the rest of the site
    remove_filter( 'http_request_args', $ssl_bypass, 10 );

    if ( is_wp_error( $tmp ) ) {
        return false;
    }

    $file_array = array(
        'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
        'tmp_name' => $tmp,
    );

    $id = media_handle_sideload( $file_array, $post_id );
    if ( is_wp_error( $id ) ) {
        wp_delete_file( $file_array['tmp_name'] );
        return false;
    }

    return $id;
}

function wsp_woo_set_product_attributes( $product, $attributes_input ) {
    if ( ! is_array( $attributes_input ) ) {
        return;
    }

    $product_attributes = array();

    foreach ( $attributes_input as $attr_data ) {
        if ( empty( $attr_data['name'] ) || empty( $attr_data['options'] ) ) {
            continue;
        }

        $attribute = new WC_Product_Attribute();
        $attribute->set_name( sanitize_text_field( $attr_data['name'] ) );
        
        $options = is_array( $attr_data['options'] ) ? $attr_data['options'] : array_map( 'trim', explode( '|', $attr_data['options'] ) );
        $attribute->set_options( array_map( 'sanitize_text_field', $options ) );
        
        $attribute->set_position( 0 );
        $attribute->set_visible( true );
        $attribute->set_variation( true ); 

        $product_attributes[] = $attribute;
    }

    $product->set_attributes( $product_attributes );
}

// ---------------------------------------------
// EXECUTE CALLBACKS
// ---------------------------------------------

function wsp_execute_woo_get_products( $input ) {
    $limit  = isset( $input['limit'] ) ? intval( $input['limit'] ) : 10;
    $status = isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'any';

    $products = wc_get_products( array( 'limit' => $limit, 'status' => $status ) );
    $result = array();
    foreach ( $products as $p ) {
        $result[] = array(
            'id'            => $p->get_id(),
            'name'          => $p->get_name(),
            'price'         => $p->get_price(),
            'regular_price' => $p->get_regular_price(),
            'status'        => $p->get_status(),
            'sku'           => $p->get_sku(),
            'stock_status'  => $p->get_stock_status(),
        );
    }
    return array( 'products' => $result, 'total' => count( $result ) );
}

function wsp_execute_woo_get_product( $input ) {
    $id = intval( $input['id'] );
    $p  = wc_get_product( $id );
    if ( ! $p ) return array( 'success' => false, 'error' => 'Product not found.' );

    return array(
        'id'            => $p->get_id(),
        'name'          => $p->get_name(),
        'description'   => wp_strip_all_tags( $p->get_description() ),
        'price'         => $p->get_price(),
        'regular_price' => $p->get_regular_price(),
        'sku'           => $p->get_sku(),
        'status'        => $p->get_status(),
    );
}

function wsp_execute_woo_create_product( $input ) {
    $name  = sanitize_text_field( wp_unslash( $input['name'] ) );
    $price = sanitize_text_field( wp_unslash( $input['regular_price'] ) );
    $desc  = isset( $input['description'] ) ? wp_kses_post( wp_unslash( $input['description'] ) ) : '';
    $sku   = isset( $input['sku'] ) ? sanitize_text_field( wp_unslash( $input['sku'] ) ) : '';
    $status = isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'draft';
    $type   = isset( $input['type'] ) ? sanitize_text_field( wp_unslash( $input['type'] ) ) : 'simple';

    if ( 'variable' === $type ) {
        $p = new WC_Product_Variable();
    } else {
        $p = new WC_Product_Simple();
    }

    $p->set_name( $name );
    $p->set_status( $status );
    $p->set_description( $desc );

    if ( 'variable' !== $type ) {
        $p->set_regular_price( $price );
        if ( ! empty( $input['sale_price'] ) ) {
            $p->set_sale_price( sanitize_text_field( wp_unslash( $input['sale_price'] ) ) );
        }
        if ( isset( $input['stock_qty'] ) ) {
            $p->set_manage_stock( true );
            $p->set_stock_quantity( intval( $input['stock_qty'] ) );
        }
    } else {
        if ( ! empty( $input['attributes'] ) ) {
            wsp_woo_set_product_attributes( $p, $input['attributes'] );
        }
    }

    if ( ! empty( $sku ) ) {
        $p->set_sku( $sku );
    }

    $product_id = $p->save();
    if ( ! $product_id ) return array( 'success' => false, 'error' => 'Could not save product.' );

    $image_id = false;
    if ( ! empty( $input['image_url'] ) ) {
        $img_id = wsp_woo_sideload_image_by_url( $input['image_url'], $product_id );
        if ( $img_id ) {
            $p->set_image_id( $img_id );
            $p->save();
            $image_id = $img_id;
        }
    }

    return array(
        'success'   => true,
        'id'        => $product_id,
        'name'      => $name,
        'type'      => $type,
        'price'     => $p->get_price(),
        'image_id'  => $image_id,
        'permalink' => get_permalink( $product_id )
    );
}

function wsp_execute_woo_create_variation( $input ) {
    $parent_id = intval( $input['parent_id'] );
    $parent    = wc_get_product( $parent_id );
    if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
        return array( 'success' => false, 'error' => 'Parent product must be a variable product.' );
    }

    $variation = new WC_Product_Variation();
    $variation->set_parent_id( $parent_id );

    if ( ! empty( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
        $formatted_attrs = array();
        foreach ( $input['attributes'] as $key => $val ) {
            $attr_key = sanitize_title( $key );
            if ( 0 !== strpos( $attr_key, 'attribute_' ) ) {
                $attr_key = 'attribute_' . $attr_key;
            }
            $formatted_attrs[ $attr_key ] = sanitize_title( $val );
        }
        $variation->set_attributes( $formatted_attrs );
    }

    $variation->set_regular_price( sanitize_text_field( wp_unslash( $input['regular_price'] ) ) );
    if ( ! empty( $input['sale_price'] ) ) {
        $variation->set_sale_price( sanitize_text_field( wp_unslash( $input['sale_price'] ) ) );
    }
    if ( ! empty( $input['sku'] ) ) {
        $variation->set_sku( sanitize_text_field( wp_unslash( $input['sku'] ) ) );
    }

    $variation_id = $variation->save();
    if ( ! $variation_id ) return array( 'success' => false, 'error' => 'Could not save variation.' );

    if ( ! empty( $input['image_url'] ) ) {
        $img_id = wsp_woo_sideload_image_by_url( $input['image_url'], $variation_id );
        if ( $img_id ) {
            $variation->set_image_id( $img_id );
            $variation->save();
        }
    }

    return array(
        'success'      => true,
        'variation_id' => $variation_id,
        'parent_id'    => $parent_id,
        'price'        => $variation->get_price(),
    );
}

function wsp_execute_woo_update_product( $input ) {
    $id = intval( $input['id'] );
    $p  = wc_get_product( $id );
    if ( ! $p ) {
        return array( 'success' => false, 'error' => 'Product not found.' );
    }

    $updated = array();

    if ( isset( $input['name'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['name'] ) );
        $p->set_name( $value );
        $updated['name'] = $value;
    }

    if ( isset( $input['regular_price'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['regular_price'] ) );
        $p->set_regular_price( $value );
        $updated['regular_price'] = $value;
    }

    if ( isset( $input['sale_price'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['sale_price'] ) );
        $p->set_sale_price( $value );
        $updated['sale_price'] = $value;
    }

    if ( isset( $input['description'] ) ) {
        $value = wp_kses_post( wp_unslash( $input['description'] ) );
        $p->set_description( $value );
        $updated['description'] = $value;
    }

    if ( isset( $input['sku'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['sku'] ) );
        $p->set_sku( $value );
        $updated['sku'] = $value;
    }

    if ( isset( $input['stock_qty'] ) ) {
        $p->set_manage_stock( true );
        $p->set_stock_quantity( intval( $input['stock_qty'] ) );
        $updated['stock_qty'] = intval( $input['stock_qty'] );
    }

    if ( isset( $input['stock_status'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['stock_status'] ) );
        $p->set_stock_status( $value );
        $updated['stock_status'] = $value;
    }

    if ( ! empty( $input['image_url'] ) ) {
        $img_id = wsp_woo_sideload_image_by_url( $input['image_url'], $id );
        if ( $img_id ) {
            $p->set_image_id( $img_id );
            $updated['image_url'] = sanitize_text_field( wp_unslash( $input['image_url'] ) );
        }
    }

    if ( ! empty( $updated ) ) {
        $p->save();
    } else {
        return array( 'success' => false, 'error' => 'No fields to update provided.' );
    }

    return array(
        'success' => true,
        'id'      => $id,
        'updated' => $updated,
    );
}

function wsp_execute_woo_create_coupon( $input ) {
    $code   = sanitize_text_field( wp_unslash( $input['code'] ) );
    $amount = sanitize_text_field( wp_unslash( $input['amount'] ) );
    $type   = isset( $input['discount_type'] ) ? sanitize_text_field( wp_unslash( $input['discount_type'] ) ) : 'percent';

    // Fixed: Added validation for discount_type
    $allowed_types = array( 'percent', 'fixed_cart', 'fixed_product' );
    if ( ! in_array( $type, $allowed_types, true ) ) {
        return array( 'success' => false, 'error' => 'Invalid discount_type. Allowed values: percent, fixed_cart, fixed_product' );
    }

    $coupon = new WC_Coupon();
    $coupon->set_code( $code );
    $coupon->set_amount( $amount );
    $coupon->set_discount_type( $type );
    
    if ( ! empty( $input['expiry_date'] ) ) {
        $coupon->set_date_expires( strtotime( $input['expiry_date'] ) );
    }

    $coupon_id = $coupon->save();
    if ( ! $coupon_id ) return array( 'success' => false, 'error' => 'Could not save coupon.' );

    return array(
        'success' => true,
        'id'      => $coupon_id,
        'code'    => $code,
        'amount'  => $amount,
        'type'    => $type,
    );
}

function wsp_execute_woo_list_coupons( $input ) {
    $limit = isset( $input['limit'] ) ? intval( $input['limit'] ) : 20;
    
    $coupon_posts = get_posts( array(
        'post_type'      => 'shop_coupon',
        'posts_per_page' => $limit,
        'post_status'    => 'any',
    ) );

    $result = array();
    foreach ( $coupon_posts as $post ) {
        $c = new WC_Coupon( $post->ID );
        $result[] = array(
            'id'            => $c->get_id(),
            'code'          => $c->get_code(),
            'amount'        => $c->get_amount(),
            'discount_type' => $c->get_discount_type(),
            'usage_count'   => $c->get_usage_count(),
            'expiry_date'   => $c->get_date_expires() ? $c->get_date_expires()->date( 'Y-m-d' ) : 'Never',
        );
    }
    return array( 'coupons' => $result, 'total' => count( $result ) );
}

function wsp_execute_woo_create_order_note( $input ) {
    $id        = intval( $input['id'] );
    $note      = sanitize_textarea_field( wp_unslash( $input['note'] ) );
    $is_public = ! empty( $input['is_public'] ) ? 1 : 0;

    $order = wc_get_order( $id );
    if ( ! $order ) return array( 'success' => false, 'error' => 'Order not found.' );

    $comment_id = $order->add_order_note( $note, $is_public, true );
    if ( ! $comment_id ) return array( 'success' => false, 'error' => 'Could not add order note.' );

    return array(
        'success'   => true,
        'id'        => $id,
        'note_id'   => $comment_id,
        'is_public' => $is_public,
    );
}

function wsp_execute_woo_list_customers( $input ) {
    $limit = isset( $input['limit'] ) ? intval( $input['limit'] ) : 10;
    
    $users = get_users( array(
        'role'    => 'customer',
        'number'  => $limit,
        'orderby' => 'registered',
        'order'   => 'DESC',
    ) );

    $result = array();
    foreach ( $users as $u ) {
        $result[] = array(
            'id'            => $u->ID,
            'display_name'  => $u->display_name,
            'email'         => $u->user_email,
            'billing_phone' => get_user_meta( $u->ID, 'billing_phone', true ),
            'order_count'   => wc_get_customer_order_count( $u->ID ),
        );
    }
    return array( 'customers' => $result, 'total' => count( $result ) );
}

function wsp_execute_woo_report_sales( $input ) {
    $days = isset( $input['days'] ) ? intval( $input['days'] ) : 30;
    $date_after = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

    $orders = wc_get_orders( array(
        'limit'        => -1,
        'status'       => array( 'processing', 'completed' ),
        'date_created' => '>=' . $date_after,
    ) );

    $total_orders  = count( $orders );
    $total_revenue = 0.0;
    $total_tax     = 0.0;
    $total_shipping = 0.0;

    foreach ( $orders as $order ) {
        $total_revenue  += (float) $order->get_total();
        $total_tax      += (float) $order->get_cart_tax() + (float) $order->get_shipping_tax();
        $total_shipping += (float) $order->get_shipping_total();
    }

    $net_revenue = $total_revenue - $total_tax - $total_shipping;
    $avg_order   = $total_orders > 0 ? ( $total_revenue / $total_orders ) : 0.0;

    return array(
        'success'            => true,
        'days'               => $days,
        'orders_count'       => $total_orders,
        'gross_revenue'      => round( $total_revenue, 2 ),
        'net_revenue'        => round( $net_revenue, 2 ),
        'total_tax'          => round( $total_tax, 2 ),
        'total_shipping'     => round( $total_shipping, 2 ),
        'average_order_val'  => round( $avg_order, 2 ),
        'currency'           => get_woocommerce_currency(),
    );
}

function wsp_execute_woo_get_low_stock( $input ) {
    $threshold = isset( $input['threshold'] ) ? intval( $input['threshold'] ) : 10;
    $products = wc_get_products( array(
        'limit'        => -1,
        'stock_status' => 'instock',
        'manage_stock' => true,
    ) );

    $low_stock = array();
    foreach ( $products as $p ) {
        $qty = $p->get_stock_quantity();
        if ( null !== $qty && $qty <= $threshold ) {
            $low_stock[] = array(
                'id'        => $p->get_id(),
                'name'      => $p->get_name(),
                'sku'       => $p->get_sku(),
                'stock_qty' => $qty,
            );
        }
    }

    $out_of_stock_products = wc_get_products( array(
        'limit'        => -1,
        'stock_status' => 'outofstock',
    ) );
    $out_of_stock = array();
    foreach ( $out_of_stock_products as $p ) {
        $out_of_stock[] = array(
            'id'   => $p->get_id(),
            'name' => $p->get_name(),
            'sku'  => $p->get_sku(),
        );
    }

    return array(
        'success'      => true,
        'low_stock'    => $low_stock,
        'out_of_stock' => $out_of_stock,
    );
}

function wsp_execute_woo_moderate_review( $input ) {
    $review_id = intval( $input['id'] );
    $action    = sanitize_text_field( wp_unslash( $input['action'] ) ); 
    
    // Fixed: Added validation for action
    $allowed_actions = array( 'approve', 'spam', 'trash', 'reply' );
    if ( ! in_array( $action, $allowed_actions, true ) ) {
        return array( 'success' => false, 'error' => 'Invalid action. Allowed values: approve, spam, trash, reply' );
    }

    $review = get_comment( $review_id );
    if ( ! $review ) {
        return array( 'success' => false, 'error' => 'Review not found.' );
    }

    if ( 'reply' === $action ) {
        if ( empty( $input['reply_text'] ) ) {
            return array( 'success' => false, 'error' => 'Reply text is required for reply action.' );
        }
        
        $user = wp_get_current_user();
        $comment_id = wp_insert_comment( array(
            'comment_post_ID' => $review->comment_post_ID,
            'comment_author'  => $user->display_name,
            'comment_author_email' => $user->user_email,
            'comment_content' => sanitize_textarea_field( wp_unslash( $input['reply_text'] ) ),
            'comment_parent'  => $review_id,
            'user_id'         => $user->ID,
            'comment_approved' => 1,
        ) );
        
        return array( 'success' => true, 'message' => 'Reply added successfully.', 'reply_id' => $comment_id );
    }

    $status = 'approve';
    if ( 'spam' === $action ) $status = 'spam';
    if ( 'trash' === $action ) $status = 'trash';

    $result = wp_set_comment_status( $review_id, $status );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array( 'success' => true, 'message' => 'Review status updated to: ' . $status );
}

function wsp_execute_woo_list_orders( $input ) {
    $limit  = isset( $input['limit'] ) ? intval( $input['limit'] ) : 10;
    $status = isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'any';

    $orders = wc_get_orders( array( 'limit' => $limit, 'status' => $status ) );
    $result = array();
    foreach ( $orders as $o ) {
        $result[] = array(
            'id'            => $o->get_id(),
            'status'        => $o->get_status(),
            'total'         => $o->get_total(),
            'customer_name' => $o->get_billing_first_name() . ' ' . $o->get_billing_last_name(),
        );
    }
    return array( 'orders' => $result, 'total' => count( $result ) );
}

function wsp_execute_woo_update_order_status( $input ) {
    $id     = intval( $input['id'] );
    $status = sanitize_text_field( wp_unslash( $input['status'] ) );

    // Fixed: Added validation for order status
    $allowed = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
    if ( ! in_array( str_replace('wc-', '', $status), $allowed, true ) ) {
        return array( 'success' => false, 'error' => 'Invalid status. Allowed values: ' . implode(', ', $allowed) );
    }

    $o = wc_get_order( $id );
    if ( ! $o ) return array( 'success' => false, 'error' => 'Order not found.' );

    $o->update_status( $status, 'Updated via AI Agent (MCP).' );
    $o->save();

    return array( 'success' => true, 'id' => $id, 'new_status' => $status );
}

function wsp_execute_woo_refund_order( $input ) {
    $order_id = intval( $input['order_id'] );
    $amount   = (float) $input['amount'];
    $reason   = isset( $input['reason'] ) ? sanitize_text_field( wp_unslash( $input['reason'] ) ) : 'Refunded via AI Agent';

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return array( 'success' => false, 'error' => 'Order not found.' );
    }

    if ( $amount <= 0 ) {
        return array( 'success' => false, 'error' => 'Refund amount must be greater than 0.' );
    }

    $refund = wc_create_refund( array(
        'amount'         => $amount,
        'reason'         => $reason,
        'order_id'       => $order_id,
        'refund_payment' => true, 
    ) );

    if ( is_wp_error( $refund ) ) {
        return array( 'success' => false, 'error' => $refund->get_error_message() );
    }

    return array(
        'success'   => true,
        'refund_id' => $refund->get_id(),
        'order_id'  => $order_id,
        'amount'    => $amount,
        'reason'    => $reason,
    );
}

function wsp_execute_woo_list_shipping_zones( $input ) {
    if ( ! class_exists( 'WC_Shipping_Zones' ) ) return array( 'success' => false, 'error' => 'WooCommerce shipping is not available.' );

    $result = array();
    foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
        $zone    = new WC_Shipping_Zone( $zone_data['id'] );
        $methods = array();
        foreach ( $zone->get_shipping_methods() as $method ) {
            $method->init_instance_settings();
            $methods[] = array(
                'instance_id' => $method->instance_id,
                'method_id'   => $method->id,
                'title'       => $method->instance_settings['title'] ?? $method->method_title,
                'enabled'     => 'yes' === $method->enabled,
                'cost'        => $method->instance_settings['cost'] ?? null,
            );
        }
        $result[] = array(
            'id'        => $zone->get_id(),
            'name'      => $zone->get_zone_name(),
            'order'     => $zone->get_zone_order(),
            'locations' => $zone->get_zone_locations(),
            'methods'   => $methods,
        );
    }

    // "Locations not covered by your other zones" (id 0), read-only in WooCommerce itself.
    $rest_of_world = new WC_Shipping_Zone( 0 );
    $rest_methods  = array();
    foreach ( $rest_of_world->get_shipping_methods() as $method ) {
        $method->init_instance_settings();
        $rest_methods[] = array(
            'instance_id' => $method->instance_id,
            'method_id'   => $method->id,
            'title'       => $method->instance_settings['title'] ?? $method->method_title,
            'enabled'     => 'yes' === $method->enabled,
            'cost'        => $method->instance_settings['cost'] ?? null,
        );
    }
    $result[] = array( 'id' => 0, 'name' => 'Locations not covered by your other zones', 'order' => 0, 'locations' => array(), 'methods' => $rest_methods );

    return array( 'success' => true, 'zones' => $result );
}

function wsp_execute_woo_create_shipping_zone( $input ) {
    if ( empty( $input['name'] ) ) return array( 'success' => false, 'error' => 'name is required.' );
    if ( ! class_exists( 'WC_Shipping_Zone' ) ) return array( 'success' => false, 'error' => 'WooCommerce shipping is not available.' );

    $zone = new WC_Shipping_Zone();
    $zone->set_zone_name( sanitize_text_field( wp_unslash( $input['name'] ) ) );

    if ( ! empty( $input['locations'] ) && is_array( $input['locations'] ) ) {
        $locations = array();
        foreach ( $input['locations'] as $loc ) {
            if ( empty( $loc['code'] ) || empty( $loc['type'] ) ) continue;
            $locations[] = array( 'code' => sanitize_text_field( $loc['code'] ), 'type' => sanitize_text_field( $loc['type'] ) );
        }
        $zone->set_locations( $locations );
    }

    $zone_id = $zone->save();
    return array( 'success' => true, 'id' => $zone_id, 'name' => $zone->get_zone_name() );
}

function wsp_execute_woo_delete_shipping_zone( $input ) {
    if ( empty( $input['zone_id'] ) ) return array( 'success' => false, 'error' => 'zone_id is required.' );
    if ( ! class_exists( 'WC_Shipping_Zones' ) ) return array( 'success' => false, 'error' => 'WooCommerce shipping is not available.' );

    $zone_id = intval( $input['zone_id'] );
    $zone    = WC_Shipping_Zones::get_zone( $zone_id );
    if ( ! $zone ) return array( 'success' => false, 'error' => 'Shipping zone not found.' );

    $zone->delete();
    return array( 'success' => true, 'id' => $zone_id );
}

function wsp_execute_woo_add_shipping_method( $input ) {
    if ( empty( $input['zone_id'] ) && '0' !== (string) ( $input['zone_id'] ?? '' ) ) return array( 'success' => false, 'error' => 'zone_id is required.' );
    if ( empty( $input['method_id'] ) ) return array( 'success' => false, 'error' => 'method_id is required, e.g. flat_rate, free_shipping, local_pickup.' );
    if ( ! class_exists( 'WC_Shipping_Zones' ) ) return array( 'success' => false, 'error' => 'WooCommerce shipping is not available.' );

    $zone_id   = intval( $input['zone_id'] );
    $method_id = sanitize_key( $input['method_id'] );
    $zone      = WC_Shipping_Zones::get_zone( $zone_id );
    if ( ! $zone ) return array( 'success' => false, 'error' => 'Shipping zone not found.' );

    $instance_id = $zone->add_shipping_method( $method_id );
    if ( ! $instance_id ) return array( 'success' => false, 'error' => 'Unknown or unsupported method_id: ' . $method_id );

    if ( ! empty( $input['settings'] ) && is_array( $input['settings'] ) ) {
        $result = wsp_woo_update_shipping_method_instance( $instance_id, $input['settings'], null );
        if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array( 'success' => true, 'zone_id' => $zone_id, 'instance_id' => $instance_id, 'method_id' => $method_id );
}

function wsp_execute_woo_update_shipping_method( $input ) {
    if ( empty( $input['instance_id'] ) ) return array( 'success' => false, 'error' => 'instance_id is required.' );

    $instance_id = intval( $input['instance_id'] );
    $settings    = ! empty( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
    $enabled     = isset( $input['enabled'] ) ? (bool) $input['enabled'] : null;

    $result = wsp_woo_update_shipping_method_instance( $instance_id, $settings, $enabled );
    if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );

    return array( 'success' => true, 'instance_id' => $instance_id );
}

function wsp_execute_woo_delete_shipping_method( $input ) {
    if ( empty( $input['zone_id'] ) && '0' !== (string) ( $input['zone_id'] ?? '' ) ) return array( 'success' => false, 'error' => 'zone_id is required.' );
    if ( empty( $input['instance_id'] ) ) return array( 'success' => false, 'error' => 'instance_id is required.' );
    if ( ! class_exists( 'WC_Shipping_Zones' ) ) return array( 'success' => false, 'error' => 'WooCommerce shipping is not available.' );

    $zone = WC_Shipping_Zones::get_zone( intval( $input['zone_id'] ) );
    if ( ! $zone ) return array( 'success' => false, 'error' => 'Shipping zone not found.' );

    $zone->delete_shipping_method( intval( $input['instance_id'] ) );
    return array( 'success' => true, 'instance_id' => intval( $input['instance_id'] ) );
}

/**
 * Shared helper: applies settings/enabled changes to a shipping method instance.
 * Mirrors WooCommerce's own REST API update path (WC_REST_Shipping_Zone_Methods_V2_Controller::update_fields)
 * since WC_Data_Store exposes no public method for this.
 */
function wsp_woo_update_shipping_method_instance( $instance_id, $settings, $enabled ) {
    global $wpdb;

    $method_type = $wpdb->get_var( $wpdb->prepare( "SELECT method_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE instance_id = %d", $instance_id ) );
    if ( ! $method_type ) return new WP_Error( 'not_found', 'Shipping method instance not found: ' . $instance_id );

    $classes = WC_Shipping::instance()->get_shipping_method_class_names();
    if ( ! isset( $classes[ $method_type ] ) ) return new WP_Error( 'not_found', 'Unknown shipping method type: ' . $method_type );

    $method = new $classes[ $method_type ]( $instance_id );

    if ( ! empty( $settings ) ) {
        $method->init_instance_settings();
        $instance_settings = $method->instance_settings;
        foreach ( $method->get_instance_form_fields() as $key => $field ) {
            if ( isset( $settings[ $key ] ) ) {
                $instance_settings[ $key ] = sanitize_text_field( wp_unslash( (string) $settings[ $key ] ) );
            }
        }
        update_option( $method->get_instance_option_key(), $instance_settings );
    }

    if ( null !== $enabled ) {
        $wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'is_enabled' => $enabled ? 1 : 0 ), array( 'instance_id' => $instance_id ) );
    }

    WC_Cache_Helper::get_transient_version( 'shipping', true );
    return true;
}
?>