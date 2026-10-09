<?php
/**
 * Blocks (Gutenberg): reusable blocks, patterns, block types, and per-post block content.
 *
 * - "Blocks" (list/get/create/update/delete) are reusable blocks, i.e. `wp_block` posts.
 * - Patterns and block types are read-only views of WordPress' registries.
 * - get/update_post_blocks read and rewrite a post's block tree via core's
 *   parse_blocks() / serialize_blocks().
 *
 * Every write goes through the object-level guards in guard.php and a final
 * wp_kses_post() over the serialized markup (no wp_unslash — see AGENTS.md,
 * "block markup"; post arrays are wp_slash()ed before wp_update_post()).
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'WSP_BLOCKS_MAX_NODES' ) ) define( 'WSP_BLOCKS_MAX_NODES', 2000 );
if ( ! defined( 'WSP_BLOCKS_MAX_DEPTH' ) ) define( 'WSP_BLOCKS_MAX_DEPTH', 20 );

/* ---------------------------------------------------------------- helpers */

/** Normalize one reusable block row. */
function wsp_blocks_item_data( $post, $full = false ) {
	$sync = get_post_meta( $post->ID, 'wp_pattern_sync_status', true );
	$data = array(
		'id'          => (int) $post->ID,
		'title'       => $post->post_title,
		'slug'        => $post->post_name,
		'status'      => $post->post_status,
		'sync_status' => ( 'unsynced' === $sync ) ? 'unsynced' : 'synced',
		'modified'    => gmdate( 'c', strtotime( $post->post_modified_gmt . ' UTC' ) ),
	);
	if ( $full ) {
		$data['content']   = $post->post_content;
		$data['used_in']   = wsp_blocks_used_in( $post->ID );
		$data['edit_link'] = get_edit_post_link( $post->ID, 'raw' );
	}
	return $data;
}

/** Posts that embed a reusable block (`<!-- wp:block {"ref":ID} /-->`), max 50, only those the caller can edit. */
function wsp_blocks_used_in( $block_id ) {
	$block_id = (int) $block_id;
	$posts    = get_posts( array(
		's'                => '"ref":' . $block_id,
		'post_type'        => 'any',
		'post_status'      => 'any',
		'posts_per_page'   => 100,
		'suppress_filters' => true,
		'no_found_rows'    => true,
	) );
	$out = array();
	foreach ( $posts as $p ) {
		if ( 'wp_block' === $p->post_type && (int) $p->ID === $block_id ) {
			continue;
		}
		// "ref":12 must not match "ref":123.
		if ( preg_match( '/"ref":' . $block_id . '(?!\d)/', $p->post_content ) && current_user_can( 'edit_post', $p->ID ) ) {
			$out[] = array( 'id' => (int) $p->ID, 'type' => $p->post_type, 'title' => $p->post_title );
		}
		if ( count( $out ) >= 50 ) {
			break;
		}
	}
	return $out;
}

/** Validate a sync_status input. */
function wsp_blocks_sync_status( $input ) {
	if ( ! isset( $input['sync_status'] ) ) {
		return null;
	}
	$s = sanitize_key( $input['sync_status'] );
	return in_array( $s, array( 'synced', 'unsynced' ), true ) ? $s : new WP_Error( 'invalid_param', "sync_status must be 'synced' or 'unsynced'." );
}

/** Persist sync status the way the block editor does: meta only for "unsynced". */
function wsp_blocks_save_sync_status( $post_id, $sync ) {
	if ( 'unsynced' === $sync ) {
		update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );
	} elseif ( 'synced' === $sync ) {
		delete_post_meta( $post_id, 'wp_pattern_sync_status' );
	}
}

/** Recursively kses block attribute strings; keys are plain text. */
function wsp_blocks_sanitize_attrs( $value ) {
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ is_string( $k ) ? sanitize_text_field( $k ) : $k ] = wsp_blocks_sanitize_attrs( $v );
		}
		return $out;
	}
	if ( is_string( $value ) ) {
		return wp_kses_post( $value );
	}
	return ( is_scalar( $value ) || null === $value ) ? $value : '';
}

