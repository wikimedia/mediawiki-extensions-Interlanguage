<?php
/**
 * MediaWiki Interlanguage extension
 * InterlanguageExtension class
 *
 * Copyright © 2008-2011 Nikola Smolenski <smolensk@eunet.rs> and others
 * @version 1.5
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 *
 * For more information,
 * @see https://www.mediawiki.org/wiki/Extension:Interlanguage
 */

use MediaWiki\MediaWikiServices;

class InterlanguageExtension {
	/**
	 * @var \Wikimedia\Rdbms\IDatabase|false
	 */
	public $foreignDbr = false;

	/**
	 * The meat of the extension, the function that handles {{interlanguage:}} magic.
	 *
	 * @param Parser $parser
	 * @param string $param parameter passed to {{interlanguage:}}.
	 * @return string
	 */
	function interlanguage( Parser $parser, $param ) {
		$this->addPageLink( $parser->getOutput(), $param );
		$parser->getOutput()->addModules( [ 'ext.Interlanguage' ] );

		$a = $this->getLinks( $param );
		list($res, $a) = $this->processLinks( $a, $param );

		if($res === false) {
			list( $res, $a ) = $this->preservePageLinks( $parser->getTitle()->mArticleID );
		}

		if ($res === true) {
			$this->sortLinks( $a );
			$res = $this->linksToWiki( $a );
		}

		return $res;
	}

	/**
	 * @param string $param
	 * @return array[]
	 */
	function getLinks( $param ) {
		global $wgInterlanguageExtensionDB, $wgInterlanguageExtensionApiUrl;
		if ( isset( $wgInterlanguageExtensionDB ) && $wgInterlanguageExtensionDB ) {
			return $this->getLinksFromDB( $param );
		} elseif ( isset( $wgInterlanguageExtensionApiUrl ) && $wgInterlanguageExtensionApiUrl ) {
			return $this->getLinksFromApi( $param );
		} else {
			return array();
		}
	}


	/**
	 * Get the links from a foreign database
	 *
	 * @param string $param
	 * @return array[] crafted to look like an API response
	 */
	function getLinksFromDB( $param ) {
		global $wgInterlanguageExtensionDB;

		if( !$this->foreignDbr ) {
			$this->foreignDbr = wfGetDB( DB_REPLICA, array(), $wgInterlanguageExtensionDB );
		}

		list( $dbKey, $namespace ) = $this->getKeyNS( $param );

		$a = array( 'query' => array( 'pages' => array() ) );

		$res = $this->foreignDbr->select(
			'page',
			array( 'page_id', 'page_is_redirect' ),
			array(
				'page_title' => $dbKey,
				'page_namespace' => $namespace
			),
			__FUNCTION__
		);
		$res = $res->fetchObject();

		if( $res === false ) {
			// There is no such article on the central wiki
			$a['query']['pages'][-1] = array( 'missing' => "" );
		} else {
			if( $res->page_is_redirect ) {
				$res = $this->foreignDbr->select(
					array( 'redirect', 'page' ),
					'page_id',
					array(
						'rd_title = page_title',
						'rd_from' => $res->page_id
					),
					__FUNCTION__
				);
				$res = $res->fetchObject();
			}

			$a['query']['pages'][0] = array( 'langlinks' => $this->readLinksFromDB( $this->foreignDbr, $res->page_id ) );
		}

		return $a;
	}

	/**
	 * @param string $param
	 * @return array[] API response
	 */
	function getLinksFromApi( $param ) {
		global $wgInterlanguageExtensionApiUrl;
		$title = $this->translateNamespace( $param );

		$url = $wgInterlanguageExtensionApiUrl .
			"?action=query&prop=langlinks&" .
			"lllimit=500&format=php&redirects&titles=" .
			urlencode( $title );
		$a = MediaWikiServices::getInstance()->getHttpRequestFactory()->get( $url );
		$a = @unserialize( $a );
		return $a;
	}

