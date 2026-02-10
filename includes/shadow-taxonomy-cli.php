<?php
namespace Shadow_Taxonomy\CLI;

use Shadow_Taxonomy\Core as Core;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

\WP_CLI::add_command( 'shadow', __NAMESPACE__ . '\Shadow_Terms' );

class Shadow_Terms extends \WP_CLI_Command {

	/**
	 * Batch size for paginated queries.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 100;

	/**
	 * Validate that the provided post type and taxonomy exist.
	 *
	 * @param string $cpt Post type slug.
	 * @param string $tax Taxonomy slug.
	 */
	private function validate_cpt_and_tax( string $cpt, string $tax ): void {
		if ( ! post_type_exists( $cpt ) ) {
			\WP_CLI::error( esc_html__( 'The Post Type you provided does not exist.' ) );
		}

		if ( ! taxonomy_exists( $tax ) ) {
			\WP_CLI::error( esc_html__( 'The Taxonomy you provided does not exist.' ) );
		}
	}

	/**
	 * Query all posts missing the shadow term meta key, paginated.
	 *
	 * @param string $cpt Post type slug.
	 * @param string $tax Taxonomy slug.
	 *
	 * @return \WP_Post[] Array of post objects.
	 */
	private function get_posts_missing_shadow_meta( string $cpt, string $tax ): array {
		$all_posts = [];
		$page = 1;

		do {
			$query = new \WP_Query( [
				'post_type'      => $cpt,
				'post_status'    => 'publish',
				'posts_per_page' => self::BATCH_SIZE,
				'paged'          => $page,
				'meta_query'     => [
					[
						'key'     => Core\get_meta_key( $tax, 'term_id' ),
						'compare' => 'NOT EXISTS',
					],
				],
			] );

			if ( is_wp_error( $query ) ) {
				\WP_CLI::error( esc_html__( 'An error occurred while searching for posts.' ) );
			}

			if ( ! empty( $query->posts ) ) {
				$all_posts = array_merge( $all_posts, $query->posts );
			}

			$page++;
		} while ( $page <= $query->max_num_pages );

		return $all_posts;
	}