/**
 * Turn caller JSON into the array shape serialize_blocks() expects.
 *
 * @param mixed    $blocks Caller-supplied list.
 * @param int      $depth  Current nesting depth.
 * @param int      $nodes  Running node count (by reference).
 * @return array|WP_Error
 */
function wsp_blocks_normalize( $blocks, $depth, &$nodes ) {
	if ( ! is_array( $blocks ) ) {
		return new WP_Error( 'invalid_blocks', 'blocks must be an array of block objects.' );
	}
	if ( $depth > WSP_BLOCKS_MAX_DEPTH ) {
		return new WP_Error( 'too_deep', 'Blocks are nested more than ' . WSP_BLOCKS_MAX_DEPTH . ' levels deep.' );
	}
	$registry = WP_Block_Type_Registry::get_instance();
	$out      = array();
	foreach ( array_values( $blocks ) as $i => $b ) {
		if ( ++$nodes > WSP_BLOCKS_MAX_NODES ) {
			return new WP_Error( 'too_many_blocks', 'More than ' . WSP_BLOCKS_MAX_NODES . ' blocks.' );
		}
		if ( ! is_array( $b ) ) {
			return new WP_Error( 'invalid_blocks', 'Block #' . $i . ' is not an object.' );
		}
		$name = isset( $b['blockName'] ) ? $b['blockName'] : null;
		// null name = classic/freeform HTML chunk.
		if ( null !== $name ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name ) ) {
				return new WP_Error( 'invalid_block_name', 'Block #' . $i . ' has an invalid blockName (expected e.g. "core/paragraph").' );
			}
			if ( ! $registry->is_registered( $name ) ) {
				return new WP_Error( 'unknown_block', "Block type '" . esc_html( $name ) . "' is not registered. Use wsp_list_block_types to see available types." );
			}
		}
		$attrs = isset( $b['attrs'] ) && is_array( $b['attrs'] ) ? wsp_blocks_sanitize_attrs( $b['attrs'] ) : array();
		$inner = array();
		if ( ! empty( $b['innerBlocks'] ) ) {
			$inner = wsp_blocks_normalize( $b['innerBlocks'], $depth + 1, $nodes );
			if ( is_wp_error( $inner ) ) {
				return $inner;
			}
		}
		$html = isset( $b['innerHTML'] ) && is_string( $b['innerHTML'] ) ? $b['innerHTML'] : '';

		// innerContent interleaves HTML strings with one null per inner block.
		$content = isset( $b['innerContent'] ) && is_array( $b['innerContent'] ) ? array_values( $b['innerContent'] ) : null;
		if ( null !== $content ) {
			$slots = 0;
			foreach ( $content as $piece ) {
				if ( null === $piece ) {
					$slots++;
				} elseif ( ! is_string( $piece ) ) {
					return new WP_Error( 'invalid_blocks', 'innerContent of block #' . $i . ' may only hold strings and nulls.' );
				}
			}
			if ( $slots !== count( $inner ) ) {
				return new WP_Error( 'invalid_blocks', 'innerContent of block #' . $i . ' needs exactly one null per inner block (' . count( $inner ) . ').' );
			}
		} else {
			// No layout given: leaf = just the HTML; container = HTML, then the children.
			$content = array( $html );
			foreach ( $inner as $unused ) {
				$content[] = null;
			}
		}
		$out[] = array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $html,
			'innerContent' => $content,
		);
	}
	return $out;
}

/** parse_blocks() output → compact JSON-friendly tree (drops whitespace-only freeform chunks). */
function wsp_blocks_simplify( $blocks ) {
	$out = array();
	foreach ( $blocks as $b ) {
		if ( null === $b['blockName'] && '' === trim( $b['innerHTML'] ) ) {
			continue;
		}
		$out[] = array(
			'blockName'    => $b['blockName'],
			'attrs'        => (object) $b['attrs'],
			'innerHTML'    => $b['innerHTML'],
			'innerContent' => $b['innerContent'],
			'innerBlocks'  => wsp_blocks_simplify( $b['innerBlocks'] ),
		);
	}
	return $out;
}