	/**
	 * Process the links and prepare the result
	 *
	 * @param array[] $a
	 * @param string $param
	 * @return array
	 */
	function processLinks( $a, $param ) {
		global $wgInterlanguageExtensionInterwiki;

		// Be sure to set $res to bool false in case of failure
		$res = false;
		if(isset($a['query']['pages']) && is_array($a['query']['pages'])) {
			$a = array_shift($a['query']['pages']);
			if( isset( $a['missing'] ) ) {
				// There is no such article on the central wiki, so we will display a broken link
				// to the article on the central wiki
				$res = array(
					MediaWikiServices::getInstance()->getLinkRenderer()->makeBrokenLink(
						Title::newFromText( $wgInterlanguageExtensionInterwiki . $this->translateNamespace( $param ) ),
						$wgInterlanguageExtensionInterwiki . $param
					),
					'noparse' => true,
					'isHTML' => true
				);
			} else {
				if( isset( $a['langlinks'] ) ) {
					// Prepare the array for sorting
					$a = $a['langlinks'];
					if( is_array( $a ) ) {
						$res = true;
					}
				} else {
					// There are no links in the central wiki article, so we display nothing
					$res = '';
				}
			}
		}
		return array( $res, $a );
	}

	/**
	 * Add a page to the list of page links. It will later be used by pageLinks().
	 *
	 * @param ParserOutput $parserOutput
	 * @param string $param
	 */
	function addPageLink( ParserOutput $parserOutput, $param ) {
		if ( method_exists( $parserOutput, 'getPageProperty' ) ) {
			$ilp = $parserOutput->getPageProperty( 'interlanguage_pages' );
		} else {
			$ilp = $parserOutput->getProperty( 'interlanguage_pages' );
			if ($ilp === false) {
				$ilp = null;
			}
		}
		if(!$ilp) $ilp = array(); else $ilp = @unserialize( $ilp );
		if(!isset($ilp[$param])) {
			$ilp[$param] = true;
			if ( method_exists( $parserOutput, 'setPageProperty' ) ) {
				// MW 1.38
				$parserOutput->setPageProperty( 'interlanguage_pages', @serialize( $ilp ) );
			} else {
				$parserOutput->setProperty( 'interlanguage_pages', @serialize( $ilp ) );
			}
		}
	}

	/**
	 * @param ParserOutput $parserOutput
	 * @return array|false Array of page links. Empty array if there are no links, literal false if links have not
	 * been yet set.
	 */
	function getPageLinks( $parserOutput ) {
		if ( method_exists( $parserOutput, 'getPageProperty' ) ) {
			// MW 1.38
			$ilp = $parserOutput->getPageProperty( 'interlanguage_pages' );
		} else {
			$ilp = $parserOutput->getProperty( 'interlanguage_pages' );
			if ($ilp === false) {
				$ilp = null;
			}
		}
		if($ilp !== null) $ilp = @unserialize( $ilp );
		return $ilp;
	}

	/**
	 * Copies interlanguage pages from ParserOutput to OutputPage.
	 *
	 * @param OutputPage $out
	 * @param ParserOutput $parserOutput
	 * @return true
	 */
	function onOutputPageParserOutput( &$out, $parserOutput ) {
		$pagelinks = $this->getPageLinks( $parserOutput );
		if( $pagelinks ) $out->interlanguage_pages = $pagelinks;
		return true;
	}

