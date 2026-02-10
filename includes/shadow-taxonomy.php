<?php
namespace Shadow_Taxonomy\Core;

/**
 * Build the meta key used to store the shadow relationship.
 *
 * @param string $taxonomy Taxonomy slug.
 * @param string $type     Either 'term_id' or 'post_id'.
 *
 * @return string Sanitized meta key.
 */
function get_meta_key( string $taxonomy, string $type = 'term_id' ): string {
	return sanitize_key( "shadow_{$taxonomy}_{$type}" );
}

/**
 * Register a post-to-taxonomy shadow relationship. This function hooks into the
 * WordPress Plugins API and registers multiple hooks. These hooks ensure that any
 * changes made on the post type side or taxonomy side of a given relationship will
 * stay in sync.
 *
 * @param string $post_type Post Type slug.
 * @param string $taxonomy  Taxonomy slug.
 */
function create_relationship( string $post_type, string $taxonomy ): void {
	add_action( 'wp_insert_post', create_shadow_term( $post_type, $taxonomy ) );
	add_action( 'before_delete_post', delete_shadow_term( $taxonomy ) );
}

/**
 * Create a closure for the wp_insert_post hook, which handles creating or
 * updating an associated taxonomy term.
 *
 * @param string $post_type Post Type slug.
 * @param string $taxonomy  Taxonomy slug.
 *
 * @return \Closure
 */
function create_shadow_term( string $post_type, string $taxonomy ): \Closure {
	return function( $post_id ) use ( $post_type, $taxonomy ) {
		$term = get_associated_term( $post_id, $taxonomy );
		$post = get_post( $post_id );

		if ( empty( $post ) || $post->post_type !== $post_type ) {
			return false;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return false;
		}

		if ( ! $term ) {
			create_shadow_taxonomy_term( $post_id, $post, $taxonomy );
		} else {
			$associated_post = get_associated_post( $term, $post_type );

			if ( empty( $associated_post ) ) {
				return false;
			}

			if ( post_type_already_in_sync( $term, $associated_post ) ) {
				return false;
			}

			wp_update_term(
				$term->term_id,
				$taxonomy,
				[
					'name' => $associated_post->post_title,
					'slug' => $associated_post->post_name,
				]
			);

			/**
			 * Fires after a shadow taxonomy term has been updated.
			 *
			 * @param \WP_Term $term            The updated term object.
			 * @param \WP_Post $associated_post  The associated post object.
			 * @param string   $taxonomy         The taxonomy slug.
			 */
			do_action( 'shadow_taxonomy_term_updated', $term, $associated_post, $taxonomy );
		}
	};
}

/**
 * Create a closure for the before_delete_post hook, which handles deleting an
 * associated taxonomy term.
 *
 * @param string $taxonomy Taxonomy slug.
 *
 * @return \Closure
 */
function delete_shadow_term( string $taxonomy ): \Closure {
	return function( $post_id ) use ( $taxonomy ) {
		$term = get_associated_term( $post_id, $taxonomy );

		if ( ! $term ) {
			return false;
		}

		wp_delete_term( $term->term_id, $taxonomy );

		/**
		 * Fires after a shadow taxonomy term has been deleted.
		 *
		 * @param \WP_Term $term     The deleted term object.
		 * @param int      $post_id  The post ID that triggered the deletion.
		 * @param string   $taxonomy The taxonomy slug.
		 */
		do_action( 'shadow_taxonomy_term_deleted', $term, $post_id, $taxonomy );
	};
}

/**
 * Create the shadow term and set the term/post meta to establish the association.
 *
 * @param int      $post_id  Post ID.
 * @param \WP_Post $post     The WP Post object.
 * @param string   $taxonomy Taxonomy slug.
 *
 * @return array|false Term array on success, false on error.
 */
function create_shadow_taxonomy_term( int $post_id, $post, string $taxonomy ) {
	$new_term = wp_insert_term( $post->post_title, $taxonomy, [ 'slug' => $post->post_name ] );

	if ( is_wp_error( $new_term ) ) {
		return false;
	}

	update_term_meta( $new_term['term_id'], get_meta_key( $taxonomy, 'post_id' ), $post_id );
	update_post_meta( $post_id, get_meta_key( $taxonomy, 'term_id' ), $new_term['term_id'] );

	/**
	 * Fires after a shadow taxonomy term has been created.
	 *
	 * @param array  $new_term The newly created term data array.
	 * @param int    $post_id  The post ID.
	 * @param string $taxonomy The taxonomy slug.
	 */
	do_action( 'shadow_taxonomy_term_created', $new_term, $post_id, $taxonomy );

	return $new_term;
}

