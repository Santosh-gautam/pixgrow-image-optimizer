<?php
/**
 * WebP delivery class to replace raw image tags with HTML5 <picture> tags on the frontend.
 *
 * @package PixGrow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class PixGrow_WebP_Delivery {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Hook filters to rewrite image HTML outputs
		add_filter( 'wp_get_attachment_image', array( $this, 'filter_wp_get_attachment_image' ), 10, 5 );
		add_filter( 'post_thumbnail_html', array( $this, 'filter_post_thumbnail_html' ), 10, 5 );
		add_filter( 'the_content', array( $this, 'filter_the_content' ), 999 );
		add_filter( 'widget_text', array( $this, 'filter_widget_text' ), 999 );
	}

	/**
	 * Filter wp_get_attachment_image HTML.
	 */
	public function filter_wp_get_attachment_image( $html, $attachment_id, $size, $icon, $attr ) {
		if ( is_feed() ) {
			return $html;
		}
		if ( ! get_post_meta( $attachment_id, '_pixgrow_optimized', true ) ) {
			return $html;
		}
		return $this->wrap_html_in_picture( $html, $attachment_id );
	}

	/**
	 * Filter featured thumbnail HTML.
	 */
	public function filter_post_thumbnail_html( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
		if ( is_feed() ) {
			return $html;
		}
		if ( ! get_post_meta( $post_thumbnail_id, '_pixgrow_optimized', true ) ) {
			return $html;
		}
		return $this->wrap_html_in_picture( $html, $post_thumbnail_id );
	}

	/**
	 * Filter post content body. Only parses raw img HTML tags.
	 */
	public function filter_the_content( $content ) {
		if ( is_feed() || empty( $content ) ) {
			return $content;
		}
		// Match img tags that have wp-image-{id} class to resolve the attachment ID
		return preg_replace_callback( '/<img[^>]+class="[^"]*wp-image-(\d+)[^"]*"[^>]*>/i', array( $this, 'replace_img_tag_callback' ), $content );
	}

	/**
	 * Filter text widget contents. Only parses raw img HTML tags.
	 */
	public function filter_widget_text( $text ) {
		if ( is_feed() || empty( $text ) ) {
			return $text;
		}
		return preg_replace_callback( '/<img[^>]+class="[^"]*wp-image-(\d+)[^"]*"[^>]*>/i', array( $this, 'replace_img_tag_callback' ), $text );
	}

	/**
	 * Regex replacement callback.
	 */
	public function replace_img_tag_callback( $matches ) {
		$img_html      = $matches[0];
		$attachment_id = (int) $matches[1];
		if ( get_post_meta( $attachment_id, '_pixgrow_optimized', true ) ) {
			return $this->wrap_html_in_picture( $img_html, $attachment_id );
		}
		return $img_html;
	}

	/**
	 * Helper to wrap image markup in a responsive HTML5 picture element.
	 * Preserves the original image as a fallback.
	 *
	 * @param string $html Original img HTML markup.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Wrapped/Filtered HTML markup.
	 */
	public function wrap_html_in_picture( $html, $attachment_id ) {
		// Prevent double-wrapping
		if ( false !== strpos( $html, '<picture>' ) ) {
			return $html;
		}

		$webp_exists = get_post_meta( $attachment_id, '_pixgrow_webp_exists', true );
		$avif_exists = get_post_meta( $attachment_id, '_pixgrow_avif_exists', true );

		if ( ! $webp_exists && ! $avif_exists ) {
			return $html;
		}

		// Extract src, srcset, and sizes
		$src = '';
		if ( preg_match( '/src=["\']([^"\']+)["\']/i', $html, $src_match ) ) {
			$src = $src_match[1];
		}

		$srcset = '';
		if ( preg_match( '/srcset=["\']([^"\']+)["\']/i', $html, $srcset_match ) ) {
			$srcset = $srcset_match[1];
		}

		$sizes = '';
		if ( preg_match( '/sizes=["\']([^"\']+)["\']/i', $html, $sizes_match ) ) {
			$sizes = $sizes_match[1];
		}

		$sources_html = '';

		// 1. Pro AVIF source companion (naming image.jpg -> image.jpg.avif)
		if ( $avif_exists ) {
			if ( ! empty( $srcset ) ) {
				$avif_srcset = preg_replace( '/\.(jpg|jpeg|png)(?=\s|\b|$|\?)/i', '.$1.avif', $srcset );
				$sizes_attr  = ! empty( $sizes ) ? ' sizes="' . esc_attr( $sizes ) . '"' : '';
				$sources_html .= '<source srcset="' . esc_attr( $avif_srcset ) . '"' . $sizes_attr . ' type="image/avif">';
			} elseif ( ! empty( $src ) ) {
				$avif_src = preg_replace( '/\.(jpg|jpeg|png)(?=\s|\b|$|\?)/i', '.$1.avif', $src );
				$sources_html .= '<source srcset="' . esc_attr( $avif_src ) . '" type="image/avif">';
			}
		}

		// 2. Free WebP source companion (naming image.jpg -> image.jpg.webp)
		if ( $webp_exists ) {
			if ( ! empty( $srcset ) ) {
				$webp_srcset = preg_replace( '/\.(jpg|jpeg|png)(?=\s|\b|$|\?)/i', '.$1.webp', $srcset );
				$sizes_attr  = ! empty( $sizes ) ? ' sizes="' . esc_attr( $sizes ) . '"' : '';
				$sources_html .= '<source srcset="' . esc_attr( $webp_srcset ) . '"' . $sizes_attr . ' type="image/webp">';
			} elseif ( ! empty( $src ) ) {
				$webp_src = preg_replace( '/\.(jpg|jpeg|png)(?=\s|\b|$|\?)/i', '.$1.webp', $src );
				$sources_html .= '<source srcset="' . esc_attr( $webp_src ) . '" type="image/webp">';
			}
		}

		if ( ! empty( $sources_html ) ) {
			return '<picture>' . $sources_html . $html . '</picture>';
		}

		return $html;
	}
}
