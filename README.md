# Shadow Taxonomy

A WordPress Composer library for creating relationships between custom post types using shadow taxonomies.

## Introduction

One of the hardest things to do in WordPress is creating relationships between two different post types. Often this is accomplished by saving relationship data in post meta. However this leads to expensive meta queries, which are generally one of the poorest performing queries you can make in WordPress.

Metadata can also be a pain to keep synced. For example, when posts are deleted, what happens to the post meta saved on a separate post type?

Shadow Taxonomy solves this by using WordPress taxonomies as the relationship layer. Instead of meta queries, you get performant taxonomy queries and a built-in checkbox UI on the post edit screen for free.

## What is a Shadow Taxonomy?

A shadow taxonomy is a custom WordPress taxonomy that automatically mirrors a specific post type. Anytime a post in that post type is created, updated, or deleted, the associated shadow taxonomy term is also created, updated, and deleted.

This library manages the entire lifecycle of the shadow terms, keeping your taxonomy in sync with its associated post type.

## Installation

```
composer require spock/shadow-taxonomies
```

**Requirements:** PHP >= 7.2, WordPress

## Usage

### Step One: Create the Shadow Taxonomy

```php
add_action( 'init', function() {
	register_taxonomy(
		'services-tax',
		'staff-cpt',
		array(
			'label'         => __( 'Services', 'text-domain' ),
			'rewrite'       => false,
			'show_tagcloud' => false,
			'hierarchical'  => true,
		)
	);
	// We will make our connection here in the next step.
});
```

Here we are creating a normal custom taxonomy. In this example we are creating a taxonomy to mirror a CPT called Services, so by convention the shadow taxonomy is named `services-tax`.

Because we want to link Services to another post type called Staff, this taxonomy is registered on the Staff CPT post edit screen.

The taxonomy is not made `public` so that nobody manually edits the terms. The library handles creating, updating, and deleting the shadow taxonomy terms to keep everything in sync.

### Step Two: Create the Association

```php
\Shadow_Taxonomy\Core\create_relationship( 'service-cpt', 'service-tax' );
```

This one line creates the shadow taxonomy link. The first argument is the custom post type slug, and the second is the shadow taxonomy slug. Place this immediately after the `register_taxonomy` call.

### Combined Helper

Use `register_shadow_taxonomy` to register the taxonomy and establish the relationship in a single call:

```php
\Shadow_Taxonomy\Core\register_shadow_taxonomy(
	[ 'movies' ],
	[ 'actor' ],
	'_actor',
	[
		'label'         => 'Actors',
		'rewrite'       => false,
		'show_tagcloud' => false,
		'show_ui'       => false,
		'hierarchical'  => false,
		'show_in_menu'  => false,
		'meta_box_cb'   => false,
		'show_in_rest'  => true,
	]
);
```

## API

### get_the_posts

```php
\Shadow_Taxonomy\Core\get_the_posts( $post_id, $taxonomy, $cpt )
```

Fetch the associated posts for a given post ID. Returns an array of `WP_Post` objects or `false` if none are found.

- `$post_id` *(int)* **required** - The ID of the post whose associations you want to find.
- `$taxonomy` *(string)* **required** - The shadow taxonomy slug.
- `$cpt` *(string)* **required** - The associated custom post type slug.

### get_associated_term

```php
\Shadow_Taxonomy\Core\get_associated_term( $post, $taxonomy )
```

Get the shadow term for a given post. Accepts a `WP_Post` object or post ID. Returns a `WP_Term` object or `false`.

### get_associated_post

```php
\Shadow_Taxonomy\Core\get_associated_post( $term, $post_type )
```

Get the shadow post for a given term. Returns a `WP_Post` object or `false`.

### get_meta_key

```php
\Shadow_Taxonomy\Core\get_meta_key( $taxonomy, $type )
```

Build the meta key used to store shadow relationships. `$type` is either `'term_id'` or `'post_id'`.

## Hooks

The library fires the following actions:

- `shadow_taxonomy_term_created` - Fires after a shadow term is created. Parameters: `$new_term`, `$post_id`, `$taxonomy`.
- `shadow_taxonomy_term_updated` - Fires after a shadow term is updated. Parameters: `$term`, `$associated_post`, `$taxonomy`.
- `shadow_taxonomy_term_deleted` - Fires after a shadow term is deleted. Parameters: `$term`, `$post_id`, `$taxonomy`.

## WP-CLI Commands

The library includes WP-CLI commands for managing shadow taxonomies on existing sites with existing data.

### sync

```bash
wp shadow sync --cpt=<post_type> --tax=<taxonomy> [--dry-run] [--verbose]
```

Syncs all posts in the given post type to shadow terms, and removes any orphan terms.

### sync-terms

```bash
wp shadow sync-terms --cpt=<post_type> --tax=<taxonomy> [--dry-run] [--verbose]
```

Like `sync`, but also repairs missing metadata on both the post and term side.

### deep-sync

```bash
wp shadow deep-sync --cpt=<post_type> --tax=<taxonomy> [--dry-run] [--verbose]
```

Creates shadow terms for posts missing both the shadow meta key and a matching term by slug.

### check

```bash
wp shadow check <post_type|taxonomy> --id=<int> --tax=<taxonomy>
```

Checks if a specific post or term has a valid shadow association.

### Options

- `--cpt` *(string)* **required** - The post type to shadow.
- `--tax` *(string)* **required** - The taxonomy to use as the shadow.
- `--dry-run` *(flag)* **optional** - Lists changes without making them.
- `--verbose` *(flag)* **optional** - Outputs additional logging during processing.

## License

GPL-2.0+
