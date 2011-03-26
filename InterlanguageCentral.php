<?php
/**
 * MediaWiki InterlanguageCentral extension v1.1
 *
 * Copyright © 2010-2011 Nikola Smolenski <smolensk@eunet.rs>
 * @version 1.1
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
 * @see http://www.mediawiki.org/wiki/Extension:Interlanguage
 */

$wgInterlanguageCentralExtensionIndexUrl = "";

$wgExtensionFunctions[]="wfInterlanguageCentralExtension";
$wgJobClasses['purgeDependentWikis'] = 'InterlanguageCentralExtensionPurgeJob';
$wgExtensionCredits['parserhook'][] = array(
	'name'			=> 'Interlanguage Central',
	'author'			=> 'Nikola Smolenski',
	'url'				=> 'http://www.mediawiki.org/wiki/Extension:Interlanguage',
	'version'			=> '1.1',
	'descriptionmsg'	=> 'interlanguagecentral-desc',
);
$wgExtensionMessagesFiles['Interlanguagecentral'] = dirname(__FILE__) . '/InterlanguageCentral.i18n.php';
$wgAutoloadClasses['InterlanguageCentralExtensionPurgeJob'] = dirname(__FILE__) .  '/InterlanguageCentralExtensionPurgeJob.php';
$wgAutoloadClasses['InterlanguageCentralExtension'] = dirname(__FILE__) . '/InterlanguageCentralExtension.php';

function wfInterlanguageCentralExtension() {
	global $wgHooks, $wgInterlanguageCentralExtension;

	if( !isset( $wgInterlanguageCentralExtension ) ) {
		$wgInterlanguageCentralExtension = new InterlanguageCentralExtension();
		$wgHooks['ArticleSave'][] = $wgInterlanguageCentralExtension;
		$wgHooks['ArticleSaveComplete'][] = $wgInterlanguageCentralExtension;
		//TODO: ArticleDelete etc.
	}
	return true;
}