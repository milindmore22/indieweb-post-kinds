<?php

/**
 * Post Kind Taxonomy Class
 *
 * Registers the taxonomy and sets its behavior.
 *
 * @package Post Kinds
 */
final class Kind_Taxonomy {
	private static $kinds = array(); // Store a Post_Kind class which is a definition of a specific kind

	public static function init() {

		require_once plugin_dir_path( __FILE__ ) . '/register-kinds.php';

		// Add the Correct Archive Title to Kind Archives.
		add_filter( 'get_the_archive_title', array( static::class, 'kind_archive_title' ), 10 );
		add_filter( 'get_the_archive_description', array( static::class, 'kind_archive_description' ), 10 );
		add_filter( 'document_title_parts', array( static::class, 'document_title_parts' ), 10 );

		// Add Kind Permalinks.
		add_filter( 'post_link', array( static::class, 'kind_permalink' ), 10, 3 );
		add_filter( 'post_type_link', array( static::class, 'kind_permalink' ), 10, 3 );

		// Query Variable to Exclude Kinds from Feed
		add_filter( 'query_vars', array( static::class, 'query_vars' ) );
		add_action( 'pre_get_posts', array( static::class, 'kind_filter_query' ) );
		add_action( 'pre_get_posts', array( static::class, 'kind_photo_filter' ) );
		add_action( 'pre_get_posts', array( static::class, 'kind_onthisday_filter' ) );
		add_action( 'pre_get_posts', array( static::class, 'kind_alias_filter' ) );

		// Add Dropdown
		add_action( 'restrict_manage_posts', array( static::class, 'kind_dropdown' ), 10, 2 );

		// Add Links to Ping to the Webmention Sender.
		add_filter( 'webmention_links', array( static::class, 'webmention_links' ), 11, 2 );

		// Add Links to Enclosures if Appropriate
		add_filter( 'enclosure_links', array( static::class, 'enclosure_links' ), 11, 2 );

		// Add Classes to Post.
		add_filter( 'post_class', array( static::class, 'post_class' ) );

		// Trigger Webmention on Change in Post Status.
		add_filter( 'transition_post_status', array( static::class, 'transition' ), 10, 3 );
		// On Post Save Set Post Format
		add_action( 'save_post', array( static::class, 'post_formats' ), 99, 3 );

		// Create hook triggered by change of kind
		add_action( 'set_object_terms', array( static::class, 'set_object_terms' ), 10, 6 );

		add_filter( 'single_post_title', array( static::class, 'single_post_title' ), 9, 2 );
		add_filter( 'the_title', array( static::class, 'the_title' ), 9, 2 );
		add_filter( 'get_sample_permalink', array( static::class, 'get_sample_permalink' ), 12, 5 );

		add_action( 'rest_api_init', array( static::class, 'rest_kind' ) );

		add_filter( 'embed_template_hierarchy', array( static::class, 'embed_template_hierarchy' ) );
		add_filter( 'template_include', array( static::class, 'template_include' ) );

		add_action( 'rest_api_init', array( static::class, 'register_routes' ) );

	}

	/** Template Redirect
	 *
	 */
	public static function template_include( $template ) {
		global $wp_query;

		// Only use this template for archive pages
		if ( is_singular() ) {
			return $template;
		}
		// if this is not a request for 'kind_photos'.
		if ( ! isset( $wp_query->query_vars['kind_photos'] ) ) {
				  return $template;
		}
		$photo_template = locate_template( 'kind-photos.php' );
		if ( '' !== $photo_template ) {
			return $photo_template;
		}
		return $template;
	}