	/**
	 * Displays a list of links to pages on the central wiki below the edit box.
	 *
	 * @param EditPage $editPage
	 * @return true
	 */
	function pageLinks( $editPage ) {
		if( isset( $editPage->mParserOutput ) ) {
			$pagelinks = $this->getPageLinks( $editPage->mParserOutput );
			if(!$pagelinks) $pagelinks = array();
		} else {
			// If there is no ParserOutput, it means the article was not parsed, and we should
			// load links from the DB.
			$pagelinks = $this->loadPageLinks( $editPage->getArticle()->getTitle()->mArticleID );
		}
		$pagelinktitles = $this->makePageLinkTitles( $pagelinks );

		if( count( $pagelinktitles ) ) {
			$ple = wfMessage( 'interlanguage-pagelinksexplanation' )->escaped();

			$res = <<<THEEND
<div class='interlanguageExtensionEditLinks'>
<div class="mw-interlanguageExtensionEditLinksExplanation"><p>$ple</p></div>
<ul>
THEEND;

			$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

			foreach($pagelinktitles as $title) {
				$link = $linkRenderer->makeLink( $title, $title->getText(), array(), array( 'action' => 'edit' ) );
				$res .= "<li>$link</li>\n";
			}
			$res .= <<<THEEND
</ul>
</div>
THEEND;

			$editPage->editFormTextBottom = $res;
		}

		return true;
	}

	/**
	 * Get Db key form and namespace of an article title.
	 *
	 * @param string $param Page title
	 * @return array
	 */
	function getKeyNS( $param ) {
		$paramTitle = Title::newFromText( $param );
		if( $paramTitle ) {
			$dbKey = $paramTitle->getDBkey();
			$namespace = $paramTitle->getNamespace();
		} else {
			//If the title is malformed, try at least this
			$dbKey = strtr( $param, ' ', '_' );
			$namespace = 0;
		}
		return array( $dbKey, $namespace );
	}

	/**
	 * Translate namespace from localized to the canonical form.
	 *
	 * @param string $param Page title
	 * @return string
	 */
	function translateNamespace( $param ) {
		list( $dbKey, $namespace ) = $this->getKeyNS( $param );
		if( $namespace == 0 ) {
			return $dbKey;
		} else {
			$canonicalNamespaces = MediaWikiServices::getInstance()
				->getNamespaceInfo()
				->getCanonicalNamespaces();
			return $canonicalNamespaces[$namespace] . ":" . $dbKey;
		}
	}

	/**
	 * Displays a list of links to pages on the central wiki at the end of the language box.
	 *
	 * @param SkinTemplate $skin
	 * @param QuickTemplate $template
	 * @return true
	 */
	function onSkinTemplateOutputPageBeforeExec( &$skin, &$template ) {
		global $wgOut;
		$pagelinktitles = $this->makePageLinkTitles( $wgOut->interlanguage_pages ?? [] );

		foreach( $pagelinktitles as $title ) {
			$template->data['language_urls'][] = array(
				'href' => $title->getFullURL( array( 'action' => 'edit' ) ),
				'text' => wfMessage( 'interlanguage-editlinks' )->text(),
				'title' => $title->getText(),
				'class' => "interwiki-interlanguage",
			);
		}

		return true;
	}

	/**
	 * Make an array of Titles from the array of links.
	 *
	 * @param array $pagelinks
	 * @return Title[] If there are no page links, an empty array is returned.
	 */
	function makePageLinkTitles( $pagelinks ) {
		global $wgInterlanguageExtensionInterwiki;

		$pagelinktitles = array();
		if( is_array( $pagelinks ) ) {
			foreach( $pagelinks as $page => $dummy ) {
				$title = Title::newFromText( $wgInterlanguageExtensionInterwiki . $this->translateNamespace( $page ) );
				if( $title ) {
					$pagelinktitles[] = $title;
				}
			}
		}

		return $pagelinktitles;

	}

	/**
	 * Returns an array of names of pages on the central wiki which are linked to from a page
	 * on this wiki by {{interlanguage:}} magic. Pagenames are array keys.
	 *
	 * @param int $articleid ID of the article whose links should be returned.
	 * @return array If there are no pages linked, an empty array is returned.
	 */
	function loadPageLinks( $articleid ) {
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select( 'page_props', 'pp_value', array( 'pp_page' => $articleid, 'pp_propname' => 'interlanguage_pages' ), __FUNCTION__);
		$pagelinks = array();
		$row = $res->fetchObject();
		if ( $row ) {
			$pagelinks = @unserialize( $row->pp_value );
		}

		return $pagelinks;
	}