	/**
	 * Get all taxonomy terms for the given taxonomy.
	 *
	 * @param string $tax Taxonomy slug.
	 *
	 * @return \WP_Term[] Array of term objects.
	 */
	private function get_all_terms( string $tax ): array {
		$terms = get_terms( [
			'taxonomy'   => $tax,
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		return $terms;
	}

	/**
	 * Create a shadow term for the given post and update meta on both sides.
	 *
	 * @param \WP_Post $post    The post object.
	 * @param string   $tax     Taxonomy slug.
	 * @param bool     $verbose Whether to output verbose logs.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function create_shadow_term_for_post( $post, string $tax, bool $verbose = false ): bool {
		$new_term = wp_insert_term( $post->post_title, $tax, [ 'slug' => $post->post_name ] );

		if ( is_wp_error( $new_term ) ) {
			\WP_CLI::warning( sprintf( 'Failed to create term for "%s": %s', esc_html( $post->post_title ), $new_term->get_error_message() ) );
			return false;
		}

		if ( $verbose ) {
			\WP_CLI::log( sprintf( 'Created Term: %s', esc_html( $post->post_title ) ) );
		}

		update_term_meta( $new_term['term_id'], Core\get_meta_key( $tax, 'post_id' ), $post->ID );
		update_post_meta( $post->ID, Core\get_meta_key( $tax, 'term_id' ), $new_term['term_id'] );

		return true;
	}

	/**
	 * Delete a shadow term.
	 *
	 * @param \WP_Term $term    The term object.
	 * @param string   $tax     Taxonomy slug.
	 * @param bool     $verbose Whether to output verbose logs.
	 */
	private function delete_orphan_term( $term, string $tax, bool $verbose = false ): void {
		if ( $verbose ) {
			\WP_CLI::log( sprintf( 'Deleting Orphan Term: %s', esc_html( $term->name ) ) );
		}

		wp_delete_term( $term->term_id, $tax );
	}

	/**
	 * Output a dry-run summary table.
	 *
	 * @param array $items Array of [ 'action' => string, 'count' => int ] items.
	 */
	private function output_dry_run_table( array $items ): void {
		\WP_CLI::warning( esc_html__( 'View the below table to see how many terms will be created or deleted.' ) );
		\WP_CLI\Utils\format_items( 'table', $items, [ 'action', 'count' ] );
	}

	/**
	 * Command will loop through all items in the provided post type and sync them to a shadow term.
	 * Function will also loop through all taxonomy terms and remove any orphan terms. Once this
	 * function is complete your taxonomy relations will be 100% in sync.
	 *
	 * ## OPTIONS
	 *
	 * --cpt=<post_type_name>
	 * : The custom post type to sync.
	 *
	 * --tax=<taxonomy_name>
	 * : The Shadow taxonomy name for the above post type.
	 *
	 * [--verbose]
	 * : Prints rows to the console as they're updated.
	 *
	 * [--dry-run]
	 * : Allows you to see the number of shadow terms which need to be created or deleted.
	 *
	 * @subcommand sync
	 */
	public function sync_shadow_terms( $args, $assoc_args ) {
		$tax     = $assoc_args['tax'];
		$cpt     = $assoc_args['cpt'];
		$verbose = isset( $assoc_args['verbose'] );
		$dry_run = isset( $assoc_args['dry-run'] );

		$this->validate_cpt_and_tax( $cpt, $tax );

		/**
		 * Check for missing Shadow Taxonomy Terms.
		 */
		$posts = $this->get_posts_missing_shadow_meta( $cpt, $tax );

		$terms_to_create = array_filter( $posts, function( $post ) use ( $tax ) {
			return empty( Core\get_associated_term( $post->ID, $tax ) );
		} );

		/**
		 * Check for orphan shadow terms which are no longer needed.
		 */
		$all_terms = $this->get_all_terms( $tax );

		$terms_to_delete = array_filter( $all_terms, function( $term ) use ( $cpt ) {
			return empty( Core\get_associated_post( $term, $cpt ) );
		} );

		$count = count( $terms_to_create ) + count( $terms_to_delete );

		/**
		 * Output When Running a dry-run.
		 */
		if ( $dry_run ) {
			$this->output_dry_run_table( [
				[ 'action' => 'Create', 'count' => count( $terms_to_create ) ],
				[ 'action' => 'Delete', 'count' => count( $terms_to_delete ) ],
			] );
			return;
		}

		if ( 0 === $count ) {
			\WP_CLI::success( esc_html__( 'Shadow Taxonomy is in sync, no action needed.' ) );
			return;
		}

		/**
		 * Process Shadow Taxonomy Additions and Deletions.
		 */
		\WP_CLI::log( sprintf( 'Processing %d items...', absint( $count ) ) );

		foreach ( $terms_to_create as $post ) {
			$this->create_shadow_term_for_post( $post, $tax, $verbose );
		}

		foreach ( $terms_to_delete as $term ) {
			$this->delete_orphan_term( $term, $tax, $verbose );
		}

		\WP_CLI::success( sprintf( 'Process Complete. Successfully synced %d posts and terms.', absint( $count ) ) );
	}

	/**
	 * Command will check if the input post or taxonomy has a valid associated shadow object. Function
	 * does not fix any issues, it simply tells you the status of the association.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : The type of data to check. Possible arguments are post_type or taxonomy.
	 *
	 * --id=<int>
	 * : The ID of the post type or taxonomy term to validate.
	 *
	 * --tax=<taxonomy_name>
	 * : The taxonomy name for the shadow relationship.
	 *
	 * @subcommand check
	 */
	public function check_sync( $args, $assoc_args ) {

		if ( ! isset( $assoc_args['tax'] ) || ! taxonomy_exists( $assoc_args['tax'] ) ) {
			\WP_CLI::error( esc_html__( 'Please provide a valid taxonomy using --tax.' ) );
		}

		if ( 'post_type' === $args[0] ) {
			$post = get_post( $assoc_args['id'] );

			if ( empty( $post ) ) {
				\WP_CLI::error( sprintf( 'Post with ID %d not found.', absint( $assoc_args['id'] ) ) );
			}

			$term_id = Core\get_associated_term_id( $post, $assoc_args['tax'] );

			if ( empty( $term_id ) ) {
				\WP_CLI::error( sprintf( 'Associated Shadow %s not found.', $args[0] ) );
			}

			\WP_CLI::success( 'Shadow Taxonomy is in Sync.' );
			return;
		}

		if ( 'taxonomy' === $args[0] ) {
			$term = get_term_by( 'id', $assoc_args['id'], $assoc_args['tax'] );

			if ( empty( $term ) ) {
				\WP_CLI::error( sprintf( 'Term with ID %d not found.', absint( $assoc_args['id'] ) ) );
			}

			$post_id = Core\get_associated_post_id( $term );

			if ( empty( $post_id ) ) {
				\WP_CLI::error( sprintf( 'Associated Shadow %s not found.', $args[0] ) );
			}

			\WP_CLI::success( 'Shadow Taxonomy is in Sync.' );
			return;
		}

		\WP_CLI::error( 'Type should be either post_type or taxonomy.' );
	}

	/**
	 * Command will sync shadow terms and also repair any missing metadata on both
	 * the post and term side of the relationship.
	 *
	 * ## OPTIONS
	 *
	 * --cpt=<post_type_name>
	 * : The custom post type to sync.
	 *
	 * --tax=<taxonomy_name>
	 * : The Shadow taxonomy name for the above post type.
	 *
	 * [--verbose]
	 * : Prints rows to the console as they're updated.
	 *
	 * [--dry-run]
	 * : Allows you to see the number of shadow terms which need to be created or deleted.
	 *
	 * @subcommand sync-terms
	 */
	public function migrate_shadow_terms( $args, $assoc_args ) {
		$tax     = $assoc_args['tax'];
		$cpt     = $assoc_args['cpt'];
		$verbose = isset( $assoc_args['verbose'] );
		$dry_run = isset( $assoc_args['dry-run'] );

		$this->validate_cpt_and_tax( $cpt, $tax );

		/**
		 * Check for missing Shadow Taxonomy Terms.
		 */
		$posts = $this->get_posts_missing_shadow_meta( $cpt, $tax );

		$terms_to_create      = [];
		$posts_missing_metadata = [];

		foreach ( $posts as $post ) {
			$object_terms = wp_get_object_terms( $post->ID, $tax );

			if ( empty( $object_terms ) ) {
				$terms_to_create[] = $post;
			} else {
				$post_meta = get_post_meta( $post->ID, Core\get_meta_key( $tax, 'term_id' ), true );

				if ( empty( $post_meta ) ) {
					$posts_missing_metadata[] = [
						'term_id' => $object_terms[0]->term_id,
						'post_id' => $post->ID,
					];
				}
			}
		}

		/**
		 * Check for orphan shadow terms which are no longer needed.
		 */
		$all_terms = $this->get_all_terms( $tax );

		$terms_to_delete        = [];
		$terms_missing_metadata = [];

		foreach ( $all_terms as $term ) {
			$term_query = new \WP_Query( [
				'post_type'      => $cpt,
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'tax_query'      => [
					[
						'taxonomy' => $tax,
						'field'    => 'id',
						'terms'    => $term->term_id,
					],
				],
				'no_found_rows' => true,
			] );

			if ( empty( $term_query->posts ) || is_wp_error( $term_query ) ) {
				$terms_to_delete[] = $term;
			} else {
				$term_meta = get_term_meta( $term->term_id, Core\get_meta_key( $tax, 'post_id' ), true );

				if ( empty( $term_meta ) ) {
					$terms_missing_metadata[] = [
						'term_id' => $term->term_id,
						'post_id' => $term_query->posts[0]->ID,
					];
				}
			}
		}

		/**
		 * Output When Running a dry-run.
		 */
		if ( $dry_run ) {
			$this->output_dry_run_table( [
				[ 'action' => 'Create', 'count' => count( $terms_to_create ) ],
				[ 'action' => 'Delete', 'count' => count( $terms_to_delete ) ],
				[ 'action' => 'Missing Term Meta', 'count' => count( $terms_missing_metadata ) ],
				[ 'action' => 'Missing Post Meta', 'count' => count( $posts_missing_metadata ) ],
			] );
			return;
		}

		if ( empty( $terms_to_create ) &&
			empty( $terms_to_delete ) &&
			empty( $terms_missing_metadata ) &&
			empty( $posts_missing_metadata ) ) {
			\WP_CLI::success( esc_html__( 'Shadow Taxonomy is in sync, no action needed.' ) );
			return;
		}

		$count = count( $terms_to_create ) + count( $terms_to_delete ) + count( $terms_missing_metadata ) + count( $posts_missing_metadata );

		\WP_CLI::log( sprintf( 'Processing %d items...', absint( $count ) ) );

		/**
		 * Create Shadow Terms.
		 */
		foreach ( $terms_to_create as $post ) {
			$this->create_shadow_term_for_post( $post, $tax, $verbose );
		}

		/**
		 * Delete Orphan Shadow Terms.
		 */
		foreach ( $terms_to_delete as $term ) {
			$this->delete_orphan_term( $term, $tax, $verbose );
		}

		/**
		 * Repair missing term metadata.
		 */
		foreach ( $terms_missing_metadata as $meta ) {
			update_term_meta( $meta['term_id'], Core\get_meta_key( $tax, 'post_id' ), $meta['post_id'] );

			if ( $verbose ) {
				\WP_CLI::log( sprintf( 'Repaired term meta for term ID: %d', absint( $meta['term_id'] ) ) );
			}
		}

		/**
		 * Repair missing post metadata.
		 */
		foreach ( $posts_missing_metadata as $meta ) {
			update_post_meta( $meta['post_id'], Core\get_meta_key( $tax, 'term_id' ), $meta['term_id'] );

			if ( $verbose ) {
				\WP_CLI::log( sprintf( 'Repaired post meta for post ID: %d', absint( $meta['post_id'] ) ) );
			}
		}

		\WP_CLI::success( sprintf( 'Process Complete. Successfully synced %d posts and terms.', absint( $count ) ) );
	}

	/**
	 * Command performs a deep sync that creates shadow terms for posts that are missing
	 * both the shadow meta key and a matching taxonomy term by slug.
	 *
	 * ## OPTIONS
	 *
	 * --cpt=<post_type_name>
	 * : The custom post type to sync.
	 *
	 * --tax=<taxonomy_name>
	 * : The Shadow taxonomy name for the above post type.
	 *
	 * [--verbose]
	 * : Prints rows to the console as they're updated.
	 *
	 * [--dry-run]
	 * : Allows you to see the number of shadow terms which need to be created or deleted.
	 *
	 * @subcommand deep-sync
	 */
	public function deep_sync( $args, $assoc_args ) {
		$tax     = $assoc_args['tax'];
		$cpt     = $assoc_args['cpt'];
		$verbose = isset( $assoc_args['verbose'] );
		$dry_run = isset( $assoc_args['dry-run'] );

		$this->validate_cpt_and_tax( $cpt, $tax );

		/**
		 * Check for missing Shadow Taxonomy Terms.
		 */
		$posts = $this->get_posts_missing_shadow_meta( $cpt, $tax );

		$terms_to_create = array_filter( $posts, function( $post ) use ( $tax ) {
			$shadow_term = get_post_meta( $post->ID, Core\get_meta_key( $tax, 'term_id' ), true );

			if ( ! empty( $shadow_term ) ) {
				return false;
			}

			$term = get_term_by( 'slug', $post->post_name, $tax );

			return empty( $term );
		} );

		$count = count( $terms_to_create );

		/**
		 * Output When Running a dry-run.
		 */
		if ( $dry_run ) {
			$this->output_dry_run_table( [
				[ 'action' => 'Create', 'count' => $count ],
			] );
			return;
		}

		if ( 0 === $count ) {
			\WP_CLI::success( esc_html__( 'Shadow Taxonomy is in sync, no action needed.' ) );
			return;
		}

		/**
		 * Process Shadow Taxonomy Additions.
		 */
		\WP_CLI::log( sprintf( 'Processing %d posts...', absint( $count ) ) );

		foreach ( $terms_to_create as $post ) {
			$this->create_shadow_term_for_post( $post, $tax, $verbose );
		}

		\WP_CLI::success( sprintf( 'Process Complete. Successfully synced %d posts and terms.', absint( $count ) ) );
	}

}
