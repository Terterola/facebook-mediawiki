<?php
/**
 * Copyright � 2008 Garrett Brown <http://www.mediawiki.org/wiki/User:Gbruin>
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License along
 * with this program. If not, see <http://www.gnu.org/licenses/>.
 */


/**
 * Not a valid entry point, skip unless MEDIAWIKI is defined.
 */
if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}


/**
 *  Body class for the special page Special:Connect.
 */
class SpecialConnect extends SpecialPage {
	/**
	 * Constructor
	 */
	function __construct() {
		global $wgSpecialPageGroups;
		// Initiate SpecialPage's constructor
		parent::__construct( 'Connect' );
		// Add this special page to the "login" group of special pages
		$wgSpecialPageGroups['Connect'] = 'login';
	}
	
	/**
	 * Overrides getDescription() in SpecialPage. Looks in a different wiki message
	 * for this extension's description.
	 */
	function getDescription() {
		return wfMsg( 'fbconnect-special' );
	}
	
	/**
	 * Performs any necessary execution and outputs the resulting Special page.
	 */
	function execute( $par ) {
		global $wgOut, $wgUser, $wgAuth;
		
		wfLoadExtensionMessages( 'FBConnect' );
		$this->setHeaders();
		$wgOut->disallowUserJs();  # just in case...
		
		// Display heading
		$wgOut->addWikiText( $this->createHeading() );
		
		// Display login form and Facebook Connect form
		$wgOut->addHTML( '<table id="specialconnect-forms"><tr><td class="left">' );
		if( FBConnect::$api->isConnected() ) {
			// If the user is Connected, display some info about them instead of a login form
			$wgOut->addHTML( $this->createInfoForm() );
		} else {
			$template = $this->createLoginForm();
			// Give authentication and captcha plugins a chance to modify the form
			$wgAuth->modifyUITemplate( $template );
			wfRunHooks( 'UserLoginForm', array( &$template ) );
			$wgOut->addTemplate( $template );
		}
		$wgOut->addHTML( '</td><td class="right">' . $this->createConnectForm() . '</td></tr></table>');
	}
	
	/**
	 * Creates a header outlining the benefits of using Facebook Connect.
	 * 
	 * @TODO: Move styles to a stylesheet.
	 */
	function createHeading() {
		$heading = '
			<div id="specialconnect-intro">' . wfMsg( 'fbconnect-intro' ) . '</div>
			<table id="specialconnect-table">
				<tr>
					<th>' . wfMsg( 'fbconnect-conv' ) . '</th>
					<th>' . wfMsg( 'fbconnect-fbml' ) . '</th>
					<th>' . wfMsg( 'fbconnect-comm' ) . '</th>
				</tr>
				<tr>
					<td>' . wfMsg( 'fbconnect-convdesc' ) . '</td>
					<td>' . wfMsg( 'fbconnect-fbmldesc' ) . '</td>
					<td>' . wfMsg( 'fbconnect-commdesc' ) . '</td>
				</tr>
			</table>';
		return $heading;
	}
	
	/**
	 * If the user is already connected, then show some basic info about their Facebook
	 * account (real name, profile picture, etc).
	 */
	function createInfoForm() {
		return '';
	}
	
	/**
	 * Creates a Login Form template object and propogates it with parameters.
	 */
	function createLoginForm() {
		global $wgUser, $wgEnableEmail, $wgEmailConfirmToEdit,
		       $wgCookiePrefix, $wgCookieExpiration, $wgAuth;
		
		$template = new UserloginTemplate();
		
		// Pull the name from $wgUser or cookies
		if( $wgUser->isLoggedIn() )
			$name =  $wgUser->getName();
		else if( isset( $_COOKIE[$wgCookiePrefix . 'UserName'] ))
			$name =  $_COOKIE[$wgCookiePrefix . 'UserName'];
		else
			$name = null;
		// Alias some common URLs for $action and $link
		$loginTitle = self::getTitleFor( 'Userlogin' );
		$this_href = wfUrlencode( $this->getTitle() );
		// Action URL that gets posted to
		$action = $loginTitle->getLocalUrl( 'action=submitlogin&type=login&returnto=' . $this_href );
		// Don't show a "create account" link if the user is not allowed to create an account
		if ($wgUser->isAllowed( 'createaccount' )) {
			$link_href = htmlspecialchars( $loginTitle->getLocalUrl( 'type=signup&returnto=' . $this_href ));
			$link_text = wfMsgHtml( 'nologinlink' );
			$link = wfMsgHtml( 'nologin', "<a href=\"$link_href\">$link_text</a>" );
		} else
			$link = '';
		
		// Set the appropriate options for this template
		$template->set( 'header', '' );
		$template->set( 'name', $name );
		$template->set( 'action', $action );
		$template->set( 'link', $link );
		$template->set( 'message', '' );
		$template->set( 'messagetype', 'none' );
		$template->set( 'useemail', $wgEnableEmail );
		$template->set( 'emailrequired', $wgEmailConfirmToEdit );
		$template->set( 'canreset', $wgAuth->allowPasswordChange() );
		$template->set( 'canremember', ( $wgCookieExpiration > 0 ) );
		$template->set( 'remember', $wgUser->getOption( 'rememberpassword' ) );
		
		// Spit out the form we just made
		return $template;
	}
	
	/**
	 * Creates a button that allows users to merge their account with Facebook Connect.
	 */
	function createConnectForm() {
		global $wgUser;
		
		if( !$wgUser->isLoggedIn() ) {
			$msg = 'Or <strong>login</strong> with Facebook:<br/><br/>' .
		           '<fb:login-button size="large" background="white" length="long"></fb:login-button>';
		} else if( !FBConnect::$api->isConnected() ) {
			$msg = 'Merge your wiki account with your Facebook ID:<br/><br/>' .
		           '<fb:login-button size="large" background="white" length="long"></fb:login-button><br/><br/>' .
			       'Note: This can be undone by a sysop.<br/>' .
			       'Note #2: This feature is unfinished. Eventually, it will require ' .
			       '<a href="http://www.mediawiki.org/wiki/Extension:User_Merge_and_Delete">' .
			       'Extension:User Merge and Delete</a>.';
		} else {
			$msg = 'Logout of Facebook<br/><br/>' .
			       '<fb:login-button size="large" background="white"></fb:login-button><br/><br/>' .
			       'This will also log you out of Facebook and all Connected sites, including this wiki.';
		}
		return $msg;
	}
}