	/**
	 * Preserve the links that are in the article; this will be called in case of an API error.
	 *
	 * @param int $articleid
	 * @return array
	 */
	function preservePageLinks( $articleid ) {
		$dbr = wfGetDB( DB_REPLICA );
		$a = $this->readLinksFromDB( $dbr, $articleid );
		$res = true;
		return array( $res, $a );
	}

	/**
	 * Read interlanguage links from a database, and return them in the same format that API
	 * uses.
	 *
	 * @param \Wikimedia\Rdbms\IDatabase $dbr
	 * @param int $articleid ID of the article whose links should be returned.
	 * @return array[] The array with the links. If there are no links, an empty array is returned.
	 */
	function readLinksFromDB( $dbr, $articleid ) {
		$res = $dbr->select(
			array( 'langlinks' ),
			array( 'll_lang', 'll_title' ),
			array( 'll_from' => $articleid ),
			__FUNCTION__
		);
		$a = array();
		foreach( $res as $row ) {
			$a[] = array( 'lang' => $row->ll_lang, '*' => $row->ll_title );
		}
		return $a;
	}

	/**
	 * Sort an array of links in-place
	 *
	 * @param array[] $a
	 */
	function sortLinks( &$a ) {
		global $wgInterlanguageExtensionSort;
		switch( $wgInterlanguageExtensionSort ) {
			case 'code':
				usort($a, 'InterlanguageExtension::compareCode');
				break;
			case 'alphabetic':
				usort($a, 'InterlanguageExtension::compareAlphabetic');
				break;
			case 'alphabetic_revised':
				usort($a, 'InterlanguageExtension::compareAlphabeticRevised');
				break;
		}
	}

	/**
	 * Convert an array of links to wikitext
	 *
	 * @param array[] $a
	 * @return string
	 */
	function linksToWiki( $a ) {
		global $wgLanguageCode;
		$res = '';
		foreach($a as $v) {
			if($v['lang'] != $wgLanguageCode) {
				$res .= "[[".$v['lang'].':'.$v['*']."]]";
			}
		}
		return $res;
	}

	/**
	 * Compare two interlanguage links by order of alphabet, based on language code.
	 *
	 * @param array $a
	 * @param array $b
	 * @return int
	 */
	static function compareCode($a, $b) {
		return strcmp($a['lang'], $b['lang']);
	}