/**
 * Check if the current term and its associated post have the same title and slug.
 * While we generally rely on term and post meta to track association, it's important
 * that these two values stay synced.
 *
 * @param \WP_Term $term The WP Term object.
 * @param \WP_Post $post The WP Post object.
 *
 * @return bool True if in sync, false otherwise.
 */
function post_type_already_in_sync( $term, $post ): bool {
	if ( isset( $term->slug ) && isset( $post->post_name ) ) {
		return $term->name === $post->post_title && $term->slug === $post->post_name;
	}

	return $term->name === $post->post_title;
}

/**
 * Get the associated shadow post ID from a given term object.
 *
 * @param \WP_Term $term WP Term object.
 *
 * @return mixed The post_id or empty string if no associated post is found.
 */
function get_associated_post_id( $term ) {
	return get_term_meta( $term->term_id, get_meta_key( $term->taxonomy, 'post_id' ), true );
}

/**
 * Find the shadow or associated post for the input taxonomy term.
 *
 * @param \WP_Term $term      WP Term object.
 * @param string   $post_type Post Type slug.
 *
 * @return \WP_Post|false The associated post object, or false if not found.
 */
function get_associated_post( $term, string $post_type ) {
	if ( empty( $term ) ) {
		return false;
	}

	$post_id = get_associated_post_id( $term );

	if ( empty( $post_id ) ) {
		return false;
	}

	return get_post( $post_id );
}

/**
 * Get the associated shadow term ID from a given post object.
 *
 * @param \WP_Post $post     WP Post object.
 * @param string   $taxonomy Taxonomy slug.
 *
 * @return mixed The term_id or empty string if no associated term was found.
 */
function get_associated_term_id( $post, string $taxonomy ) {
	return get_post_meta( $post->ID, get_meta_key( $taxonomy, 'term_id' ), true );
}

/**
 * Get the associated Term object for a given Post object or Post ID.
 *
 * @param \WP_Post|int $post     WP Post object or Post ID.
 * @param string       $taxonomy Taxonomy slug.
 *
 * @return \WP_Term|false The associated term object, or false if not found.
 */
function get_associated_term( $post, string $taxonomy ) {
	if ( is_numeric( $post ) ) {
		$post = get_post( $post );
	}

	if ( empty( $post ) ) {
		return false;
	}

	$term_id = get_associated_term_id( $post, $taxonomy );

	return get_term_by( 'id', $term_id, $taxonomy );
}

/**
 * Get all related posts for a given post ID. Converts all attached shadow term
 * relations into the actual associated post objects.
 *
 * @param int    $post_id  The ID of the post.
 * @param string $taxonomy The name of the shadow taxonomy.
 * @param string $cpt      The name of the associated post type.
 *
 * @return \WP_Post[]|false Array of post objects, or false if none are found.
 */
function get_the_posts( int $post_id, string $taxonomy, string $cpt ) {
	$terms = get_the_terms( $post_id, $taxonomy );

	if ( ! empty( $terms ) ) {
		$posts = array_filter( array_map( function( $term ) use ( $cpt ) {
			return get_associated_post( $term, $cpt );
		}, $terms ) );

		return ! empty( $posts ) ? array_values( $posts ) : false;
	}

	return false;
}

/**
 * Helper function to register a shadow taxonomy and establish the relationship.
 *
 * @param array  $from_post_types Post types to register the taxonomy on (where checkboxes appear).
 * @param array  $to_post_types   Post types that shadow terms will be created from.
 * @param string $taxonomy        The taxonomy slug to use for the registered connection.
 * @param array  $taxonomy_args   Arguments to use for the registration of the shadow taxonomy.
 */
function register_shadow_taxonomy( array $from_post_types, array $to_post_types, string $taxonomy, array $taxonomy_args ): void {
	register_taxonomy(
		$taxonomy,
		$from_post_types,
		$taxonomy_args
	);

	foreach ( $to_post_types as $post_type ) {
		create_relationship( $post_type, $taxonomy );
	}
}