	/**
	 * Register the Route.
	 */
	public static function register_routes() {
		register_rest_route(
			'post-kinds/1.0',
			'/fields',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( static::class, 'read' ),
					'args'                => array(
						'kind' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
					'permission_callback' => function () {
						return current_user_can( 'read' );
					},
				),
			)
		);
	}

	public static function read( $request ) {
		$kind = $request->get_param( 'kind' );
		return self::get_kind_info( $kind, 'all' );
	}


	/**
	 * Add our query variables to query_vars list.
	 *
	 * @access public
	 *
	 * @param array $qvars Current query_vars.
	 * @return array
	 */
	public static function query_vars( $qvars ) {
		$qvars[] = 'exclude';
		$qvars[] = 'exclude_terms';
		$qvars[] = 'kind_photos';
		$qvars[] = 'onthisday';
		return $qvars;
	}

	/**
	 * Filter the query for our post kinds.
	 *
	 * @access public
	 *
	 * @param $query
	 */
	public static function kind_photo_filter( $query ) {
		// check if the user is requesting an admin page
		if ( is_admin() ) {
			return;
		}
		$photos = get_query_var( 'kind_photos' );
		// Return if  not set
		if ( $photos ) {
			$query->set(
				'meta_query',
				array(
					'relation' => 'OR',
					array(
						'key'     => '_content_img_ids',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'mf2_photo',
						'compare' => 'EXISTS',
					),
				)
			);
			$query->set( 'posts_per_page', 30 );

		}
		return $query;
	}

	/**
	 * Filter the query for onthisday.
	 *
	 * @access public
	 *
	 * @param $query
	 */
	public static function kind_onthisday_filter( $query ) {
		// check if the user is requesting an admin page
		if ( is_admin() ) {
			return;
		}
		$onthisday = get_query_var( 'onthisday' );
		// Return if  not set
		if ( $onthisday ) {
			$now = new DateTime();
			$query->set(
				'date_query',
				array(
					'month' => $now->format( 'm' ),
					'day'   => $now->format( 'd' ),
				)
			);
		}
		return $query;
	}

	/**
	 * Filter the query for our post kinds.
	 *
	 * @access public
	 *
	 * @param $query
	 */
	public static function kind_filter_query( $query ) {
		// check if the user is requesting an admin page
		if ( is_admin() ) {
			return;
		}
		$exclude = get_query_var( 'exclude' );
		// Return if both are not set
		if ( ! taxonomy_exists( $exclude ) ) {
			return $query;
		}
		$filter = get_query_var( 'exclude_terms' );
		if ( empty( $filter ) ) {
			return $query;
		}
		$filter   = explode( ',', $filter );
		$operator = 'NOT IN';
		$query->set(
			'tax_query',
			array(
				array(
					'taxonomy' => $exclude,
					'field'    => 'slug',
					'terms'    => $filter,
					'operator' => $operator,
				),
			)
		);
		return $query;
	}

	/**
	 * Allows for some pre-defined aliases
	 *
	 * @access public
	 *
	 * @param $query
	 */
	public static function kind_alias_filter( $query ) {
		if ( empty( get_query_var( 'kind' ) ) ) {
			return $query;
		}

		$kind = get_query_var( 'kind' );
		if ( 'food' === $kind ) {
			$query->set( 'kind', 'eat,drink' );
		}
		if ( 'reaction' === $kind ) {
			$query->set( 'kind', 'bookmark,repost,like,favorite' );
		}
		if ( 'media' === $kind ) {
			$query->set( 'kind', 'watch,read,listen,play' );
		}
		return $query;
	}

	/**
	 * Register our REST API endpoint for post kinds.
	 *
	 * @access public
	 */
	public static function rest_kind() {
		register_rest_field(
			'post',
			'kind',
			array(
				'get_callback'    => array( static::class, 'get_post_kind_slug' ),
				'update_callback' => array( static::class, 'set_rest_post_kind' ),
				'schema'          => array(
					'kind' => __( 'Post Kind', 'indieweb-post-kinds' ),
					'type' => 'string',
				),
			)
		);
	}

	/**
	 * Filter template hierarchy to include our template files.
	 *
	 * @access public
	 *
	 * @param array $templates Array of template file names.
	 * @return mixed
	 */
	public static function embed_template_hierarchy( $templates ) {
		$object = get_queried_object();
		if ( ! empty( $object->post_type ) ) {
			$post_kind = get_post_kind( $object );
			if ( $post_kind ) {
				array_unshift( $templates, "embed-{$object->post_type}-{$post_kind}.php" );
			}
		}
		return $templates;
	}

	/**
	 * Generate a sample permalink for a post.
	 *
	 * @access public
	 *
	 * @param string  $permalink Current permalink.
	 * @param int     $post_id   Post ID.
	 * @param string  $title     Current post title.
	 * @param string  $name      Current post name.
	 * @param WP_Post $post      Post object.
	 * @return mixed
	 */
	public static function get_sample_permalink( $permalink, $post_id, $title, $name, $post ) {
		if ( 'publish' === $post->post_status || ! empty( $post->post_title ) ) {
			return $permalink;
		}
		// Only kick in if the permalink would be the post_id otherwise.
		if ( is_numeric( $permalink[1] ) && $post_id === (int) $permalink[1] ) {
			$excerpt = self::get_excerpt( $post );
			$excerpt = sanitize_title( mb_strimwidth( wp_strip_all_tags( $excerpt ), 0, 40 ) ); // phpcs:ignore
			if ( ! empty( $excerpt ) ) {
				$permalink[1] = wp_unique_post_slug( $excerpt, $post_id, $post->post_status, $post->post_type, $post->post_parent );
			}
		}
		return $permalink;
	}

	/**
	 * Generate a post title.
	 *
	 * @access public
	 *
	 * @param int!WP_Post    $post Post ID or Post Object.
	 * @return string
	 */
	public static function generate_title( $post, $length = 40 ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$kind    = get_post_kind_slug( $post );
		$excerpt = wp_strip_all_tags( self::get_excerpt( $post ) );
		if ( ! in_array( $kind, array( 'note', 'article' ), true ) || ! $kind ) {
			$kind_post = new Kind_Post( $post );
			$cite      = $kind_post->get_cite();
			if ( Parse_This_MF2::is_microformat( $cite ) ) {
				if ( array_key_exists( 'name', $cite['properties'] ) ) {
					$excerpt = $cite['properties']['name'];
					if ( is_array( $excerpt ) ) {
						$excerpt = $excerpt[0];
					}
				}
			}
		}
		return mb_strimwidth( $excerpt, 0, $length, '...' ); // phpcs:ignore
	}

	/**
	 * Filter the post title. Add a symbol at the end if generated title.
	 *
	 * @access public
	 *
	 * @param string $title   Current post title
	 * @param int    $post_id Post ID
	 * @return string
	 */
	public static function the_title( $title, $post_id ) {
		if ( ! $title && is_admin() ) {
			return self::generate_title( $post_id, 60 ) . '&diams;';
		}
		return $title;
	}

	/**
	 * Filter the single post title.
	 *
	 * @access public
	 *
	 * @param string $title   Current post title
	 * @param WP_Post    $post Post object
	 * @return string
	 */
	public static function single_post_title( $title, $post ) {
		if ( ! empty( $title ) ) {
			return $title;
		}
		return self::generate_title( $post );
	}


	/**
	 * Filter the post excerpt.
	 *
	 * @access public
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function get_excerpt( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		if ( ! empty( $post->post_excerpt ) ) {
			return $post->post_excerpt;
		}
		return $post->post_content;
	}

	/**
	 * Set our post object terms.
	 *
	 * @access public
	 *
	 * @param object $object_id  Current object ID.
	 * @param array  $terms      Array of object terms.
	 * @param array  $tt_ids     Array of term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy slug
	 * @param bool   $append     If terms should be appended or overwrite.
	 * @param array  $old_tt_ids Array of original trem taxonomy IDs.
	 */
	public static function set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( empty( $tt_ids ) && empty( $old_tt_ids ) ) {
			return;
		}
		if ( 'kind' === $taxonomy ) {
			$old_term = get_term_by( 'term_taxonomy_id', array_pop( $old_tt_ids ), 'kind' );
			$old_term = $old_term instanceof WP_Term ? $old_term->slug : '';
			$new_term = get_term_by( 'term_taxonomy_id', array_pop( $tt_ids ), 'kind' );
			$new_term = $new_term instanceof WP_Term ? $new_term->slug : '';
			// Trigger a hook on a changed kind identifying old and new so actions can be performed
			do_action( 'change_kind', $object_id, $old_term, $new_term );
		}
	}

	/**
	 * To Be Run on Plugin Activation.
	 */
	public static function activate_kinds() {
		if ( function_exists( 'iwt_plugin_notice' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( 'You have Indieweb Taxonomy activated. Post Kinds replaces this plugin. Please disable Taxonomy before activating' );
		}
		self::register();
		self::kind_defaultterms();
		flush_rewrite_rules();
	}

	/**
	 * Register the custom taxonomy for kinds.
	 */
	public static function register() {
		global $wp_rewrite;
		$labels = array(
			'name'                       => _x( 'Kinds', 'taxonomy general name', 'indieweb-post-kinds' ),
			'singular_name'              => _x( 'Kind', 'taxonomy singular name', 'indieweb-post-kinds' ),
			'search_items'               => _x( 'Search Kinds', 'search locations', 'indieweb-post-kinds' ),
			'popular_items'              => _x( 'Popular Kinds', 'popular kinds', 'indieweb-post-kinds' ),
			'all_items'                  => _x( 'All Kinds', 'all taxonomy items', 'indieweb-post-kinds' ),
			'parent_item'                => _x( 'Parent Kind', 'taxonomy parent item', 'indieweb-post-kinds' ),
			'parent_item_colon'          => _x( 'Parent Kind:', 'taxonomy parent item with colon', 'indieweb-post-kinds' ),
			'edit_item'                  => _x( 'Edit Kind', 'edit taxonomy item', 'indieweb-post-kinds' ),
			'view_item'                  => _x( 'View Kind', 'view taxonomy item', 'indieweb-post-kinds' ),
			'update_item'                => _x( 'Update Kind', 'update taxonomy item', 'indieweb-post-kinds' ),
			'add_new_item'               => _x( 'Add New Kind', 'add taxonomy item', 'indieweb-post-kinds' ),
			'new_item_name'              => _x( 'New Kind', 'new taxonomy item', 'indieweb-post-kinds' ),
			'separate_items_with_commas' => _x( 'Separate kinds with commas', 'separate kinds with commas', 'indieweb-post-kinds' ),
			'add_or_remove_items'        => _x( 'Add or remove kinds', 'add or remove items', 'indieweb-post-kinds' ),
			'choose_from_most_used'      => _x( 'Choose from the most used kinds', 'choose most used', 'indieweb-post-kinds' ),
			'not found'                  => _x( 'No kinds found', 'no kinds found', 'indieweb-post-kinds' ),
			'no_terms'                   => _x( 'No kinds', 'no kinds', 'indieweb-post-kinds' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'hierarchical'       => false,
			'show_ui'            => true,
			'show_in_menu'       => WP_DEBUG,
			'show_in_nav_menus'  => true,
			'show_in_rest'       => false,
			'show_tagcloud'      => true,
			'show_in_quick_edit' => false,
			'show_admin_column'  => true,
			'meta_box_cb'        => array( static::class, 'select_metabox' ),
			'rewrite'            => true,
			'query_var'          => true,
			'default_term'       => array(
				'name' => __( 'Article', 'indieweb-post-kinds' ),
				'slug' => 'article',
			),

		);
		register_taxonomy( 'kind', array( 'post' ), $args );

		$kind_rewrites = array(
			'kind/([a-z]+)/([0-9]{4})/page/([0-9]{1,})/?' => 'index.php?year=$matches[2]&kind=$matches[1]&paged=$matches[3]', // Year Archive for Kinds with Pagination.
			'kind/([a-z]+)/([0-9]{4})/?$'                 => 'index.php?year=$matches[2]&kind=$matches[1]', // Year Archive for Kinds
			'kind/([a-z]+)/([0-9]{4})/([0-9]{2})/page/([0-9]{1,})/?$' => 'index.php?year=$matches[2]&monthnum=$matches[3]&kind=$matches[1]&paged=$matches[4]', // Month Archive for Kinds with Pagination.
			'kind/([a-z]+)/([0-9]{4})/([0-9]{2})/?$'      => 'index.php?year=$matches[2]&monthnum=$matches[3]&kind=$matches[1]', // Month Archive for Kinds.
			'kind/([a-z]+)/([0-9]{4})/([0-9]{2})/([0-9]{2})/page/([0-9]{1,})/?$' => 'index.php?year=$matches[2]&monthnum=$matches[3]&day=$matches[4]&kind=$matches[1]&paged=$matches[5]', // Day Archive for Kinds with Pagination.
			'kind/([a-z]+)/([0-9]{4})/([0-9]{2})/([0-9]{2})/?$' => 'index.php?year=$matches[2]&monthnum=$matches[3]&day=$matches[4]&kind=$matches[1]', // Day Archive for Kinds.
		);

		foreach ( $kind_rewrites as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}

		$kind_rewrite_taxonomies = apply_filters( 'kind_rewrite_taxonomies', array( 'tag', 'category', 'series' ) );

		// For each kind, support filtering by other taxonomies.
		foreach ( $kind_rewrite_taxonomies as $taxonomy ) {
				add_rewrite_rule(
					sprintf( 'kind/([a-z]+)/%1$s/([^/]*)/feed/([^/]*)/?$', $taxonomy ),
					sprintf( 'index.php?kind=$matches[1]&%1$s=$matches[2]&feed=$matches[3]', $taxonomy ),
					'top'
				);
				add_rewrite_rule(
					sprintf( 'kind/([a-z]+)/%1$s/([^/]*)/feed/?$', $taxonomy ),
					sprintf( 'index.php?kind=$matches[1]&%1$s=$matches[2]&feed=%2$s', $taxonomy, get_default_feed() ),
					'top'
				);
				add_rewrite_rule(
					sprintf( 'kind/([a-z]+)/%1$s/([^&]+)/%2$s/([0-9]{1,})/?$', $taxonomy, $wp_rewrite->pagination_base ),
					sprintF( 'index.php?kind=$matches[1]&%1$s=$matches[2]&paged=$matches[3]', $taxonomy ),
					'top'
				);
				add_rewrite_rule(
					sprintf( 'kind/([a-z]+)/%1$s/([^&]+)/?$', $taxonomy ),
					sprintf( 'index.php?kind=$matches[1]&%1$s=$matches[2]', $taxonomy ),
					'top'
				);

		}

		$kind_exclude_slug = apply_filters( 'kind_exclude_slug', 'exclude' );

		// Exclude/Include Terms with feed and page options. Example usage /exclude/kind/eat,drink would provide an archive excluding these terms.
		add_rewrite_rule(
			$kind_exclude_slug . '/([^/]*)/([a-z,]+)/feed/([^/]*)/?$',
			'index.php?exclude=$matches[1]&exclude_terms=$matches[2]&feed=$matches[3]',
			'top'
		);
		add_rewrite_rule(
			$kind_exclude_slug . '/([^/]*)/([^/]*)/feed',
			'index.php?exclude=$matches[1]&exclude_terms=$matches[2]&feed=' . get_default_feed(),
			'top'
		);
		add_rewrite_rule(
			$kind_exclude_slug . sprintf( '/([^/]*)/([^/]*)/%1$s/([0-9]{1,})/?', $wp_rewrite->pagination_base ),
			'index.php?exclude=$matches[1]&exclude_terms=$matches[2]&paged=$matches[3]',
			'top'
		);
		add_rewrite_rule(
			$kind_exclude_slug . '/([^/]*)/([^/]*)/?$',
			'index.php?exclude=$matches[1]&exclude_terms=$matches[2]',
			'top'
		);

		$onthisday_slug = apply_filters( 'kind_onthisday_slug', 'onthisday' );

		// On This Day Rewrites.

		// If the Simple Location plugin is installed, add the On This Day rewrite options for the map view.
		if ( class_exists( 'Simple_Location_Plugin' ) ) {
			add_rewrite_rule(
				sprintf( '%1$s/([0-9]{2})/([0-9]{2})/map/%2$s/([0-9]{1,})/?', $onthisday_slug, $wp_rewrite->pagination_base ),
				'index.php?monthnum=$matches[1]&day=$matches[2]&paged=$matches[3]&map=1',
				'top'
			);
			add_rewrite_rule(
				$onthisday_slug . '/([0-9]{2})/([0-9]{2})/map/?$',
				'index.php?monthnum=$matches[1]&day=$matches[2]&map=1',
				'top'
			);
			// On This Day Today Map with Pagination
			add_rewrite_rule(
				sprintf( '%1$s/map/%2$s/([0-9]{1,})/?', $onthisday_slug, $wp_rewrite->pagination_base ),
				'index.php?onthisday=1&paged=$matches[1]&map=1',
				'top'
			);
			// On This Day Today Map.
			add_rewrite_rule(
				$onthisday_slug . '/map/?$',
				'index.php?onthisday=1&map=1',
				'top'
			);
		}

		// On This Specific Day.
		add_rewrite_rule(
			sprintf( '%1$s/([0-9]{2})/([0-9]{2})/%2$s/([0-9]{1,})/?', $onthisday_slug, $wp_rewrite->pagination_base ),
			'index.php?monthnum=$matches[1]&day=$matches[2]&paged=$matches[3]',
			'top'
		);
		add_rewrite_rule(
			$onthisday_slug . '/([0-9]{2})/([0-9]{2})/?$',
			'index.php?monthnum=$matches[1]&day=$matches[2]',
			'top'
		);

		// On This Day Today.
		add_rewrite_rule(
			$onthisday_slug . '/?$',
			'index.php?onthisday=1',
			'top'
		);

		// On This Day Today Pagination
		add_rewrite_rule(
			sprintf( '%1$s/%2$s/([0-9]{1,})/?', $onthisday_slug, $wp_rewrite->pagination_base ),
			'index.php?onthisday=1&paged=$matches[1]',
			'top'
		);

		$kind_photos_slug = apply_filters( 'kind_photos_slug', 'photos' );

		// Year Archives for Photos
		add_rewrite_rule(
			sprintf( '%1$s/([0-9]{4})/%2$s/([0-9]{1,})/?$', $kind_photos_slug, $wp_rewrite->pagination_base ),
			'index.php?year=$matches[1]&kind_photos=1&paged=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			$kind_photos_slug . '/([0-9]{4})/?$',
			'index.php?year=$matches[1]&kind_photos=1',
			'top'
		);

		// Year and Month Archive for Photos
		add_rewrite_rule(
			sprintf( '%1$s/([0-9]{4})/([0-9]{2})/%2$s/([0-9]{1,})/?$', $kind_photos_slug, $wp_rewrite->pagination_base ),
			'index.php?year=$matches[1]&monthnum=$matches[2]&kind_photos=1&paged=$matches[3]',
			'top'
		);
		add_rewrite_rule(
			$kind_photos_slug . '/([0-9]{4})/([0-9]{2})/?$',
			'index.php?year=$matches[1]&monthnum=$matches[2]&kind_photos=1',
			'top'
		);

		// Year and Month And Day Archive for Photos
		add_rewrite_rule(
			sprintf( '%1$s/([0-9]{4})/([0-9]{2})/([0-9]{2})/%2$s/([0-9]{1,})/?$', $kind_photos_slug, $wp_rewrite->pagination_base ),
			'index.php?year=$matches[1]&monthnum=$matches[2]&day=$matches[3]&kind_photos=1&paged=$matches[4]',
			'top'
		);
		add_rewrite_rule(
			$kind_photos_slug . '/([0-9]{4})/([0-9]{2})/([0-9]{2})/?$',
			'index.php?year=$matches[1]&monthnum=$matches[2]&day=$matches[3]&kind_photos=1',
			'top'
		);

		// Month And Day Archive for Photos
		add_rewrite_rule(
			sprintf( '%1$s/([0-9]{2})/([0-9]{2})/%2$s/([0-9]{1,})/?$', $kind_photos_slug, $wp_rewrite->pagination_base ),
			'index.php?monthnum=$matches[1]&day=$matches[2]&kind_photos=1&paged=$matches[3]',
			'top'
		);
		add_rewrite_rule(
			$kind_photos_slug . '/([0-9]{2})/([0-9]{2})/?$',
			'index.php?monthnum=$matches[1]&day=$matches[2]&kind_photos=1',
			'top'
		);

		// Root Photos Pagination.
		add_rewrite_rule(
			sprintf( '%1$s/%2$s/([0-9]{1,})/?$', $kind_photos_slug, $wp_rewrite->pagination_base ),
			'index.php?kind_photos=1&paged=$matches[1]',
			'top'
		);

		// Root Photos Feed.
		add_rewrite_rule(
			$kind_photos_slug . '%1$s/feed/([^/]*)/?$',
			'index.php?kind_photos=1&feed=$matches[1]',
			'top'
		);

		// Root Photos Feed.
		add_rewrite_rule(
			$kind_photos_slug . '%1$s/feed/?$',
			'index.php?kind_photos=1&feed=' . get_default_feed(),
			'top'
		);

		// Taxonomy Archives for Photos.
		add_rewrite_rule(
			sprintf( '%1$s/([^/]*)/([^/]*)/%2$s/([0-9]{1,})/?$', $kind_photos_slug, $wp_rewrite->pagination_base ),
			'index.php?$matches[1]=$matches[2]&kind_photos=1&paged=$matches[3]',
			'top'
		);
		add_rewrite_rule(
			$kind_photos_slug . '/([^/]*)/([^/]*)/?$',
			'index.php?$matches[1]=$matches[2]&kind_photos=1',
			'top'
		);

		// Root Photos.
		add_rewrite_rule(
			$kind_photos_slug . '/?$',
			'index.php?kind_photos=1',
			'top'
		);

		// Updated Rule
		$kind_updated_slug = apply_filters( 'kind_updated_slug', 'updated' );

		add_rewrite_rule(
			$kind_updated_slug . '/feed/([^/]*)/?$',
			'index.php?orderby=modified&feed=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			$kind_updated_slug . '%1$s/feed/?$',
			'index.php?orderby=modified&feed=' . get_default_feed(),
			'top'
		);

		add_rewrite_rule(
			sprintf( '%1$s/%2$s/([0-9]{1,})/?$', $kind_updated_slug, $wp_rewrite->pagination_base ),
			'index.php?orderby=modified&paged=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			$kind_updated_slug . '/?$',
			'index.php?orderby=modified',
			'top'
		);

	}

	/**
	 * Sets up Default Terms for Kind Taxonomy.
	 */
	public static function kind_defaultterms() {
		$terms = self::get_kind_list();
		foreach ( $terms as $term ) {
			if ( ! get_term_by( 'slug', $term, 'kind' ) ) {
				self::create_post_kind( $term );
			} else {
				self::update_post_kind( $term );
			}
		}
	}

	/**
	 * Update our post kind if the term already exists.
	 *
	 * @access private
	 *
	 * @param $term
	 */
	private static function update_post_kind( $term ) {
		$t = get_term_by( 'slug', $term, 'kind' );
		if ( ! $t ) {
			return self::create_post_kind( $term );
		}
		$kind = self::get_post_kind_info( $term );
		if ( $kind ) {
			wp_update_term(
				$t->term_id,
				'kind',
				array(
					'name'        => $kind->singular_name,
					'description' => $kind->description,
					'slug'        => $term,
				)
			);
		}
	}

	/**
	 * Create our post kind term.
	 *
	 * @access private
	 *
	 * @param $term
	 */
	private static function create_post_kind( $term ) {
		$kind = self::get_post_kind_info( $term );
		if ( $kind ) {
			wp_insert_term(
				$kind->slug,
				'kind',
				array(
					'name'        => $kind->singular_name,
					'description' => $kind->description,
					'slug'        => $term,
				)
			);
		}
	}

	/**
	 * Filter our post kind permalink.
	 *
	 * @access public
	 *
	 * @param string $permalink Permalink string to filter.
	 * @param int    $post_id   Post ID.
	 * @param bool   $leavename Whether or not to leave the post name.
	 * @return mixed
	 */
	public static function kind_permalink( $permalink, $post_id, $leavename ) {
		if ( false === strpos( $permalink, '%kind%' ) ) {
			return $permalink; }

		// Get post
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $permalink; }

		// Get taxonomy terms
		$terms = wp_get_object_terms( $post->ID, 'kind' );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) && is_object( $terms[0] ) ) {
			$taxonomy_slug = $terms[0]->slug;
		} else {
			$taxonomy_slug = 'note'; }
		return str_replace( '%kind%', $taxonomy_slug, $permalink );
	}

	/**
	 * Fetch the current post kind terms from the current query.
	 *
	 * @access public
	 *
	 * @return array
	 */
	public static function get_terms_from_query() {
		global $wp_query;
		$terms = array();
		$slugs = $wp_query->tax_query->queried_terms['kind']['terms'];
		foreach ( $slugs as $slug ) {
			$terms[] = get_term_by( 'slug', $slug, 'kind' );
		}
		return $terms;
	}

	/**
	 * Filters the post kind archive title.
	 *
	 * @access public
	 *
	 * @param string $title Current archive title value.
	 * @return string|void
	 */
	public static function kind_archive_title( $title ) {
		$return = array();
		if ( is_tax( 'kind' ) ) {
			$terms = self::get_terms_from_query();
			foreach ( $terms as $term ) {
				$return[] = self::get_kind_info( $term->slug, 'name' );
			}
			if ( $return ) {
				$title = join( ', ', $return );
			}
			if ( is_year() ) {
				/* translators: 1: Kinds. Yearly archive title. 2: Year */
				return sprintf( __( '%1$1s: %2$2s', 'indieweb-post-kinds' ), $title, get_the_date( _x( 'Y', 'yearly archives date format', 'indieweb-post-kinds' ) ) );
			} elseif ( is_month() ) {
				/* translators: Monthly archive title. 1: Month name and year */
				return sprintf( __( '%1$1s: %2$2s', 'indieweb-post-kinds' ), $title, get_the_date( _x( 'F Y', 'monthly archives date format', 'indieweb-post-kinds' ) ) );
			} elseif ( is_day() ) {
				/* translators: Daily archive title. 1: Date */
				return sprintf( __( '%1$1s: %2$2s', 'indieweb-post-kinds' ), $title, get_the_date( _x( 'F j, Y', 'daily archives date format', 'indieweb-post-kinds' ) ) );
			} elseif ( is_tag() ) {
				return single_tag_title( $title, false );
			}
		}
		$year = get_query_var( 'year' );
		if ( is_day() && empty( $year ) ) {
			/* translators: Daily archive title. 1: Date */
			return sprintf( __( '%1$1s: %2$2s', 'indieweb-post-kinds' ), __( 'On This Day', 'indieweb-post-kinds' ), get_the_date( _x( 'F j', 'daily archives date format', 'indieweb-post-kinds' ) ) );
		}
		return $title;
	}

	/**
	 * Filters the post kind archive description.
	 *
	 * @access public
	 *
	 * @param string $title Current archive description.
	 * @return string
	 */
	public static function kind_archive_description( $title ) {
		$return = array();
		if ( is_tax( 'kind' ) ) {
			$terms = self::get_terms_from_query();
			foreach ( $terms as $term ) {
				$return[] = self::get_kind_info( $term->slug, 'description' );
			}
			if ( $return ) {
				return join( '<br />', $return );
			}
		}
		return $title;
	}

	/**
	 * Sets Post Format for Post Kind.
	 *
	 * @param int     $post_id Post ID
	 * @param WP_Post $post Post Object
	 * @param boolean $update,
	 */
	public static function post_formats( $post_id, $post, $update ) {
		$kind = get_post_kind_slug( $post_id );
		if ( ! $update ) {
			set_post_format( $post_id, self::get_kind_info( $kind, 'format' ) );
		}
	}

	/**
	 * Fiters the `<title>` tag parts for the post kind.
	 *
	 * @access public
	 *
	 * @param array $parts Document title parts.
	 * @return mixed
	 */
	public static function document_title_parts( $parts ) {
		$year = get_query_var( 'year' );
		if ( is_day() && empty( $year ) ) {
			/* translators: Daily archive title. 1: Date */
			$parts['title'] = sprintf( __( '%1$1s: %2$2s', 'indieweb-post-kinds' ), __( 'On This Day', 'indieweb-post-kinds' ), get_the_date( _x( 'F j', 'daily archives date format', 'indieweb-post-kinds' ) ) );
		}
		return $parts;
	}

	/**
	 * Callback for the taxonomy meta box for our post kinds taxonomy.
	 *
	 * @access public
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function select_metabox( $post ) {
		$include = get_option( 'kind_termslist' );
		$include = array_merge( $include, array( 'note', 'reply', 'article' ) );
		// If Simple Location is Enabled, include the check-in type
		// Filter Kinds
		$include = array_unique( apply_filters( 'kind_include', $include ) );
		// Note cannot be removed or disabled without hacking the code
		if ( ! in_array( 'note', $include, true ) ) {
			$include[] = 'note';
		}
		if ( isset( $_GET['kind'] ) ) {
			$default = get_term_by( 'slug', $_GET['kind'], 'kind' );
		} else {
			// On existing published posts without a kind fall back on article which most closely mimics the behavior of an unclassified post
			if ( 'publish' === get_post_status( $post ) ) {
				$default = get_term_by( 'slug', 'article', 'kind' );
			} else {
				$default = get_term_by( 'slug', get_option( 'kind_default' ), 'kind' );
			}
		}
		$terms     = get_terms(
			'kind',
			array(
				'hide_empty' => 0,
			)
		);
		$postterms = get_the_terms( $post->ID, 'kind' );
		$current   = ( $postterms ? array_pop( $postterms ) : false );
		$current   = ( $current ? $current->term_id : $default->term_id );
		echo '<div id="kind-all">';
		echo '<ul id="taxonomy-kind" class="list:kind category-tabs form-no-clear">';
		foreach ( $terms as $term ) {
			$id   = 'kind-' . $term->term_id;
			$slug = $term->slug;
			if ( in_array( $slug, $include, true ) ) {
				printf( '<li id="%1$s" class="kind-%2$s"><label class="selectit">', esc_attr( $id ), esc_attr( $slug ) );
				printf( '<input type="radio" id="in-%1$s" name="tax_input[kind]" value="%2$s" %3$s />', esc_attr( $id ), esc_attr( $slug ), checked( $current, $term->term_id, false ) );
				self::get_icon( $slug, true );
				echo esc_html( self::get_kind_info( $slug, 'singular_name' ) );
				echo '<br />';
				echo '</label></li>';

			}
		}
		echo '</ul></div>';
	}

	/**
	 * Register our post kinds.
	 *
	 * @access public
	 *
	 * @param string $slug Post kind slug.
	 * @param array  $args Post kind arguments {
		 * @param string $singular_name  Name for one instance of the kind.
		 * @param string $name   General name for the kind plural.
		 * @param string $verb The string for the verb or action (liked this).
		 * @param string $property Microformats 2 property, superseded by properties
		 * @param array $properties Properties is an array outlining the fields for the particular kind. See Kind Fields class for more details
		 * @param string $format Post Format that maps to this.
		 * @param string $description Description of the Kind
		 * @param url $description-url Link to more information
		 * @param string $title Should this kind have an explicit title.
		 * @param boolean $show Show in Settings.
	 * }
	 * @return bool
	 */
	public static function register_post_kind( $slug, $args ) {
		$kind = new Post_Kind( $slug, $args );
		// Do not allow reregistering existing kinds
		if ( isset( static::$kinds[ $slug ] ) ) {
			return false;
		}
		static::$kinds[ $slug ] = $kind;
		return true;
	}

	/**
	 * Retrieve info on a provided post kind.
	 *
	 * @access public
	 *
	 * @param string $slug Post kind to retrieve.
	 * @return bool|mixed
	 */
	public static function get_post_kind_info( $slug ) {
		if ( isset( static::$kinds[ $slug ] ) ) {
			return static::$kinds[ $slug ];
		}
		return false;
	}

	// Enable a hidden post kind

	/**
	 * Enables a hidden post kind.
	 *
	 * @access public
	 *
	 * @param string $slug Post kind slug.
	 * @param bool   $show Whether or not to show the post kind.
	 * @return bool
	 */
	public static function set_post_kind_visibility( $slug, $show = true ) {
		if ( isset( static::$kinds[ $slug ] ) ) {
			static::$kinds[ $slug ]->show = ( $show ? true : false );
			return true;
		}
		return false;
	}

	/**
	 * Retrieve list of registered post kinds.
	 *
	 * @access public
	 * @return array
	 */
	public static function get_kind_list() {
		return array_keys( static::$kinds );
	}

	/**
	 * Returns all translated strings.
	 *
	 * @param string $kind     Post Kind to return.
	 * @param string $property The individual property
	 * @return string|array Return kind-property. If either is set to all, return all.
	 */
	public static function get_kind_info( $kind, $property ) {
		if ( ! is_string( $kind ) || ! $property ) {
			return false;
		}
		if ( 'all' === $kind ) {
			return static::$kinds;
		}
		if ( ! array_key_exists( $kind, static::$kinds ) ) {
			return false;
		}
		$k = static::$kinds[ $kind ];
		if ( 'all' === $property ) {
			return $k;
		}
		if ( ! array_key_exists( $property, get_object_vars( $k ) ) ) {
			return false;
		}
		return $k->$property;
	}

	/**
	 * Add available webmention links.
	 *
	 * @access public
	 *
	 * @param array $links   Array of existing webmention links.
	 * @param int   $post_id Post ID.
	 * @return array
	 */
	public static function webmention_links( $links, $post_id ) {
		$kind_post = new Kind_Post( $post_id );
		$cites     = $kind_post->get_cite( 'url' );
		if ( is_string( $cites ) ) {
			$links[] = $cites;
		}
		if ( is_array( $cites ) ) {
			$links = array_merge( $links, $cites );
			$links = array_unique( $links );
		}
		return $links;
	}

	/**
	 * Add available enclosure links.
	 *
	 * @access public
	 *
	 * @param array $links   Array of existing enclosure links.
	 * @param int   $post_id Post ID.
	 * @return array
	 */
	public static function enclosure_links( $links, $post_id ) {
		$kind_post = new Kind_Post( $post_id );
		if ( in_array( $kind_post->get_kind(), array( 'photo', 'video', 'audio' ), true ) ) {
			$cites = $kind_post->get_cite( 'url' );
			if ( is_string( $cites ) ) {
				$links[] = $cites;
			}
			if ( is_array( $cites ) ) {
				$links = array_merge( $links, $cites );
				$links = array_unique( $links );
			}
		}
		return $links;
	}

	/**
	 * Generate a dropdown of our post kinds for the post list table view.
	 *
	 * @access public
	 * @param string $post_type Current post type being listed.
	 * @param string $which     Current part of the post list table being rendered.
	 */
	public static function kind_dropdown( $post_type, $which ) {
		if ( 'post' === $post_type ) {
			$taxonomy      = 'kind';
			$selected      = isset( $_GET[ $taxonomy ] ) ? $_GET[ $taxonomy ] : '';
			$kind_taxonomy = get_taxonomy( $taxonomy );
			wp_dropdown_categories(
				array(
					/* translators: All */
					'show_option_all' => sprintf( __( 'All %1s', 'indieweb-post-kinds' ), $kind_taxonomy->label ),
					'taxonomy'        => $taxonomy,
					'name'            => $taxonomy,
					'orderby'         => 'name',
					'selected'        => $selected,
					'hierarchical'    => false,
					'show_count'      => true,
					'hide_empty'      => true,
					'value_field'     => 'slug',
				)
			);
		}
	}

	/**
	 * Set our post kind terms upon publish transition status.
	 *
	 * @access public
	 * @param int          $post_id Current post ID
	 * @param WP_Post|null $post    Current post object.
	 */
	public static function publish( $post_id, $post = null ) {
		if ( 'post' !== get_post_type( $post_id ) ) {
			return;
		}
		$count = wp_get_post_terms( $post_id, 'kind' );
		if ( is_countable( $count ) && $count <= 0 ) {
			set_post_kind( $post_id, get_option( 'kind_default' ) );
		}
	}

	/**
	 * Maybe set our post kind terms upon post status transition.
	 *
	 * @access public
	 *
	 * @param string  $new  New post status.
	 * @param string  $old  Old post status.
	 * @param WP_Post $post Post object.
	 */
	public static function transition( $new, $old, $post ) {
		if ( 'publish' === $new ) {
			self::publish( $post->ID, $post );
		}
	}

	/**
	 * Filter the classes on a post's markup.
	 *
	 * @access public
	 *
	 * @param array $classes Current post classes.
	 * @return array
	 */
	public static function post_class( $classes ) {
		if ( 'post' !== get_post_type() ) {
			return $classes;
		}
		$classes[] = 'kind-' . get_post_kind_slug();
		return $classes;
	}

	/**
	 * Returns a pretty, translated version of a post kind slug.
	 *
	 * @param string $slug A post format slug.
	 * @return string The translated post format name.
	 */
	public static function get_post_kind_string( $slug ) {
		$string = self::get_kind_info( $slug, 'singular_name' );
		return $slug ? $slug : '';
	}

	/**
	 * Returns a link to a post kind index.
	 *
	 * @param string $kind The post kind slug.
	 * @return string The post kind term link.
	 */
	public static function get_post_kind_link( $kind ) {
		$term = get_term_by( 'slug', $kind, 'kind' );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}
		return get_term_link( $term );
	}

	/**
	 * Retrieve a total count statistic for a given post kind.
	 *
	 * @access public
	 *
	 * @param string $kind Post kind to get post count for.
	 * @return bool|int
	 */
	public static function get_post_kind_count( $kind ) {
		$term = get_term_by( 'slug', $kind, 'kind' );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}
		return $term->count;
	}

	/**
	 * Returns the post kind slug for the current post.
	 *
	 * @access public
	 *
	 * @param WP_Post|int|null $post Post to retrieve post kind slug for.
	 * @return bool|string
	 */
	public static function get_post_kind_slug( $post = null ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false; }
		$_kind = get_the_terms( $post, 'kind' );
		if ( ! empty( $_kind ) ) {
			$kind = array_shift( $_kind );
			return $kind->slug;
		} else {
			return false; }
	}

	/**
	 * Returns the post kind name for the current post.
	 *
	 * @access public
	 *
	 * @param WP_Post|int|null $post
	 * @return array|bool|string
	 */
	public static function get_post_kind( $post = null ) {
		$kind = get_post_kind_slug( $post );
		if ( $kind ) {
			return self::get_kind_info( $kind, 'singular_name' );
		} else {
			return false;
		}
	}


	/**
	 * Check if a post has any of the given kinds, or any kind.
	 *
	 * @uses has_term()
	 *
	 * @param string|array $kinds Optional. The kind to check.
	 * @param object|int   $post Optional. The post to check. If not supplied, defaults to the current post if used in the loop.
	 * @return bool True if the post has any of the given kinds (or any kind, if no kind specified), false otherwise.
	 */
	public static function has_post_kind( $kinds = array(), $post = null ) {
		$kind = array();
		if ( $kinds ) {
			foreach ( (array) $kinds as $single ) {
				$kind[] = sanitize_key( $single );
			}
		}
		return has_term( $kind, 'kind', $post );
	}

	/**
	 * Assign a kind to a post.
	 *
	 * @param int|WP_Post $post The post for which to assign a kind.
	 * @param string     $kind A kind to assign. Using an empty string or array will default to article.
	 * @return mixed WP_Error on error. Array of affected term IDs on success.
	 */
	public static function set_post_kind( $post, $kind = 'article' ) {
		$post = get_post( $post );
		if ( empty( $post ) ) {
			return new WP_Error( 'invalid_post', __( 'Invalid post', 'indieweb-post-kinds' ) ); }
		$kind = sanitize_key( $kind );
		if ( ! self::get_kind_info( $kind, 'all' ) ) {
			return new WP_Error( 'invalid_kind', __( 'Invalid Kind', 'indieweb-post-kinds' ) );
		}
		return wp_set_post_terms( $post->ID, $kind, 'kind' );
	}

	/**
	 * Update callback for REST API endpoint.
	 *
	 * @access public
	 *
	 * @param string $kind Post kind being processed.
	 * @param $post_array
	 */
	public static function set_rest_post_kind( $kind, $post_array ) {
		if ( isset( $post_array['id'] ) ) {
			self::set_post_kind( $post_array['id'], $kind );
		}
	}

	/**
	 * Whether or not to display the before kind content.
	 *
	 * @access public
	 * @return mixed|void
	 */
	public static function before_kind() {
		return apply_filters( 'kind_icon_display', true );
	}

	/**
	 * Display before Kind - either icon text or no display.
	 *
	 * @param string $kind    The slug for the kind of the current post.
	 * @param string $display Override display;
	 * @return string Marked up kind information.
	 */
	public static function get_before_kind( $kind, $display = null ) {
		if ( ! self::before_kind() ) {
			return '';
		}
		if ( ! $display ) {
			$display = get_option( 'kind_display' );
		}
		$text = '<span class="kind-display-text">' . self::get_kind_info( $kind, 'verb' ) . '</span> ';
		$icon = self::get_icon( $kind );
		// Hide Icon in Feed View
		if ( 'text' !== $display && is_feed() ) {
			$icon = '';
		}
		switch ( $display ) {
			case 'both':
				return $icon . $text;
			case 'icon':
				return $icon;
			case 'text':
				return $text;
			default:
				return '';
		}
	}

	/**
	 * Retrieve the icon SVG for a provided post kind.
	 *
	 * @access public
	 *
	 * @param string $kind Post kind to retrieve the icon for.
	 * @param bool   $echo Whether or not the icon should be echo'd.
	 * @return string
	 */
	public static function get_icon( $kind, $echo = false ) {
		$name = self::get_kind_info( $kind, 'singular_name' );
		$svg  = sprintf( '%1$ssvgs/%2$s.svg', plugin_dir_path( __DIR__ ), $kind );
		if ( file_exists( $svg ) ) {
			$return = sprintf( '<span class="svg-icon svg-%1$s" style="display: inline-block; max-height: 1rem; margin-right: 0.5rem;" aria-label="%2$s" title="%2$s" ><span aria-hidden="true">%3$s</span></span>', esc_attr( $kind ), esc_attr( $name ), file_get_contents( $svg ) );
		} else {
			return '';
		}
		if ( $echo ) {
			echo $return; // phpcs:ignore
		}
		return $return;
	}
} // End Class Kind_Taxonomy