	/**
	 * Compare two interlanguage links by order of alphabet, based on local language.
	 *
	 * List from http://meta.wikimedia.org/w/index.php?title=Interwiki_sorting_order&oldid=2022604#By_order_of_alphabet.2C_based_on_local_language
	 *
	 * @param array $a
	 * @param array $b
	 * @return int
	 */
	static function compareAlphabetic($a, $b) {
		global $wgInterlanguageExtensionSortPrepend;
		//
		static $order = array(
			'ace', 'af', 'ak', 'als', 'am', 'ang', 'ab', 'ar', 'an', 'arc',
			'roa-rup', 'frp', 'as', 'ast', 'gn', 'av', 'ay', 'az', 'bm', 'bn',
			'zh-min-nan', 'nan', 'map-bms', 'ba', 'be', 'be-x-old', 'bh', 'bcl',
			'bi', 'bar', 'bo', 'bs', 'br', 'bg', 'bxr', 'ca', 'cv', 'ceb', 'cs',
			'ch', 'cbk-zam', 'ny', 'sn', 'tum', 'cho', 'co', 'cy', 'da', 'dk',
			'pdc', 'de', 'dv', 'nv', 'dsb', 'dz', 'mh', 'et', 'el', 'eml', 'en',
			'myv', 'es', 'eo', 'ext', 'eu', 'ee', 'fa', 'hif', 'fo', 'fr', 'fy',
			'ff', 'fur', 'ga', 'gv', 'gd', 'gl', 'gan', 'ki', 'glk', 'gu',
			'got', 'hak', 'xal', 'ko', 'ha', 'haw', 'hy', 'hi', 'ho', 'hsb',
			'hr', 'io', 'ig', 'ilo', 'bpy', 'id', 'ia', 'ie', 'iu', 'ik', 'os',
			'xh', 'zu', 'is', 'it', 'he', 'jv', 'kl', 'kn', 'kr', 'pam', 'krc',
			'ka', 'ks', 'csb', 'kk', 'kw', 'rw', 'ky', 'rn', 'sw', 'kv', 'kg',
			'ht', 'ku', 'kj', 'lad', 'lbe', 'lo', 'la', 'lv', 'lb', 'lt', 'lij',
			'li', 'ln', 'jbo', 'lg', 'lmo', 'hu', 'mk', 'mg', 'ml', 'mt', 'mi',
			'mr', 'arz', 'mzn', 'ms', 'cdo', 'mwl', 'mdf', 'mo', 'mn', 'mus',
			'my', 'nah', 'na', 'fj', 'nl', 'nds-nl', 'cr', 'ne', 'new', 'ja',
			'nap', 'ce', 'pih', 'no', 'nb', 'nn', 'nrm', 'nov', 'ii', 'oc',
			'mhr', 'or', 'om', 'ng', 'hz', 'uz', 'pa', 'pi', 'pag', 'pnb',
			'pap', 'ps', 'km', 'pcd', 'pms', 'tpi', 'nds', 'pl', 'tokipona',
			'tp', 'pnt', 'pt', 'aa', 'kaa', 'crh', 'ty', 'ksh', 'ro', 'rmy',
			'rm', 'qu', 'ru', 'sah', 'se', 'sm', 'sa', 'sg', 'sc', 'sco', 'stq',
			'st', 'tn', 'sq', 'scn', 'si', 'simple', 'sd', 'ss', 'sk', 'cu',
			'sl', 'szl', 'so', 'ckb', 'srn', 'sr', 'sh', 'su', 'fi', 'sv', 'tl',
			'ta', 'kab', 'roa-tara', 'tt', 'te', 'tet', 'th', 'ti', 'tg', 'to',
			'chr', 'chy', 've', 'tr', 'tk', 'tw', 'udm', 'bug', 'uk', 'ur',
			'ug', 'za', 'vec', 'vi', 'vo', 'fiu-vro', 'wa', 'zh-classical',
			'vls', 'war', 'wo', 'wuu', 'ts', 'yi', 'yo', 'zh-yue', 'diq', 'zea',
			'bat-smg', 'zh', 'zh-tw', 'zh-cn',
		);

		if(isset($wgInterlanguageExtensionSortPrepend) && is_array($wgInterlanguageExtensionSortPrepend)) {
			$order = array_merge($wgInterlanguageExtensionSortPrepend, $order);
			unset($wgInterlanguageExtensionSortPrepend);
		}

		$a=array_search($a['lang'], $order);
		$b=array_search($b['lang'], $order);

		return ($a>$b)?1:(($a<$b)?-1:0);
	}

