<?php

class Kind_Post_Widget extends WP_Widget {
	/**
	 * Register widget with WordPress.
	 */
	public function __construct() {
		parent::__construct(
			'Kind_Post_Widget',                // Base ID
			__( 'Kind Post Widget', 'indieweb-post-kinds' ),        // Name
			array(
				'classname'   => 'kind_post_widget',
				'description' => __( 'A widget that allows you to display a list of posts by type', 'indieweb-post-kinds' ),
			)
		);

	} // end constructor

	/**
	 * Front-end display of widget.
	 *
	 * @see WP_Widget::widget()
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Saved values from database.
	 */
	public function widget( $args, $instance ) {
		$kind = ifset( $instance['kind'], 'note' );
		// phpcs:ignore
		echo $args['before_widget'];
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title']; // phpcs:ignore
		}
		$transient = get_transient( 'kind_post_widget' );
		if ( false === $transient ) {
				$query = array(
					'tax_query'   => array(
						array(
							'taxonomy' => 'kind',
							'field'    => 'slug',
							'terms'    => $kind,
						),
					),
					'numberposts' => ifset( $instance['number'], 5 ),
					'fields'      => 'ids',
				);
				$posts = get_posts( $query );
		}

		if ( 0 === count( $posts ) ) {
			return;
		}
		echo '<div id="kind-posts">';
		if ( 0 !== count( $posts ) ) {
			echo '<ul>';
			foreach ( $posts as $post ) {
				printf( '<li>%1$s</li>', self::get_the_link( $post, $kind ) ); // phpcs:ignore
			}
			echo '</ul>';
		} else {
			esc_html_e( 'No Posts Found', 'indieweb-post-kinds' );
		}
		echo '</div>';
		echo $args['after_widget']; // phpcs:ignore
	}

	/**
	 * @access public
	 *
	 * @param WP_Post $post Post object
	 * @param string  $kind Post kind to link.
	 * @return string
	 */
	public function get_the_link( $post, $kind ) {
		return sprintf( '<a href="%2$s">%1$s</a> - %3$s', self::get_the_title( $post, $kind ), get_the_permalink( $post ), get_the_date( '', $post ) );
	}

	/**
	 * Construct a title for the post kind link.
	 *
	 * @access public
	 *
	 * @param WP_Post $post Post object.
	 * @param string  $kind Post kind.
	 * @return string
	 */
	public function get_the_title( $post, $kind ) {
		$title = get_the_title( $post );
		if ( ! empty( $title ) ) {
			return $title;
		}
		if ( ! in_array( $kind, array( 'note', 'article' ), true ) ) {
			$kind_post = new Kind_Post( $post );
			$cite      = $kind_post->get_cite( 'name' );
			if ( false === $cite ) {
				$content = Kind_View::get_post_type_string( $kind_post->get_cite( 'url' ) );
			} else {
				$content = $cite;
			}
		} else {
			$content = $post->post_excerpt;
			// If no excerpt use content
			if ( ! $content ) {
				$content = $post->post_content;
			}
			// If no content use date
			if ( $content ) {
				$content = mb_strimwidth( wp_strip_all_tags( $content ), 0, 40, '...' );
			}
		}
		if ( is_array( $content ) ) {
			$content = wp_json_encode( $content );
		}
		return trim( sprintf( '%1$s %2$s', Kind_Taxonomy::get_before_kind( $kind ), $content ) );
	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @see WP_Widget::update()
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values from database.
	 *
	 * @return array Updated safe values to be saved.
	 */
	public function update( $new_instance, $old_instance ) {
		array_walk_recursive( $new_instance, 'sanitize_text_field' );
		return $new_instance;
	}


	/**
	 * Create the form for the Widget admin
	 *
	 * @see WP_Widget::form()
	 *
	 * @param array $instance Previously saved values from database.
	 */
	public function form( $instance ) {
		$instance['kind'] = ifset( $instance['kind'], 'note' );
		?>
				<p><label for="title"><?php esc_html_e( 'Title: ', 'indieweb-post-kinds' ); ?></label>
				<input type="text" size="30" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?> id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				value="<?php echo esc_html( ifset( $instance['title'] ) ); ?>" /></p>
		<select name="<?php echo esc_attr( $this->get_field_name( 'kind' ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( 'kind' ) ); ?>">
		<?php
		$list   = get_option( 'kind_termslist', Kind_Taxonomy::get_kind_list() );
		$list[] = 'note';
		foreach ( $list as $term ) {
			$value = Kind_Taxonomy::get_post_kind_info( $term );
			printf(
				'<option value="%1$s" %3$s>%2$s</option>',
				esc_attr( $term ),
				Kind_Taxonomy::get_kind_info( $term, 'singular_name' ), // phpcs:ignore
				selected( $instance['kind'], $term )
			);
				printf( '%1$s %2$s<br />', Kind_Taxonomy::get_icon( $term ), esc_html( $value->singular_name ) ); //phpcs:ignore
		}
		?>
		</select>
		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"><?php esc_html_e( 'Number of Posts:', 'indieweb-post-kinds' ); ?></label>
		<input type="number" min="1" step="1" name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>" value="<?php echo esc_attr( ifset( $instance['number'], 5 ) ); ?>" />
		<?php
	}
}
