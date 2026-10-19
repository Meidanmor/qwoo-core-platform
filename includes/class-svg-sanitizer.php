<?php
/**
 * Strict SVG sanitizer for uploaded logos. Parses the file and rebuilds it
 * from a whitelist: shapes, text, gradients, clip paths, masks, filters and
 * <style> without imports or remote URLs. Everything else (scripts,
 * foreignObject, images, links, animation, event attributes, external or
 * data: references) is removed. Files with a DOCTYPE or entities are
 * rejected outright (XXE / entity expansion).
 *
 * The same code ships in the qwoo-platform plugin as QP_Svg; keep
 * the two in step.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Qwoo_Svg_Sanitizer {

	private const MAX_BYTES = 512 * 1024;

	private const ELEMENTS = [
		'svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use', 'style',
		'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
		'text', 'tspan', 'textpath',
		'lineargradient', 'radialgradient', 'stop', 'pattern', 'clippath', 'mask',
		'filter', 'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite', 'feflood',
		'fegaussianblur', 'femerge', 'femergenode', 'feoffset', 'fefunca', 'fefuncb', 'fefuncg', 'fefuncr',
		'fedropshadow', 'femorphology',
	];

	private const ATTRIBUTES = [
		'id', 'class', 'style', 'transform', 'viewbox', 'preserveaspectratio', 'version', 'xmlns', 'xmlns:xlink',
		'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'width', 'height', 'd', 'points', 'pathlength',
		'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap',
		'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'color', 'display',
		'visibility', 'overflow', 'clip-path', 'clippathunits', 'mask', 'maskunits', 'maskcontentunits', 'filter', 'filterunits',
		'primitiveunits', 'gradientunits', 'gradienttransform', 'spreadmethod', 'offset', 'stop-color', 'stop-opacity',
		'patternunits', 'patterncontentunits', 'patterntransform',
		'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'letter-spacing', 'word-spacing',
		'text-anchor', 'dominant-baseline', 'alignment-baseline', 'baseline-shift', 'text-decoration', 'dx', 'dy', 'rotate',
		'lengthadjust', 'textlength', 'startoffset', 'xml:space', 'href', 'xlink:href',
		'in', 'in2', 'result', 'stddeviation', 'mode', 'type', 'values', 'operator', 'k1', 'k2', 'k3', 'k4',
		'flood-color', 'flood-opacity', 'radius', 'tablevalues', 'slope', 'intercept', 'amplitude', 'exponent',
		'color-interpolation-filters', 'mix-blend-mode', 'isolation', 'vector-effect', 'shape-rendering', 'image-rendering',
		'text-rendering', 'enable-background', 'data-name',
	];

	/** The sanitized SVG, or null when the file can't be made safe. */
	public static function sanitize( string $svg ): ?string {
		if ( $svg === '' || strlen( $svg ) > self::MAX_BYTES || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		// No DOCTYPE, no entities: nothing for the parser to expand or fetch.
		if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $svg ) ) {
			return null;
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadXML( $svg, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $doc->documentElement || strtolower( $doc->documentElement->localName ) !== 'svg' ) {
			return null;
		}

		self::clean_element( $doc->documentElement );
		$out = $doc->saveXML( $doc->documentElement );
		return is_string( $out ) && $out !== '' ? $out : null;
	}

	private static function clean_element( DOMElement $el ): void {
		// Children first; copy the list because we remove while walking.
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( ! in_array( strtolower( $child->localName ), self::ELEMENTS, true ) ) {
					$el->removeChild( $child );
					continue;
				}
				self::clean_element( $child );
			} elseif ( $child instanceof DOMText || $child instanceof DOMCdataSection ) {
				continue;
			} else {
				$el->removeChild( $child ); // comments, processing instructions
			}
		}

		if ( strtolower( $el->localName ) === 'style' && ! self::css_is_safe( $el->textContent ) ) {
			$el->parentNode->removeChild( $el );
			return;
		}

		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			$name  = strtolower( $attr->nodeName );
			$value = (string) $attr->value; // entities already decoded by the parser
			$keep  = in_array( $name, self::ATTRIBUTES, true );

			if ( $keep && ( $name === 'href' || $name === 'xlink:href' ) ) {
				$keep = preg_match( '/^#[A-Za-z][\w.:-]*$/', trim( $value ) ) === 1; // in-document references only
			} elseif ( $keep && $name === 'style' ) {
				$keep = self::css_is_safe( $value );
			} elseif ( $keep && preg_match( '/url\s*\(/i', $value ) ) {
				$keep = preg_match( '/^\s*url\(\s*[\'"]?#[\w.:-]+[\'"]?\s*\)\s*$/i', $value ) === 1; // url(#id) only
			}
			if ( $keep && preg_match( '/javascript|vbscript|data\s*:|expression\s*\(/i', preg_replace( '/[\s\x00-\x1f]+/', '', $value ) ) ) {
				$keep = false;
			}
			if ( ! $keep ) {
				$el->removeAttributeNode( $attr );
			}
		}
	}

	private const INLINE_MAX_BYTES = 40 * 1024;

	/**
	 * The SVG made ready to sit inside a page (the Store builder's icons): cleaned
	 * again, simple <style> class rules moved onto the elements (a <style> in a page
	 * applies to the whole page), ids prefixed so two icons never share one, no
	 * fixed size, hidden from screen readers. Null when it can't be done safely
	 * (other CSS selectors, too big): the storefront then shows it as an image.
	 * $recolor: every colour becomes currentColor, so the icon takes the text colour.
	 */
	public static function inline_markup( string $svg, string $prefix, bool $recolor = false ): ?string {
		$clean = self::sanitize( $svg );
		if ( $clean === null || strlen( $clean ) > self::INLINE_MAX_BYTES * 2 ) return null;
		$prefix = preg_replace( '/[^A-Za-z0-9_-]/', '', $prefix );

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadXML( $clean, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $doc->documentElement ) return null;
		$root = $doc->documentElement;

		// <style> rules: only ".class" and element selectors, which can be written onto the elements.
		$rules = [];
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'style' ) ) as $style ) {
			$css = preg_replace( '/\/\*.*?\*\//s', '', (string) $style->textContent );
			if ( strpos( $css, '@' ) !== false ) return null;
			if ( trim( preg_replace( '/[^{}]*\{[^{}]*\}/', '', $css ) ) !== '' ) return null;
			preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER );
			foreach ( $m as $rule ) {
				$decls = trim( $rule[2] );
				foreach ( explode( ',', $rule[1] ) as $sel ) {
					$sel = trim( $sel );
					if ( ! preg_match( '/^(\.[A-Za-z_][\w-]*|[a-z]+)$/i', $sel ) ) return null;
					$rules[] = [ $sel, $decls ];
				}
			}
			$style->parentNode->removeChild( $style );
		}

		$ids = [];
		$all = iterator_to_array( $doc->getElementsByTagName( '*' ) );
		foreach ( $all as $el ) {
			if ( $el->hasAttribute( 'id' ) ) $ids[ $el->getAttribute( 'id' ) ] = true;
		}
		$swap = static function ( $value ) use ( $ids, $prefix ) {
			return preg_replace_callback( '/#([\w.:-]+)/', static function ( $m ) use ( $ids, $prefix ) {
				return isset( $ids[ $m[1] ] ) ? '#' . $prefix . '-' . $m[1] : $m[0];
			}, $value );
		};

		foreach ( $all as $el ) {
			$name = strtolower( $el->localName );
			if ( $name === 'title' || $name === 'desc' ) {
				if ( $el->parentNode ) $el->parentNode->removeChild( $el );
				continue;
			}
			if ( $rules ) {
				$classes = preg_split( '/\s+/', trim( (string) $el->getAttribute( 'class' ) ) );
				$add     = '';
				foreach ( $rules as [ $sel, $decls ] ) {
					$hit = $sel[0] === '.' ? in_array( substr( $sel, 1 ), $classes, true ) : strtolower( $sel ) === $name;
					if ( $hit && $decls !== '' ) $add .= rtrim( $decls, ';' ) . ';';
				}
				// The element's own style still wins, as it did over the class rules.
				if ( $add !== '' ) $el->setAttribute( 'style', $add . (string) $el->getAttribute( 'style' ) );
			}
			$el->removeAttribute( 'class' );
			if ( $el->hasAttribute( 'id' ) ) $el->setAttribute( 'id', $prefix . '-' . $el->getAttribute( 'id' ) );
			foreach ( iterator_to_array( $el->attributes ) as $attr ) {
				if ( $attr->nodeName === 'id' ) continue;
				if ( strpos( $attr->value, '#' ) !== false ) $el->setAttribute( $attr->nodeName, $swap( $attr->value ) );
			}
		}

		if ( $recolor ) {
			$keep = '/^(none|transparent|currentcolor|inherit)$|^url\(/i';
			foreach ( iterator_to_array( $doc->getElementsByTagName( '*' ) ) as $el ) {
				foreach ( [ 'fill', 'stroke' ] as $prop ) {
					if ( $el->hasAttribute( $prop ) && ! preg_match( $keep, trim( $el->getAttribute( $prop ) ) ) ) $el->setAttribute( $prop, 'currentColor' );
				}
				if ( $el->hasAttribute( 'style' ) ) {
					$el->setAttribute( 'style', preg_replace_callback( '/\b(fill|stroke)\s*:\s*([^;]+)/i', static function ( $m ) use ( $keep ) {
						return preg_match( $keep, trim( $m[2] ) ) ? $m[0] : $m[1] . ':currentColor';
					}, $el->getAttribute( 'style' ) ) );
				}
			}
			// Shapes with no colour of their own are black by default: make that the text colour too.
			if ( ! $root->hasAttribute( 'fill' ) && ! preg_match( '/\bfill\s*:/i', (string) $root->getAttribute( 'style' ) ) ) $root->setAttribute( 'fill', 'currentColor' );
		}

		// Sized by the page's CSS, keeping its shape.
		if ( ! $root->hasAttribute( 'viewBox' ) ) {
			$w = (float) $root->getAttribute( 'width' );
			$h = (float) $root->getAttribute( 'height' );
			if ( $w <= 0 || $h <= 0 ) return null;
			$root->setAttribute( 'viewBox', '0 0 ' . $w . ' ' . $h );
		}
		$root->removeAttribute( 'width' );
		$root->removeAttribute( 'height' );
		$root->setAttribute( 'aria-hidden', 'true' );
		$root->setAttribute( 'focusable', 'false' );

		$out = $doc->saveXML( $root );
		if ( ! is_string( $out ) || $out === '' || strlen( $out ) > self::INLINE_MAX_BYTES ) return null;
		return $out;
	}

	/** CSS with no imports, no remote or data URLs, no script. */
	private static function css_is_safe( string $css ): bool {
		$flat = strtolower( preg_replace( '/\\\\|\/\*.*?\*\/|[\s\x00-\x1f]+/s', '', $css ) );
		if ( preg_match( '/@import|javascript|vbscript|expression\(|behavior:|-moz-binding|<|data:/', $flat ) ) {
			return false;
		}
		// Only url(#id) references.
		return ! preg_match( '/url\((?![\'"]?#)/', $flat );
	}
}
