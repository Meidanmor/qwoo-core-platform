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