/** Count blocks in a parsed tree. */
function wsp_blocks_count( $blocks ) {
	$n = 0;
	foreach ( $blocks as $b ) {
		$n += 1 + wsp_blocks_count( $b['innerBlocks'] );
	}
	return $n;
}

/** Resolve a `wp_block` post for update/delete, enforcing the guards. */
function wsp_blocks_guard( $id, $for_delete = false ) {
	return $for_delete ? wsp_mcp_guard_delete_post( $id, 'wp_block' ) : wsp_mcp_guard_edit_post( $id, 'wp_block' );
}

/* ------------------------------------------------- reusable blocks (wp_block) */

function wsp_execute_list_blocks( $input ) {
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, intval( $input['per_page'] ) ) ) : 20;
	$status   = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'publish';
	if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'trash', 'any' ), true ) ) {
		return new WP_Error( 'invalid_param', 'status must be publish, draft, pending, private, trash or any.' );
	}
	// Drafts/private/trash blocks belong to someone: require edit rights across the type.
	$type = get_post_type_object( 'wp_block' );
	if ( 'publish' !== $status && ( ! $type || ! current_user_can( $type->cap->edit_others_posts ) ) ) {
		return new WP_Error( 'forbidden', 'Only published blocks can be listed with your permissions.' );
	}
	$args = array(
		'post_type'      => 'wp_block',
		'post_status'    => $status,
		'posts_per_page' => $per_page,
		'paged'          => isset( $input['page'] ) ? max( 1, intval( $input['page'] ) ) : 1,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);
	if ( ! empty( $input['search'] ) ) {
		$args['s'] = sanitize_text_field( wp_unslash( $input['search'] ) );
	}
	$q     = new WP_Query( $args );
	$items = array();
	foreach ( $q->posts as $p ) {
		$items[] = wsp_blocks_item_data( $p );
	}
	return array( 'blocks' => $items, 'total' => (int) $q->found_posts, 'returned' => count( $items ), 'pages' => (int) $q->max_num_pages );
}

function wsp_execute_get_block( $input ) {
	if ( empty( $input['id'] ) ) {
		return new WP_Error( 'missing_param', 'id is required.' );
	}
	$post = wsp_blocks_guard( $input['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$data = wsp_blocks_item_data( $post, true );
	if ( ! empty( $input['parse'] ) ) {
		$data['blocks'] = wsp_blocks_simplify( parse_blocks( $post->post_content ) );
	}
	return $data;
}

function wsp_execute_create_block( $input ) {
	if ( empty( $input['title'] ) || ! isset( $input['content'] ) || '' === $input['content'] ) {
		return new WP_Error( 'missing_param', 'title and content (block markup) are required.' );
	}
	$type = get_post_type_object( 'wp_block' );
	if ( ! $type || ! current_user_can( $type->cap->create_posts ) ) {
		return new WP_Error( 'forbidden', 'You do not have permission to create blocks.' );
	}
	$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'publish';
	if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
		return new WP_Error( 'invalid_param', 'status must be publish, draft, pending or private.' );
	}
	if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
		return new WP_Error( 'forbidden', "You do not have permission to set status '" . esc_html( $status ) . "'." );
	}
	$sync = wsp_blocks_sync_status( $input );
	if ( is_wp_error( $sync ) ) {
		return $sync;
	}
	$args = array(
		'post_type'    => 'wp_block',
		'post_status'  => $status,
		'post_title'   => sanitize_text_field( wp_unslash( $input['title'] ) ),
		'post_content' => wp_kses_post( $input['content'] ),
	);
	if ( ! empty( $input['slug'] ) ) {
		$args['post_name'] = sanitize_title( $input['slug'] );
	}
	$id = wp_insert_post( wp_slash( $args ), true );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	wsp_blocks_save_sync_status( $id, $sync );
	return array( 'success' => true, 'block' => wsp_blocks_item_data( get_post( $id ), true ) );
}