	/**
	 * Compare two interlanguage links by order of alphabet, based on local language (by first
	 * word).
	 *
	 * List from http://meta.wikimedia.org/w/index.php?title=Interwiki_sorting_order&oldid=2022604#By_order_of_alphabet.2C_based_on_local_language_.28by_first_word.29
	 *
	 * @param array $a
	 * @param array $b
	 * @return int
	 */
	static function compareAlphabeticRevised($a, $b) {
		global $wgInterlanguageExtensionSortPrepend;
		static $order = array(
			'ace', 'af', 'ak', 'als', 'am', 'ang', 'ab', 'ar', 'an', 'arc',
			'roa-rup', 'frp', 'as', 'ast', 'gn', 'av', 'ay', 'az', 'id', 'ms',
			'bm', 'bn', 'zh-min-nan', 'nan', 'map-bms', 'jv', 'su', 'ba', 'be',
			'be-x-old', 'bh', 'bcl', 'bi', 'bar', 'bo', 'bs', 'br', 'bug', 'bg',
			'bxr', 'ca', 'ceb', 'cv', 'cs', 'ch', 'cbk-zam', 'ny', 'sn', 'tum',
			'cho', 'co', 'cy', 'da', 'dk', 'pdc', 'de', 'dv', 'nv', 'dsb', 'na',
			'dz', 'mh', 'et', 'el', 'eml', 'en', 'myv', 'es', 'eo', 'ext', 'eu',
			'ee', 'fa', 'hif', 'fo', 'fr', 'fy', 'ff', 'fur', 'ga', 'gv', 'sm',
			'gd', 'gl', 'gan', 'ki', 'glk', 'gu', 'got', 'hak', 'xal', 'ko',
			'ha', 'haw', 'hy', 'hi', 'ho', 'hsb', 'hr', 'io', 'ig', 'ilo',
			'bpy', 'ia', 'ie', 'iu', 'ik', 'os', 'xh', 'zu', 'is', 'it', 'he',
			'kl', 'kn', 'kr', 'pam', 'ka', 'ks', 'csb', 'kk', 'kw', 'rw', 'ky',
			'rn', 'sw', 'kv', 'kg', 'ht', 'ku', 'kj', 'lad', 'lbe', 'lo', 'la',
			'lv', 'to', 'lb', 'lt', 'lij', 'li', 'ln', 'jbo', 'lg', 'lmo', 'hu',
			'mk', 'mg', 'ml', 'krc', 'mt', 'mi', 'mr', 'arz', 'mzn', 'cdo',
			'mwl', 'mdf', 'mo', 'mn', 'mus', 'my', 'nah', 'fj', 'nl', 'nds-nl',
			'cr', 'ne', 'new', 'ja', 'nap', 'ce', 'pih', 'no', 'nb', 'nn',
			'nrm', 'nov', 'ii', 'oc', 'mhr', 'or', 'om', 'ng', 'hz', 'uz', 'pa',
			'pi', 'pag', 'pnb', 'pap', 'ps', 'km', 'pcd', 'pms', 'nds', 'pl',
			'pnt', 'pt', 'aa', 'kaa', 'crh', 'ty', 'ksh', 'ro', 'rmy', 'rm',
			'qu', 'ru', 'sah', 'se', 'sa', 'sg', 'sc', 'sco', 'stq', 'st', 'tn',
			'sq', 'scn', 'si', 'simple', 'sd', 'ss', 'sk', 'sl', 'cu', 'szl',
			'so', 'ckb', 'srn', 'sr', 'sh', 'fi', 'sv', 'tl', 'ta', 'kab',
			'roa-tara', 'tt', 'te', 'tet', 'th', 'vi', 'ti', 'tg', 'tpi',
			'tokipona', 'tp', 'chr', 'chy', 've', 'tr', 'tk', 'tw', 'udm', 'uk',
			'ur', 'ug', 'za', 'vec', 'vo', 'fiu-vro', 'wa', 'zh-classical',
			'vls', 'war', 'wo', 'wuu', 'ts', 'yi', 'yo', 'zh-yue', 'diq', 'zea',
			'bat-smg', 'zh', 'zh-tw', 'zh-cn',
		);

		if(isset($wgInterlanguageExtensionSortPrepend) && is_array($wgInterlanguageExtensionSortPrepend)) {
			$order = array_merge($wgInterlanguageExtensionSortPrepend, $order);
			unset($wgInterlanguageExtensionSortPrepend);
		}

		$a=array_search($a['lang'], $order);
		$b=array_search($b['lang'], $order);

		return ($a>$b)?1:(($a<$b)?-1:0);
	}
}