function wsp_execute_update_block( $input ) {
	if ( empty( $input['id'] ) ) {
		return new WP_Error( 'missing_param', 'id is required.' );
	}
	$post = wsp_blocks_guard( $input['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$sync = wsp_blocks_sync_status( $input );
	if ( is_wp_error( $sync ) ) {
		return $sync;
	}
	$args = array( 'ID' => $post->ID );
	if ( isset( $input['title'] ) ) {
		$args['post_title'] = sanitize_text_field( wp_unslash( $input['title'] ) );
	}
	if ( isset( $input['content'] ) ) {
		$args['post_content'] = wp_kses_post( $input['content'] );
	}
	if ( isset( $input['status'] ) ) {
		$status = sanitize_key( $input['status'] );
		if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			return new WP_Error( 'invalid_param', 'status must be publish, draft, pending or private.' );
		}
		$ok = wsp_mcp_guard_post_status( $post, $status );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$args['post_status'] = $status;
	}
	if ( count( $args ) < 2 && null === $sync ) {
		return new WP_Error( 'nothing_to_update', 'Provide at least one of title, content, status, sync_status.' );
	}
	if ( count( $args ) > 1 ) {
		$result = wp_update_post( wp_slash( $args ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	wsp_blocks_save_sync_status( $post->ID, $sync );
	return array( 'success' => true, 'block' => wsp_blocks_item_data( get_post( $post->ID ), true ) );
}

function wsp_execute_delete_block( $input ) {
	if ( empty( $input['id'] ) ) {
		return new WP_Error( 'missing_param', 'id is required.' );
	}
	$post = wsp_blocks_guard( $input['id'], true );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$used  = wsp_blocks_used_in( $post->ID );
	$force = ! empty( $input['force'] );
	$res   = $force ? wp_delete_post( $post->ID, true ) : wp_trash_post( $post->ID );
	if ( ! $res ) {
		return new WP_Error( 'delete_failed', 'Could not delete block ' . esc_html( $post->ID ) . '.' );
	}
	return array(
		'success'   => true,
		'id'        => (int) $post->ID,
		'permanent' => $force,
		'used_in'   => $used,
		'warning'   => $used ? 'This block is still embedded in the posts listed in used_in; they will render nothing for it.' : null,
	);
}

/* ---------------------------------------------------------------- patterns */

function wsp_execute_list_patterns( $input ) {
	$search   = ! empty( $input['search'] ) ? strtolower( sanitize_text_field( wp_unslash( $input['search'] ) ) ) : '';
	$category = ! empty( $input['category'] ) ? sanitize_key( $input['category'] ) : '';
	$with     = ! empty( $input['include_content'] );
	$limit    = isset( $input['limit'] ) ? max( 1, min( 200, intval( $input['limit'] ) ) ) : 50;

	$out = array();
	$all = WP_Block_Patterns_Registry::get_instance()->get_all_registered();
	foreach ( $all as $p ) {
		$cats = isset( $p['categories'] ) ? (array) $p['categories'] : array();
		if ( '' !== $category && ! in_array( $category, $cats, true ) ) {
			continue;
		}
		$hay = strtolower( ( $p['title'] ?? '' ) . ' ' . ( $p['description'] ?? '' ) . ' ' . $p['name'] . ' ' . implode( ' ', (array) ( $p['keywords'] ?? array() ) ) );
		if ( '' !== $search && false === strpos( $hay, $search ) ) {
			continue;
		}
		$row = array(
			'name'        => $p['name'],
			'title'       => $p['title'] ?? '',
			'description' => $p['description'] ?? '',
			'categories'  => $cats,
			'keywords'    => array_values( (array) ( $p['keywords'] ?? array() ) ),
			'block_types' => array_values( (array) ( $p['blockTypes'] ?? array() ) ),
		);
		if ( $with ) {
			$row['content'] = $p['content'] ?? '';
		}
		$out[] = $row;
	}
	$total = count( $out );
	return array(
		'patterns'   => array_slice( $out, 0, $limit ),
		'total'      => $total,
		'returned'   => min( $total, $limit ),
		'categories' => array_values( array_map( function ( $c ) {
			return array( 'name' => $c['name'], 'label' => $c['label'] );
		}, WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered() ) ),
	);
}

/* -------------------------------------------------------------- block types */

function wsp_execute_list_block_types( $input ) {
	$search = ! empty( $input['search'] ) ? strtolower( sanitize_text_field( wp_unslash( $input['search'] ) ) ) : '';
	$ns     = ! empty( $input['namespace'] ) ? sanitize_key( $input['namespace'] ) : '';
	$with   = ! empty( $input['include_attributes'] );
	$limit  = isset( $input['limit'] ) ? max( 1, min( 500, intval( $input['limit'] ) ) ) : 200;

	$out = array();
	foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
		if ( '' !== $ns && 0 !== strpos( $name, $ns . '/' ) ) {
			continue;
		}
		$hay = strtolower( $name . ' ' . ( $type->title ?? '' ) . ' ' . ( $type->description ?? '' ) );
		if ( '' !== $search && false === strpos( $hay, $search ) ) {
			continue;
		}
		$row = array(
			'name'        => $name,
			'title'       => $type->title ?? '',
			'category'    => $type->category ?? null,
			'description' => $type->description ?? '',
			'parent'      => $type->parent ?? null,
			'is_dynamic'  => method_exists( $type, 'is_dynamic' ) ? (bool) $type->is_dynamic() : false,
		);
		if ( $with ) {
			$attrs = array();
			foreach ( (array) $type->attributes as $attr => $def ) {
				$attrs[ $attr ] = array(
					'type'    => $def['type'] ?? null,
					'default' => $def['default'] ?? null,
				);
			}
			$row['attributes'] = (object) $attrs;
		}
		$out[] = $row;
	}
	usort( $out, function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
	$total = count( $out );
	return array( 'block_types' => array_slice( $out, 0, $limit ), 'total' => $total, 'returned' => min( $total, $limit ) );
}

/* ------------------------------------------------------- per-post block tree */

function wsp_blocks_resolve_post( $input ) {
	if ( empty( $input['post_id'] ) ) {
		return new WP_Error( 'missing_param', 'post_id is required.' );
	}
	$post = wsp_mcp_guard_edit_post( $input['post_id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( in_array( $post->post_type, array( 'revision', 'attachment', 'nav_menu_item' ), true ) ) {
		return new WP_Error( 'invalid_post_type', "Post type '" . esc_html( $post->post_type ) . "' has no block content." );
	}
	return $post;
}

function wsp_execute_get_post_blocks( $input ) {
	$post = wsp_blocks_resolve_post( $input );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$parsed = parse_blocks( $post->post_content );
	return array(
		'post_id'      => (int) $post->ID,
		'post_type'    => $post->post_type,
		'title'        => $post->post_title,
		'has_blocks'   => has_blocks( $post->post_content ),
		'block_count'  => wsp_blocks_count( $parsed ),
		'blocks'       => wsp_blocks_simplify( $parsed ),
	);
}

function wsp_execute_update_post_blocks( $input ) {
	$post = wsp_blocks_resolve_post( $input );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$has_blocks  = array_key_exists( 'blocks', $input );
	$has_content = isset( $input['content'] ) && is_string( $input['content'] );
	if ( $has_blocks === $has_content ) {
		return new WP_Error( 'invalid_param', 'Provide exactly one of "blocks" (array) or "content" (block markup string).' );
	}

	if ( $has_blocks ) {
		$nodes = 0;
		$tree  = wsp_blocks_normalize( $input['blocks'], 1, $nodes );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$markup = serialize_blocks( $tree );
	} else {
		$markup = $input['content'];
	}
	$markup = wp_kses_post( $markup );

	// WordPress snapshots the previous content as a revision when the post type supports it.
	$result = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $markup ) ), true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$saved = get_post( $post->ID );
	return array(
		'success'     => true,
		'post_id'     => (int) $post->ID,
		'block_count' => wsp_blocks_count( parse_blocks( $saved->post_content ) ),
		'revisions'   => post_type_supports( $post->post_type, 'revisions' ),
		'link'        => get_permalink( $post->ID ),
	);
}
