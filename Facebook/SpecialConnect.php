<?php
/*
 * Copyright � 2008-2010 Garrett Brown <http://www.mediawiki.org/wiki/User:Gbruin>
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
 * Class SpecialConnect
 * 
 * This class represents the body class for the page Special:Connect.
 * 
 * Currently, this page has one valid subpage at Special:Connect/ChooseName.
 * Visiting the subpage will generate an error; it is only useful when POSTed to.
 */
class SpecialConnect extends SpecialPage {
	private $userNamePrefix;
	private $isNewUser = false;
	private $mEmail = '';
	private $mRealName = '';
	static private $fbOnLoginJs;
	
	/**
	 * Constructor.
	 */
	function __construct() {
		global $wgSpecialPageGroups;
		// Initiate SpecialPage's constructor
		parent::__construct( 'Connect' );
		// Add this special page to the "login" group of special pages
		$wgSpecialPageGroups['Connect'] = 'login';
		
		wfLoadExtensionMessages( 'Facebook' );
		$this->userNamePrefix = wfMsg('facebook-usernameprefix');
	}
	
	/**
	 * Allows the prefix to be changed at runtime.  This is useful, for example,
	 * to generate a username based off of a facebook name.
	 */
	public function setUserNamePrefix( $prefix ) {
		$this->userNamePrefix = $prefix;
	}
	
	/**
	 * Returns the list of user options that can be updated by facebook on each login.
	 */
	public function getAvailableUserUpdateOptions() {
		return FacebookUser::$availableUserUpdateOptions;
	}
	
	/**
	 * Overrides getDescription() in SpecialPage. Looks in a different wiki message
	 * for this extension's description.
	 */
	function getDescription() {
		return wfMsg( 'facebook-title' );
	}
	
	private function setReturnTo() {
		global $wgRequest;
	
		$this->mReturnTo = $wgRequest->getVal( 'returnto' );
		$this->mReturnToQuery = $wgRequest->getVal( 'returntoquery' );
	
		/**
		 * Wikia BugId: 13709
		 * Before the fix, the logic and the usage of parse_str was wrong
		 * which had fatal side effects.
		 *
		 * The goal of the block below is to remove the fbconnected
		 * variable from the $this->mReturnToQuery (which is supposed
		 * to be a QUERY_STRING-like string.
		 */
		if( !empty($this->mReturnToQuery) ) {
			// a temporary array
			$aReturnToQuery = array();
			// decompose the query string to the array
			parse_str( $this->mReturnToQuery, $aReturnToQuery );
			// remove unwanted elements
			unset( $aReturnToQuery['fbconnected'] );
	
			//recompose the query string
			foreach ( $aReturnToQuery as $k => $v ) {
				$aReturnToQuery[$k] = "{$k}={$v}";
			}
			// Oh, parse_str implicitly urldecodes values which wasn't
			// mentioned in the PHP documentation.
			$this->mReturnToQuery = urlencode( implode( '&', $aReturnToQuery ) );
			// remove the temporary array
			unset( $aReturnToQuery );
		}
	
		$title = Title::newFromText($this->mReturnTo);
		if ($title instanceof Title) {
			$this->mResolvedReturnTo = strtolower(SpecialPage::resolveAlias($title->getDBKey()));
			if (in_array( $this->mResolvedReturnTo, array('userlogout', 'signup', 'connect') )) {
				$titleObj = Title::newMainPage();
				$this->mReturnTo = $titleObj->getText();
				$this->mReturnToQuery = '';
			}
		}
	}
	
	/**
	 * The controller interacts with the views through these two functions.
	 */
	public function sendPage($function, $arg = NULL) {
		global $wgOut;
		// Setup the page for rendering
		wfLoadExtensionMessages( 'Facebook' );
		$this->setHeaders();
		$wgOut->disallowUserJs();  # just in case...
		$wgOut->setRobotPolicy( 'noindex,nofollow' );
		$wgOut->setArticleRelated( false );
		// Call the specified function to continue generating the page
		if (is_null($arg)) {
			$this->$function();
		} else {
			$this->$function($arg);
		}
	}
	
	protected function sendError($titleMsg, $textMsg) {
		global $wgOut;
		$wgOut->showErrorPage($titleMsg, $textMsg);
	}
	
	protected function sendRedirect($specialPage) {
		global $wgOut, $wgUser;
		$urlaction = '';
		if ( !empty( $this->mReturnTo ) ) {
			$urlaction = "returnto=$this->mReturnTo";
			if ( !empty( $this->mReturnToQuery ) )
				$urlaction .= "&returntoquery=$this->mReturnToQuery";
		}
		$wgOut->redirect( $wgUser->getSkin()->makeSpecialUrl( $specialPage, $urlaction ) );
	}
	
	/**
	 * Performs any necessary execution and outputs the resulting Special page.
	 * Minor efforts have been made to conform to a MVC architecture.
	 * 
	 * This isn't a strict adherence to MVC, because technically models should
	 * be manipulated by the controller and the views should observe the models. 
	 */
	public function execute( $par ) {
		global $wgUser, $wgRequest, $facebook;
		
		if ( $wgRequest->getVal('action', '') == 'disconnect_reclamation' ) {
			$this->sendPage('disconnectReclamationActionView');
			return;
		}
		
		// Setup the session
		global $wgSessionStarted;
		if (!$wgSessionStarted) {
			wfSetupSession();
		}
		
		$this->setReturnTo();
		
		switch ( $par ) {
		case 'ChooseName':
			if ( $wgRequest->getCheck('wpCancel') ) {
				$this->sendError('facebook-cancel', 'facebook-canceltext');
			} else {
				$choice = $wgRequest->getText('wpNameChoice');
				$this->manageChooseNamePost($choice);
				$this->sendPage('chooseNameView');
			}
			break;
		case 'ConnectExisting':
			// If not logged in, slide down to the default
			if ($wgUser->isLoggedIn()) {
				$fb_ids = FacebookDB::getFacebookIDs($wgUser);
				if (count( $fb_ids ) > 0) {
					// Will display a message that they're already logged in and connected
					$this->sendPage('alreadyLoggedInView');
				} else {
					$this->sendPage('connectExistingView');
				}
				break;
			}
		default:
			// Logged in status (ID, or 0 if not logged in) 
			$fbid = $facebook->getUser();
			if ( !$fbid ) {
				// The user isn't logged in to Facebook
				if ( !$wgUser->isLoggedIn() ) {
					// The user isn't logged in to Facebook or MediaWiki. Nothing to see
					// here, move along
					$this->sendRedirect('UserLogin');
				} else {
					// The user is logged in to MediaWiki but not Facebook
					$this->sendPage('loginToFacebookView');
				}
			} else {
				// The user is logged in to Facebook
				$mwUser = FacebookDB::getUser($fbid);
				$mwId = $mwUser ? $mwUser->getId() : 0;
				if ( !$wgUser->isLoggedIn() ) {
					if ( !$mwId ) {
						// The Facebook user is new to MediaWiki
						$this->sendPage('connectNewUserView');
					} else {
						// The user is logged in to Facebook, but not MediaWiki. The
						// UserLoadAfterLoadFromSession hook might have failed if the user's
						// "remember me" option was disabled.
						
						// Load the user from their ID
						/*
						$wgUser->mId = $mwId;
						$wgUser->mFrom = 'id';
						$wgUser->load();
						// Update user's info from Facebook
						$fbUser = new FacebookUser($wgUser);
						$fbUser->updateFromFacebook();
						// TODO: Replace this with the following line
						$fbUser = FacebookUser::newFromId($mwId);
						$fbUser->login();
						*/
						$this->sendPage('loginSuccessView');
					}
				} else {
					// The user is logged in to Facbook and MediaWiki
					if ( $mwId == $wgUser->getId() ) {
						// MediaWiki user belongs to the Facebook account. Nothing to see
						// here, move along
						$this->sendRedirect('UserLogin');
					} else {
						// Accounts don't agree. Let's find out what's going on
						if ( !$mwId ) {
							// The Facebook user isn't connected to a MediaWiki account
							$this->sendPage('connectExistingUserView');
						} else {
							// The Facebook account belongs to a different MediaWiki user
							// Ask if we should load the new user from their ID
							// "Would youlike to log out and continue with the new account?"
							// (No: Return to previous page. Yes: Post to Special:Connect/LogoutAndConnect.)
							$this->sendPage('logoutAndConnectView');
						}
					}
				}
			}
			
			/*
			 * If the user is logged in to an unconnected account, and trying to
			* connect a Facebook ID, but the ID is already connected to a DIFFERENT
			* account... display an error message.
			*
			if ( $fbid && $wgUser->isLoggedIn() ) {
				$foundUser = FacebookDB::getUser( $fbid );
				if ( $foundUser && ($foundUser->getId() != $wgUser->getId()) ) {
					$this->sendPage( 'fbIdAlreadyConnectedView' );
					return;
				}
			}
			
			// Either fully logged out of both services, or fully logged in -- nothing for Special:Conect to do
			if ( (!$fbid && !$wgUser->isLoggedIn()) || ($fbid && $wgUser->isLoggedIn()) ) {
				global $wgOut;
				$urlaction = '';
				if ( !empty( $this->mReturnTo ) ) {
					$urlaction = "returnto=$this->mReturnTo";
					if ( !empty( $this->mReturnToQuery ) )
						$urlaction .= "&returntoquery=$this->mReturnToQuery";
				}
				$wgOut->redirect( $wgUser->getSkin()->makeSpecialUrl( 'UserLogin', $urlaction ) );
			} else if ($wgUser->isLoggedIn()) {
				if ($fbid) {
					// If the user has previously connected, log them in.  If they have not, then complete the connection process.
					$fb_ids = FacebookDB::getFacebookIDs($wgUser);
					if (count($fb_ids) > 0) {
						// Will display a message that they're already logged in and connected.
						$this->sendPage('alreadyLoggedInView');
					} else {
						$this->sendPage('connectExistingView');
					}
				} else {
					// If the user isn't Connected, then show a form with the Connect button (regardless of whether they are logged in or not).
					$this->sendPage('connectFormView');
				}
			} else if ($fbid) {
				// Check to see if the Connected user exists in the database
				$user = FacebookDB::getUser($fbid);
				
				if ( !(isset($user) && $user instanceof User) ) {
					$redemption = wfRunHooks('SpecialConnect::login::notFoundLocally', array(&$this, &$user, $fbid));
					if (!$redemption) {
						return false;
					}
				}
				
				// If the user is connected, log them in
				if ( isset($user) && $user instanceof User ) {
					$this->login($user);
					$this->sendPage('successfulLoginView');
				} else {
					$this->sendPage('chooseNameFormView');
				}
			} else {
				// If the user isn't Connected, then show a form with the Connect button
				$this->sendPage('connectFormView');
			}
			/**/
		}
	}
	
	private function manageChooseNamePost($choice) {
		global $wgRequest, $facebook;
		$fbid = $facebook->getUser();
		switch ($choice) {
			// Check to see if the user opted to connect an existing account
			case 'existing':
				$updatePrefs = array();
				foreach ($this->getAvailableUserUpdateOptions() as $option) {
					if ($wgRequest->getText("wpUpdateUserInfo$option", '0') == '1') {
						$updatePrefs[] = $option;
					}
				}
				$name = $wgRequest->getText('wpExistingName');
				$passwprd = $wgRequest->getText('wpExistingPassword');
				$this->attachUser($fbid, $name, $password, $updatePrefs);
				break;
				// Check to see if the user selected another valid option
			case 'nick':
			case 'first':
			case 'full':
				// Get the username from Facebook (Note: not from the form)
				$username = FacebookUser::getOptionFromInfo($choice . 'name', $facebook->getUserInfo());
			case 'manual':
				if (!isset($username) || !FacebookUser::userNameOK($username)) {
					// Use manual name if no username is set, even if manual wasn't chosen
					$username = $wgRequest->getText('wpName2');
				}
				// If no valid username was found, something's not right; ask again
				if (!FacebookUser::userNameOK($username)) {
					$this->sendPage('chooseNameFormView', 'facebook-invalidname');
				} else {
					$this->createUser($fbid, $username);
				}
				break;
			case 'auto':
				// Create a user with a unique generated username
				$this->createUser($fbid, $this->generateUserName());
				break;
			default:
				$this->sendError('facebook-invalid', 'facebook-invalidtext');
		}
	}
	
	
	
	/**
	 * The user is logged in to MediaWiki but not Facebook.
	 * No Facebook user is associated with this MediaWiki account.
	 * 
	 * Exit points: Facebook login button causes a post to a Special:Connect/ConnectUsers
	 */
	private function loginToFacebookView() {
		global $wgOut, $wgSitename, $wgUser;
		$fb_ids = FacebookDB::getFacebookIDs($wgUser);
		
		$this->outputHeader();
		$html = '<div>';
		if ( !count( $fb_ids ) ) {
			// No Facebook user associated with this MediaWiki account
			$html .= wfMsgExt( 'facebook-intro', array('parse', 'content')) . '<br/>';
			$html .= wfMsg( 'facebook-click-to-login', $wgSitename );
		} else {
			// User is already connected to a Facebook account. Send a page asking
			// them to log in to one of their (possibly several) Facebook accounts
			// For now, scold them for trying to log in to a connected account
			$html = 'Error: Your account is already connected with Facebook. Click the button to log in to Facebook.';
		}
		// FacebookInit::getPermissionsAttribute()
		// FacebookInit::getOnLoginAttribute()
		$html .= '<fb:login-button show-faces="true" width="600" max-rows="3" scope="email"></fb:login-button></div>';
		$wgOut->addHTML($html);
		
		// TODO: Add a returnto link
	}
	
	/**
	 * The user is logged in to Facebook, but not MediaWiki.
	 * The Facebook user is new to MediaWiki.
	 */
	private function connectNewUserView() {
		/**
		 * TODO: Add an option to disallow this extension to access your Facebook
		 * information. This option could simply point you to your Facebook privacy
		 * settings. This is necessary in case the user wants to perpetually browse
		 * the wiki anonymously, while still being logged in to Facebook.
		 *
		 * NOTE: The above might be done now that we have checkboxes for which options
		 * to update from fb. Haven't tested it though.
		 */
		global $wgUser, $facebook, $wgOut, $wgFbDisableLogin;
		$messagekey = 'facebook-chooseinstructions';
		
		$titleObj = SpecialPage::getTitleFor( 'Connect' );
		if ( wfReadOnly() ) {
			// The wiki is in read-only mode
			$wgOut->readOnlyPage();
			return false;
		}
		if ( empty( $wgFbDisableLogin ) ) {
			// These two permissions don't apply in $wgFbDisableLogin mode because
			// then technically no users can create accounts
			if ( $wgUser->isBlockedFromCreateAccount() ) {
				wfDebug("Facebook: Blocked user was attempting to create account via Facebook Connect.\n");
				// This is not an explicitly static method but doesn't use $this and can be called like static
				LoginForm::userBlockedMessage();
				return false;
			} elseif ( count( $permErrors = $titleObj->getUserPermissionsErrors( 'createaccount', $wgUser, true ) ) > 0 ) {
				$wgOut->showPermissionsErrorPage( $permErrors, 'createaccount' );
				return false;
			}
		}
		
		// Allow other code to have a custom form here (so that this extension can be integrated with existing custom login screens).
		if( !wfRunHooks( 'SpecialConnect::chooseNameForm', array( &$this, &$messagekey ) ) ){
			return false;
		}
		
		// Connect to the Facebook API
		$userinfo = $facebook->getUserInfo();
		
		// Keep track of when the first option visible to the user is checked
		$checked = false;
		
		// Outputs the canonical name of the special page at the top of the page
		$this->outputHeader();
		// If a different $messagekey was passed (like 'wrongpassword'), use it instead
		$wgOut->addWikiMsg( $messagekey );
		// TODO: Format the html a little nicer
		$wgOut->addHTML('
				<form action="' . $this->getTitle('ChooseName')->getLocalUrl() . '" method="POST">
				<fieldset id="mw-facebook-choosename">
				<legend>' . wfMsg('facebook-chooselegend') . '</legend>
				<table>');
		// Let them attach to an existing. If $wgFbDisableLogin is true, then
		// stand-alone account aren't allowed in the first place
		if (empty( $wgFbDisableLogin )) {
			// Grab the UserName from the cookie if it exists
			global $wgCookiePrefix;
			$name = isset($_COOKIE["{$wgCookiePrefix}UserName"]) ? trim($_COOKIE["{$wgCookiePrefix}UserName"]) : '';
			// Build an array of attributes to update
			$updateOptions = array();
			foreach ($this->getAvailableUserUpdateOptions() as $option) {
				// Translate the MW parameter into a FB parameter
				$value = FacebookUser::getOptionFromInfo($option, $userinfo);
				// If no corresponding value was received from Facebook, then continue
				if (!$value) {
					continue;
				}
				
				// Build the list item for the update option
				$updateOptions[] = "<li><input name=\"wpUpdateUserInfo$option\" type=\"checkbox\" " .
				"value=\"1\" id=\"wpUpdateUserInfo$option\" checked=\"checked\" /><label for=\"wpUpdateUserInfo$option\">" .
				wfMsgHtml("facebook-$option") . wfMsgExt('colon-separator', array('escapenoentities')) .
				" <i>$value</i></label></li>";
			}
			// Implode the update options into an unordered list
			$updateChoices = count($updateOptions) > 0 ? "<br />\n" . '<div id="mw-facebook-choosename-update" class="fbInitialHidden">' .
				wfMsgHtml('facebook-updateuserinfo') . "\n<ul>\n" . implode("\n", $updateOptions) . "\n</ul></div>\n" : '';
			// Create the HTML for the "existing account" option
			$html = '<tr><td class="wm-label"><input name="wpNameChoice" type="radio" ' .
					'value="existing" id="wpNameChoiceExisting"/></td><td class="mw-input">' .
					'<label for="wnNameChoiceExisting">' . wfMsg('facebook-chooseexisting') . '<br/>' .
					wfMsgHtml('facebook-chooseusername') . '<input name="wpExistingName" size="16" value="' .
					$name . '" id="wpExistingName"/>' . wfMsgHtml('facebook-choosepassword') .
					'<input name="wpExistingPassword" ' . 'size="" value="" type="password"/>' . $updateChoices .
					'</td></tr>';
			$wgOut->addHTML($html);
		}
	
		// Add the options for nick name, first name and full name if we can get them
		// TODO: Wikify the usernames (i.e. Full name should have an _ )
		foreach (array('nick', 'first', 'full') as $option) {
			$nickname = FacebookUser::getOptionFromInfo($option . 'name', $userinfo);
			if ($nickname && FacebookUser::userNameOK($nickname)) {
				$wgOut->addHTML('<tr><td class="mw-label"><input name="wpNameChoice" type="radio" value="' .
						$option . ($checked ? '' : '" checked="checked') . '" id="wpNameChoice' . $option .
						'"/></td><td class="mw-input"><label for="wpNameChoice' . $option . '">' .
						wfMsg('facebook-choose' . $option, $nickname) . '</label></td></tr>');
				// When the first radio is checked, this flag is set and subsequent options aren't checked
				$checked = true;
			}
		}
	
		// The options for auto and manual usernames are always available
		$wgOut->addHTML('<tr><td class="mw-label"><input name="wpNameChoice" type="radio" value="auto" ' .
				($checked ? '' : 'checked="checked" ') . 'id="wpNameChoiceAuto"/></td><td class="mw-input">' .
				'<label for="wpNameChoiceAuto">' . wfMsg('facebook-chooseauto', $this->generateUserName()) .
				'</label></td></tr><tr><td class="mw-label"><input name="wpNameChoice" type="radio" ' .
				'value="manual" id="wpNameChoiceManual"/></td><td class="mw-input"><label ' .
				'for="wpNameChoiceManual">' . wfMsg('facebook-choosemanual') . '</label>&nbsp;' .
				'<input name="wpName2" size="16" value="" id="wpName2"/></td></tr>');
		// Finish with two options, "Log in" or "Cancel"
		$wgOut->addHTML('<tr><td></td><td class="mw-submit">' .
				'<input type="submit" value="Log in" name="wpOK" />' .
				'<input type="submit" value="Cancel" name="wpCancel" />');
		// Include returnto and returntoquery parameters if they are set
		if (!empty($this->mReturnTo)) {
			$wgOut->addHTML('<input type="hidden" name="returnto" value="' .
					$this->mReturnTo . '">');
		}
		if (!empty($this->mReturnToQuery)) {
			$wgOut->addHTML('<input type="hidden" name="returnto" value="' .
					$this->mReturnToQuery . '">');
		}
		$wgOut->addHTML("</td></tr></table></fieldset></form>\n\n");
	}
	
	/**
	 * The user has just been logged in by their Facebook account.
	 */
	private function loginSuccessView() {
		global $wgOut, $wgUser;
		$wgOut->setPageTitle( wfMsg('facebook-success') );
		$wgOut->addWikiMsg( 'facebook-successtext' );
		// Run any hooks for UserLoginComplete
		$injected_html = '';
		wfRunHooks( 'UserLoginComplete', array( &$wgUser, &$injected_html ) );
		
		if ( $injected_html !== '' ) {
			$wgOut->addHtml( $injected_html );
			// Render the "return to" text retrieved from the URL
			$wgOut->returnToMain(false, $this->mReturnTo, $this->mReturnToQuery);
		} else {
			$addParam = '';
			if ($this->isNewUser) {
				$addParam = '&fbconnected=1';
			}
			// Since there was no additional message for the user, we can just
			// redirect them back to where they came from
			$titleObj = Title::newFromText( $this->mReturnTo );
			if ( ($titleObj instanceof Title) && !$titleObj->isSpecial('Userlogout') &&
					!$titleObj->isSpecial('Signup') && !$titleObj->isSpecial('Connect') ) {
				$wgOut->redirect( $titleObj->getFullURL( $this->mReturnToQuery .
						(!empty($this->mReturnToQuery) ? '&' : '') .
						'cb=' . rand(1, 10000) . $addParam )
				);
			} else {
				$titleObj = Title::newMainPage();
				$wgOut->redirect( $titleObj->getFullURL( 'cb=' . rand(1, 10000) . $addParam ) );
			}
		}
	}
	
	/**
	 * The user is logged in to Facbook and MediaWiki.
	 * The Facebook user isn't connected to a MediaWiki account.
	 */
	private function connectExistingUserView() {
		$fb_ids = FacebookDB::getFacebookIDs($wgUser);
		if ( !count( $fb_ids ) ) {
			// The Facebook user is new to MediaWiki
			$wgOut->addHTML("Connect your Facebook account to your username<br/>(Yes/No)<br/>");
			// If yes, post to Special:Connect/ConnectExisting or something
		} else {
			// The Facebook use has been to MediaWiki with a different Facebook
			if ( count( $fb_ids ) == 1 ) {
				$wgOut->addHTML('Your username is already connected to a Facebook account. Would you ' .
					'like to connect your username with this Facebook acount also?<br/>(Yes/No)<br/>');
			} else {
				$wgOut->addHTML('Your username is already connected to the following Facebook accounts. Would you ' .
					'like to connect your username with this Facebook acount also?<br/>(Yes/No)<br/>');
			}
		}
	}
	
	/**
	 * Both are loggged in.
	 * The Facebook account belongs to a different MediaWiki user.
	 * Ask if we should load the new user from their ID.
	 * (No: Return to previous page. Yes: Post to Special:Connect/LogoutAndConnect.)
	 * 
	 * Previously: This error-page is shown when the user is attempting to connect a wiki account with
	 * a Facebook ID which is already connected to a different wiki account.
	 */
	private function logoutAndConnectView() {
		global $wgOut, $facebook;
		$wgOut->setPageTitle(wfMsg( 'facebook-fbid-is-already-connected-title' ));
		
		$wgOut->addHTML('Your Facebook account belongs to a different user. Would you like ' .
			'to log out and continue as the other user?<br/><br/>');
		
		$wgOut->addWikiMsg( 'facebook-fbid-is-already-connected' );
		
		// Find out the username that this facebook id is already connected to.
		$fb_user = $facebook->getUser(); // fb id or 0 if none is found.
		if ( $fb_user ) {
			$foundUser = FacebookDB::getUser( $fb_user );
			if ( $foundUser ) {
				$connectedToUser = $foundUser->getName();
				$wgOut->addWikiMsg('facebook-fbid-connected-to', $connectedToUser);
			}
		}
		
		// Render the "Return to" text retrieved from the URL
		$wgOut->returnToMain(false, $this->mReturnTo, $this->mReturnToQuery);
	}
	
	
	private function fbIdAlreadyConnectedView() {
		
	}
	
	
	
	
	
	
	
	
	/**
	 * Disconnect from Facebook.
	 */
	private function disconnectReclamationActionView() {
		global $wgRequest, $wgOut, $facebook;
	
		$wgOut->setArticleRelated( false );
		$wgOut->enableClientCache( false );
		$wgOut->mRedirect = '';
		$wgOut->mBodytext = '';
		$wgOut->setRobotPolicy( 'noindex,nofollow' );
	
		$fb_user_id = $wgRequest->getVal('u', 0);
		$hash = $wgRequest->getVal('h', '');
		$user_id = $facebook->verifyAccountReclamation($fb_user_id, $hash);
	
		if (!($user_id === false)) {
			$result = FacebookInit::coreDisconnectFromFB($user_id);
		}
	
		$title = Title::makeTitle( NS_SPECIAL, 'Signup' );
	
		$html = Xml::openElement('a', array( 'href' => $title->getFullUrl() ));
		$html .= $title->getPrefixedText();
		$html .= Xml::closeElement( 'a' );
	
		if ( (!($user_id === false)) && ($result['status'] == 'ok') ) {
			$wgOut->setPageTitle( wfMsg('facebook-reclamation-title') );
			$wgOut->setHTMLTitle( wfMsg('facebook-reclamation-title') );
			$wgOut->addHTML( wfMsg('facebook-reclamation-body', array('$1' => $html) ));
	
		} else {
			$wgOut->setPageTitle( wfMsg('facebook-reclamation-title-error') );
			$wgOut->setHTMLTitle( wfMsg('facebook-reclamation-title-error') );
			$wgOut->addHTML( wfMsg('facebook-reclamation-body-error', array('$1' => $html) ));
		}
	
		return true;
	}
	
	/**
	 * 
	 *
	private function chooseNameView() {
		global $wgRequest, $facebook;
		$fbid = $facebook->getUser();
		$choice = $wgRequest->getText('wpNameChoice');
		switch ($choice) {
			// Check to see if the user opted to connect an existing account
			case 'existing':
				$updatePrefs = array();
				foreach ($this->getAvailableUserUpdateOptions() as $option) {
					if ($wgRequest->getText("wpUpdateUserInfo$option", '0') == '1') {
						$updatePrefs[] = $option;
					}
				}
				$this->attachUser($fbid, $wgRequest->getText('wpExistingName'),
						$wgRequest->getText('wpExistingPassword'), $updatePrefs);
				break;
				// Check to see if the user selected another valid option
			case 'nick':
			case 'first':
			case 'full':
				// Get the username from Facebook (Note: not from the form)
				$username = FacebookUser::getOptionFromInfo($choice . 'name', $facebook->getUserInfo());
			case 'manual':
				if (!isset($username) || !$this->userNameOK($username)) {
					// Use manual name if no username is set, even if manual wasn't chosen
					$username = $wgRequest->getText('wpName2');
				}
				// If no valid username was found, something's not right; ask again
				if (!$this->userNameOK($username)) {
					$this->sendPage('chooseNameFormView', 'facebook-invalidname');
				} else {
					$this->createUser($fbid, $username);
				}
				break;
			case 'auto':
				// Create a user with a unique generated username
				$this->createUser($fbid, $this->generateUserName());
				break;
			default:
				$this->sendError('facebook-invalid', 'facebook-invalidtext');
		}
	}
	
	/**
	 * NOTE: Actually for when you're both already logged in AND connected
	 * (consider renaming to alreadyLoggedInAndConnectedView())
	 *
	private function alreadyLoggedInView() {
		global $wgOut, $wgUser, $wgRequest, $wgSitename;
		$wgOut->setPageTitle(wfMsg('facebook-alreadyloggedin-title'));
		$wgOut->addWikiMsg('facebook-alreadyloggedin', $wgUser->getName());
	
		// Note: it seems this only gets called when you're already connected, so these buttons aren't needed
		#$wgOut->addWikiMsg('facebook-click-to-connect-existing', $wgSitename);
		#$wgOut->addHTML('<fb:login-button'.FacebookInit::getPermissionsAttribute().FacebookInit::getOnLoginAttribute().'></fb:login-button>');
		// Render the "Return to" text retrieved from the URL
		$wgOut->returnToMain(false, $this->mReturnTo, $this->mReturnToQuery);
	}
	
	/**
	 * This is called when a user is logged into a Wikia account and has just gone through the Facebook Connect popups,
	 * but has not been connected inside the system.
	 *
	 * This function will connect them in the database, save default preferences and present them with "Congratulations"
	 * message and a link to modify their User Preferences. TODO: SHOULD WE JUST SHOW THE CHECKBOXES AGAIN?
	 * 
	 * This is different from attachUser because that is made to synchronously test a login at the same time as creating
	 * the account via the ChooseName form.  This function, however, is designed for when the existing user is already logged in
	 * and wants to quickly connect their Facebook account.  The main difference, therefore, is that this function uses default
	 * preferences while the other form should have already shown the preferences form to the user.
	 */
	public function connectExistingView() {
		global $wgUser, $facebook;
		wfProfileIn(__METHOD__);
		
		// Store the facebook-id <=> mediawiki-id mapping.
		// TODO: FIXME: What sould we do if this fb_id is already connected to a DIFFERENT mediawiki account.
		$fb_id = $facebook->getUser();
		FacebookDB::addFacebookID($wgUser, $fb_id);
		
		// Save the default user preferences.
		global $wgFbEnablePushToFacebook;
		if (!empty( $wgFbEnablePushToFacebook )) {
			global $wgFbPushEventClasses;
			if (!empty( $wgFbPushEventClasses )) {
				$DEFAULT_ENABLE_ALL_PUSHES = true;
				foreach($wgFbPushEventClasses as $pushEventClassName) {
					$pushObj = new $pushEventClassName;
					$prefName = $pushObj->getUserPreferenceName();
					
					$wgUser->setOption($prefName, $DEFAULT_ENABLE_ALL_PUSHES ? '1' : '0');
				}
			}
		}
		$wgUser->setOption('fbFromExist', '1');
		$wgUser->saveSettings();
		
		wfRunHooks( 'SpecialConnect::userAttached', array( &$this ) );
		
		$this->sendPage('displaySuccessAttachingView');
		wfProfileOut(__METHOD__);
	}
	
	/**
	 * This error-page is shown when the user is attempting to connect a wiki account with
	 * a Facebook ID which is already connected to a different wiki account.
	 *
	private function fbIdAlreadyConnectedView() {
		global $wgOut, $facebook;
		$wgOut->setPageTitle(wfMsg('facebook-fbid-is-already-connected-title'));
	
		$wgOut->addWikiMsg('facebook-fbid-is-already-connected');
	
		// Find out the username that this facebook id is already connected to.
		$fb_user = $facebook->getUser(); // fb id or 0 if none is found.
		if ( $fb_user ) {
			$foundUser = FacebookDB::getUser( $fb_user );
			if ( $foundUser ) {
				$connectedToUser = $foundUser->getName();
				$wgOut->addWikiMsg('facebook-fbid-connected-to', $connectedToUser);
			}
		}
	
		// Render the "Return to" text retrieved from the URL
		$wgOut->returnToMain(false, $this->mReturnTo, $this->mReturnToQuery);
	}
	
	/**
	 * Displays the main connect form.
	 *
	private function connectFormView() {
		global $wgOut, $wgSitename;
		// Redirect the user back to where they came from
		$titleObj = Title::newFromText( $this->mReturnTo );
		if ( ($titleObj instanceof Title) && !$titleObj->isSpecial('Userlogout') &&
				!$titleObj->isSpecial('Signup') && !$titleObj->isSpecial('Connect') ) {
			$wgOut->redirect( $titleObj->getFullURL( $this->mReturnToQuery .
					(!empty($this->mReturnToQuery) ? '&' : '') .
					'fbconnected=2&cb=' . rand(1, 10000) )
			);
		} else {
			// Outputs the canonical name of the special page at the top of the page
			$this->outputHeader();
			// Render a humble Facebook Connect button
			$wgOut->addHTML('<div>' . wfMsgExt( 'facebook-intro', array('parse', 'content')) . '<br/>' .
					wfMsg( 'facebook-click-to-login', $wgSitename ) .'
					<fb:login-button size="large" background="black" length="long"' . FacebookInit::getPermissionsAttribute() .
					FacebookInit::getOnLoginAttribute() . '></fb:login-button>
					</div>'
			);
		}
	}
	
	/**
	 * 
	 *
	private function successfulLoginView() {
		global $wgOut, $wgUser;
		$wgOut->setPageTitle(wfMsg('facebook-success'));
		$wgOut->addWikiMsg('facebook-successtext');
		// Run any hooks for UserLoginComplete
		$injected_html = '';
		wfRunHooks( 'UserLoginComplete', array( &$wgUser, &$injected_html ) );
	
		if( $injected_html !== '' ) {
			$wgOut->addHtml( $injected_html );
			// Render the "return to" text retrieved from the URL
			$wgOut->returnToMain(false, $this->mReturnTo, $this->mReturnToQuery);
		} else {
			$addParam = '';
			if ($this->isNewUser) {
				$addParam = '&fbconnected=1';
			}
			// Since there was no additional message for the user, we can just
			// redirect them back to where they came from
			$titleObj = Title::newFromText( $this->mReturnTo );
			if ( ($titleObj instanceof Title) && !$titleObj->isSpecial('Userlogout') &&
					!$titleObj->isSpecial('Signup') && !$titleObj->isSpecial('Connect') ) {
				$wgOut->redirect( $titleObj->getFullURL( $this->mReturnToQuery .
						(!empty($this->mReturnToQuery) ? '&' : '') .
						'cb=' . rand(1, 10000) . $addParam )
				);
			} else {
				$titleObj = Title::newMainPage();
				$wgOut->redirect( $titleObj->getFullURL( 'cb=' . rand(1, 10000) . $addParam ) );
			}
		}
	}
	
	/**
	 * TODO: Add an option to disallow this extension to access your Facebook
	 * information. This option could simply point you to your Facebook privacy
	 * settings. This is necessary in case the user wants to perpetually browse
	 * the wiki anonymously, while still being logged in to Facebook.
	 *
	 * NOTE: The above might be done now that we have checkboxes for which options
	 * to update from fb. Haven't tested it though.
	 *
	private function chooseNameFormView($messagekey = 'facebook-chooseinstructions') {
		// Permissions restrictions.
		global $wgUser, $facebook, $wgOut, $wgFbDisableLogin;
	
		$titleObj = SpecialPage::getTitleFor( 'Connect' );
		if ( wfReadOnly() ) {
			// The wiki is in read-only mode
			$wgOut->readOnlyPage();
			return false;
		}
		if ( empty( $wgFbDisableLogin ) ) {
			// These two permissions don't apply in $wgFbDisableLogin mode because
			// then technically no users can create accounts
			if ( $wgUser->isBlockedFromCreateAccount() ) {
				wfDebug("Facebook: Blocked user was attempting to create account via Facebook Connect.\n");
				// This is not an explicitly static method but doesn't use $this and can be called like static
				LoginForm::userBlockedMessage();
				return false;
			} elseif ( count( $permErrors = $titleObj->getUserPermissionsErrors( 'createaccount', $wgUser, true ) ) > 0 ) {
				$wgOut->showPermissionsErrorPage( $permErrors, 'createaccount' );
				return false;
			}
		}
	
		// Allow other code to have a custom form here (so that this extension can be integrated with existing custom login screens).
		if( !wfRunHooks( 'SpecialConnect::chooseNameForm', array( &$this, &$messagekey ) ) ){
			return false;
		}
	
		// Connect to the Facebook API
		$userinfo = $facebook->getUserInfo();
	
		// Keep track of when the first option visible to the user is checked
		$checked = false;
	
		// Outputs the canonical name of the special page at the top of the page
		$this->outputHeader();
		// If a different $messagekey was passed (like 'wrongpassword'), use it instead
		$wgOut->addWikiMsg( $messagekey );
		// TODO: Format the html a little nicer
		$wgOut->addHTML('
				<form action="' . $this->getTitle('ChooseName')->getLocalUrl() . '" method="POST">
				<fieldset id="mw-facebook-choosename">
				<legend>' . wfMsg('facebook-chooselegend') . '</legend>
				<table>');
		// Let them attach to an existing. If $wgFbDisableLogin is true, then
		// stand-alone account aren't allowed in the first place
		if (empty( $wgFbDisableLogin )) {
			// Grab the UserName from the cookie if it exists
			global $wgCookiePrefix;
			$name = isset($_COOKIE[$wgCookiePrefix . 'UserName']) ?
			trim($_COOKIE[$wgCookiePrefix . 'UserName']) : '';
			// Build an array of attributes to update
			$updateOptions = array();
			foreach ($this->getAvailableUserUpdateOptions() as $option) {
				// Translate the MW parameter into a FB parameter
				$value = FacebookUser::getOptionFromInfo($option, $userinfo);
				// If no corresponding value was received from Facebook, then continue
				if (!$value) {
					continue;
				}
	
				// Build the list item for the update option
				$updateOptions[] = "<li><input name=\"wpUpdateUserInfo$option\" type=\"checkbox\" " .
				"value=\"1\" id=\"wpUpdateUserInfo$option\" checked=\"checked\" /><label for=\"wpUpdateUserInfo$option\">" .
				wfMsgHtml("facebook-$option") . wfMsgExt('colon-separator', array('escapenoentities')) .
				" <i>$value</i></label></li>";
			}
			// Implode the update options into an unordered list
			$updateChoices = count($updateOptions) > 0 ? "<br />\n" . wfMsgHtml('facebook-updateuserinfo') .
			"\n<ul>\n" . implode("\n", $updateOptions) . "\n</ul>\n" : '';
			// Create the HTML for the "existing account" option
			$html = '<tr><td class="wm-label"><input name="wpNameChoice" type="radio" ' .
					'value="existing" id="wpNameChoiceExisting"/></td><td class="mw-input">' .
					'<label for="wnNameChoiceExisting">' . wfMsg('facebook-chooseexisting') . '<br/>' .
					wfMsgHtml('facebook-chooseusername') . '<input name="wpExistingName" size="16" value="' .
					$name . '" id="wpExistingName"/>' . wfMsgHtml('facebook-choosepassword') .
					'<input name="wpExistingPassword" ' . 'size="" value="" type="password"/>' . $updateChoices .
					'</td></tr>';
			$wgOut->addHTML($html);
		}
	
		// Add the options for nick name, first name and full name if we can get them
		// TODO: Wikify the usernames (i.e. Full name should have an _ )
		foreach (array('nick', 'first', 'full') as $option) {
			$nickname = FacebookUser::getOptionFromInfo($option . 'name', $userinfo);
			if ($nickname && $this->userNameOK($nickname)) {
				$wgOut->addHTML('<tr><td class="mw-label"><input name="wpNameChoice" type="radio" value="' .
						$option . ($checked ? '' : '" checked="checked') . '" id="wpNameChoice' . $option .
						'"/></td><td class="mw-input"><label for="wpNameChoice' . $option . '">' .
						wfMsg('facebook-choose' . $option, $nickname) . '</label></td></tr>');
				// When the first radio is checked, this flag is set and subsequent options aren't checked
				$checked = true;
			}
		}
	
		// The options for auto and manual usernames are always available
		$wgOut->addHTML('<tr><td class="mw-label"><input name="wpNameChoice" type="radio" value="auto" ' .
				($checked ? '' : 'checked="checked" ') . 'id="wpNameChoiceAuto"/></td><td class="mw-input">' .
				'<label for="wpNameChoiceAuto">' . wfMsg('facebook-chooseauto', $this->generateUserName()) .
				'</label></td></tr><tr><td class="mw-label"><input name="wpNameChoice" type="radio" ' .
				'value="manual" id="wpNameChoiceManual"/></td><td class="mw-input"><label ' .
				'for="wpNameChoiceManual">' . wfMsg('facebook-choosemanual') . '</label>&nbsp;' .
				'<input name="wpName2" size="16" value="" id="wpName2"/></td></tr>');
		// Finish with two options, "Log in" or "Cancel"
		$wgOut->addHTML('<tr><td></td><td class="mw-submit">' .
				'<input type="submit" value="Log in" name="wpOK" />' .
				'<input type="submit" value="Cancel" name="wpCancel" />');
		// Include returnto and returntoquery parameters if they are set
		if (!empty($this->mReturnTo)) {
			$wgOut->addHTML('<input type="hidden" name="returnto" value="' .
					$this->mReturnTo . '">');
		}
		if (!empty($this->mReturnToQuery)) {
			$wgOut->addHTML('<input type="hidden" name="returnto" value="' .
					$this->mReturnToQuery . '">');
		}
		$wgOut->addHTML("</td></tr></table></fieldset></form>\n\n");
	}
	
	/**
	 * Success page for attaching Facebook account to a pre-existing MediaWiki
	 * account. Shows a link to preferences and a link back to where the user
	 * came from.
	 */
	private function displaySuccessAttachingView() {
		global $wgOut, $wgUser, $wgRequest;
		wfProfileIn(__METHOD__);
	
		$wgOut->setPageTitle(wfMsg('facebook-success'));
	
		$prefsLink = SpecialPage::getTitleFor('Preferences')->getLinkUrl();
		$wgOut->addHTML(wfMsg('facebook-success-connecting-existing-account', $prefsLink));
	
		// Run any hooks for UserLoginComplete
		$inject_html = '';
		wfRunHooks( 'UserLoginComplete', array( &$wgUser, &$inject_html ) );
		$wgOut->addHtml( $inject_html );
	
		// Since there was no additional message for the user, we can just
		// redirect them back to where they came from
		$titleObj = Title::newFromText( $this->mReturnTo );
		if ( ($titleObj instanceof Title) && !$titleObj->isSpecial('Userlogout') &&
				!$titleObj->isSpecial('Signup') && !$titleObj->isSpecial('Connect') ) {
			$wgOut->redirect( $titleObj->getFullURL($this->mReturnToQuery .
					(!empty($this->mReturnToQuery) ? '&' : '') .
					'fbconnected=1&cb=' . rand(1, 10000) )
			);
		} else {
			/*
			 // Render a "return to" link retrieved from the URL
			$wgOut->returnToMain( false, $this->mReturnTo, $this->mReturnToQuery .
					(!empty($this->mReturnToQuery) ? '&' : '') .
					'fbconnected=1&cb=' . rand(1, 10000) );
			/**/
			$titleObj = Title::newMainPage();
			$wgOut->redirect( $titleObj->getFullURL('fbconnected=1&cb=' . rand(1, 10000)) );
		}
	
		wfProfileOut(__METHOD__);
	}
	
	
	### Model Functions ###
	
	/**
	 * Logs in the user by their Facebook ID. If the Facebook user doesn't have
	 * an account on the wiki, then they are presented with a form prompting
	 * them to choose a wiki username.
	 */
	protected function login($user) {
		global $wgUser;
		
		$fbUser = new FacebookUser($user);
		// Update user from Facebook (see FacebookUser::updateFromFacebook)
		$fbUser->updateFromFacebook();
		
		// Setup the session
		global $wgSessionStarted;
		if (!$wgSessionStarted) {
			wfSetupSession();
		}
		
		// Log the user in and store the new user as the global user object
		$user->setCookies();
		$wgUser = $user;
		
		// Similar to what's done in LoginForm::authenticateUserData().
		// Load $wgUser now. This is necessary because loading $wgUser (say
		// by calling getName()) calls the UserLoadFromSession hook, which
		// potentially creates the user in the local database.
		$sessionUser = User::newFromSession();
		$sessionUser->load();
		
		// Provide user interface in correct language immediately on this first page load
		global $wgLang;
		$wgLang = Language::factory( $wgUser->getOption( 'language' ) );
		
		return true;
	}

	protected function createUser($fb_id, $name) {
		global $wgUser, $wgOut, $wgFbDisableLogin, $wgAuth, $wgRequest, $wgMemc;
		wfProfileIn(__METHOD__);
		
		// Handle accidental reposts.
		if ( $wgUser->isLoggedIn() ) {
			$this->sendPage('successfulLoginView');
			wfProfileOut(__METHOD__);
			return;
		}
		
		// Make sure we're not stealing an existing user account.
		if (!$name || !FacebookUser::userNameOK($name)) {
			// TODO: Provide an error message that explains that they need to pick a name or the name is taken.
			wfDebug("Facebook: Name not OK: '$name'\n");
			$this->sendPage('chooseNameFormView');
			return;
		}
		
		/// START OF TYPICAL VALIDATIONS AND RESTRICITONS ON ACCOUNT-CREATION. ///
		
		// Check the restrictions again to make sure that the user can create this account.
		$titleObj = SpecialPage::getTitleFor( 'Connect' );
		if ( wfReadOnly() ) {
			$wgOut->readOnlyPage();
			return;
		}
		
		if ( empty( $wgFbDisableLogin ) ) {
			// These two permissions don't apply in $wgFbDisableLogin mode because
			// then technically no users can create accounts
			if ( $wgUser->isBlockedFromCreateAccount() ) {
				wfDebug("Facebook: Blocked user was attempting to create account via Facebook Connect.\n");
				$wgOut->showErrorPage('facebook-error', 'facebook-errortext');
				return;
			} elseif ( count( $permErrors = $titleObj->getUserPermissionsErrors( 'createaccount', $wgUser, true ) )>0 ) {
				$wgOut->showPermissionsErrorPage( $permErrors, 'createaccount' );
				return;
			}
		}
		
		// If we are not allowing users to login locally, we should be checking
		// to see if the user is actually able to authenticate to the authenti-
		// cation server before they create an account (otherwise, they can
		// create a local account and login as any domain user). We only need
		// to check this for domains that aren't local.
		$mDomain = $wgRequest->getText( 'wpDomain' );
		if( 'local' != $mDomain && '' != $mDomain ) {
			if( !$wgAuth->canCreateAccounts() && ( !$wgAuth->userExists( $name ) ) ) {
				$wgOut->showErrorPage('facebook-error', 'wrongpassword');
				return false;
			}
		}
		
		// IP-blocking (and open proxy blocking) protection from SpecialUserLogin
		global $wgEnableSorbs, $wgProxyWhitelist;
		$ip = wfGetIP();
		if ( $wgEnableSorbs && !in_array( $ip, $wgProxyWhitelist ) &&
					$wgUser->inSorbsBlacklist( $ip ) )
		{
			$wgOut->showErrorPage('facebook-error', 'sorbs_create_account_reason');
			return;
		}
 		
		/**
		// Test to see if we are denied by $wgAuth or the user can't create an account
		if ( !$wgAuth->autoCreate() || !$wgAuth->userExists( $userName ) ||
									   !$wgAuth->authenticate( $userName )) {
			$result = false;
			return true;
		}
		/**/
		
		// Run a hook to let custom forms make sure that it is okay to proceed with processing the form.
		// This hook should only check preconditions and should not store values.  Values should be stored using the hook at the bottom of this function.
		// Can use 'this' to call sendPage('chooseNameFormView', 'SOME-ERROR-MSG-CODE-HERE') if some of the preconditions are invalid.
		if(! wfRunHooks( 'SpecialConnect::createUser::validateForm', array( &$this ) )){
			return;
		}

		$user = User::newFromName($name);
		if (!$user) {
			wfDebug("Facebook: Error creating new user.\n");
			$wgOut->showErrorPage('facebook-error', 'facebook-error-creating-user');
			return;
		}
		
		// Let extensions abort the account creation.  If you have extensions which are expecting
		// a Real Name or Email, you may need to disable them since these are not requirements of
		// Facebook Connect (so users will not have them).
		// NOTE: Currently this is commented out because it seems that most wikis might have a
		// handful of restrictions that won't be needed on Facebook Connections. For instance,
		// requiring a CAPTCHA or age-verification, etc.  Having a Facebook account as a
		// pre-requisitie removes the need for that.
		/*
		$abortError = '';
		if( !wfRunHooks( 'AbortNewAccount', array( $user, &$abortError ) ) ) {
			// Hook point to add extra creation throttles and blocks
			wfDebug( "SpecialConnect::createUser: a hook blocked creation\n" );
			$wgOut->showErrorPage('facebook-error', 'facebook-error-user-creation-hook-aborted', array($abortError));
			return false;
		}
		/**/
		
		// Apply account-creation throttles
		global $wgAccountCreationThrottle;
		if ( $wgAccountCreationThrottle && $wgUser->isPingLimitable() ) {
			$key = wfMemcKey( 'acctcreate', 'ip', $ip );
			$value = $wgMemc->get( $key );
			if ( !$value ) {
				$wgMemc->set( $key, 0, 86400 );
			}
			if ( $value >= $wgAccountCreationThrottle ) {
				// TODO: 'acct_creation_throttle_hit' should use 'parseinline' not 'parse' inside $wgOut->showErrorPage()
				$wgOut->showErrorPage('permissionserrors', 'acct_creation_throttle_hit', array($wgAccountCreationThrottle));
				return false;
			}
			$wgMemc->incr( $key );
		}
		
		/// END OF TYPICAL VALIDATIONS AND RESTRICITONS ON ACCOUNT-CREATION. ///
 		
		// Create the account (locally on main cluster or via $wgAuth on other clusters)
		$email = $realName = ""; // The real values will get filled in outside of the scope of this function.
		$pass = null;
		if( !$wgAuth->addUser( $user, $pass, $email, $realName ) ) {
			wfDebug("Facebook: Error adding new user to database.\n");
			$wgOut->showErrorPage('facebook-error', 'facebook-errortext');
			return;
		}
		
		// Adds the user to the local database (regardless of whether $wgAuth was used)
		$user = $this->initUser( $user, true );
		
		// Attach the user to their Facebook account in the database
		// This must be done up here so that the data is in the database before copy-to-local is done for sharded setups
		FacebookDB::addFacebookID($user, $fb_id);
		
		wfRunHooks( 'AddNewAccount', array( $user ) );
		
		// Mark that the user is a Facebook user
		$user->addGroup('fb-user');
		
		// Store which fields should be auto-updated from Facebook when the user logs in. 
		$updateFormPrefix = 'wpUpdateUserInfo';
		foreach ($this->getAvailableUserUpdateOptions() as $option) {
			if($wgRequest->getVal($updateFormPrefix . $option, '') != ''){
				$user->setOption("facebook-update-on-login-$option", 1);
			} else {
				$user->setOption("facebook-update-on-login-$option", 0);
			}
		}
		
		// Process the FacebookPushEvent preference checkboxes if Push Events are enabled
		global $wgFbEnablePushToFacebook;
		if( $wgFbEnablePushToFacebook ) {
			global $wgFbPushEventClasses;
			if (!empty( $wgFbPushEventClasses )) {
				foreach( $wgFbPushEventClasses as $pushEventClassName ) {
					$pushObj = new $pushEventClassName;
					$className = get_class();
					$prefName = $pushObj->getUserPreferenceName();
					
					$user->setOption($prefName, ($wgRequest->getCheck($prefName) ? '1' : '0'));
				}
			}
			
			// Save the preference for letting user select to never send anything to their newsfeed
			$prefName = FacebookPushEvent::$PREF_TO_DISABLE_ALL;
			$user->setOption($prefName, $wgRequest->getCheck($prefName) ? '1' : '0');
		}
 		
		// Unfortunately, performs a second database lookup
		$fbUser = new FacebookUser($user);
		// Update the user with settings from Facebook
		$fbUser->updateFromFacebook();
		
		// Start the session if it's not already been started
		global $wgSessionStarted;
		if (!$wgSessionStarted) {
			wfSetupSession();
		}
		
		// Log the user in and store the new user as the global user object
		$user->setCookies();
		$wgUser = $user;
		
		/*
		 * Similar to what's done in LoginForm::authenticateUserData(). Load
		 * $wgUser now. This is necessary because loading $wgUser (say by
		 * calling getName()) calls the UserLoadFromSession hook, which
		 * potentially creates the user in the local database.
		 */
		$sessionUser = User::newFromSession();
		$sessionUser->load();
		
		// Allow custom form processing to store values since this form submission was successful.
		// This hook should not fail on invalid input, instead check the input using the SpecialConnect::createUser::validateForm hook above.
		wfRunHooks( 'SpecialConnect::createUser::postProcessForm', array( &$this ) );
		
		$user->addNewUserLogEntryAutoCreate();
		
		$this->isNewUser = true;
		$this->sendPage('successfulLoginView');
		
		wfProfileOut(__METHOD__);
	}
	
	/** 
	 * Actually add a user to the database. 
	 * Give it a User object that has been initialised with a name. 
	 * 
	 * This is a custom version of similar code in SpecialUserLogin's LoginForm with differences 
	 * due to the fact that this code doesn't require a password, etc. 
	 * 
	 * @param $u User object. 
	 * @param $autocreate boolean -- true if this is an autocreation via auth plugin 
	 * @return User object. 
	 * @private 
	 */ 
	protected function initUser( $u, $autocreate ) {
		global $wgAuth, $wgExternalAuthType;
		
		if ( $wgExternalAuthType ) {
			$u = ExternalUser::addUser( $u, $this->mPassword, $this->mEmail, $this->mRealName );
			if ( is_object( $u ) ) {
				$this->mExtUser = ExternalUser::newFromName( $this->mName );
			}
		} else {
			$u->addToDatabase();
		}
		
		// No passwords for Facebook accounts.
		/*
		if ( $wgAuth->allowPasswordChange() ) {
		      $u->setPassword( $this->mPassword );
		}
		*/
		if ( $this->mEmail )
			$u->setEmail( $this->mEmail );
		if ( $this->mRealName )
			$u->setRealName( $this->mRealName );
		$u->setToken();
		
		$wgAuth->initUser( $u, $autocreate );
		
		if ( is_object( $this->mExtUser ) ) {
			$this->mExtUser->linkToLocal( $u->getId() );
			$email = $this->mExtUser->getPref( 'emailaddress' );
			if ( $email && !$this->mEmail ) {
				$u->setEmail( $email );
			}
		}
		
		$wgAuth->updateUser($u);
		
		//$u->setOption( 'rememberpassword', $this->mRemember ? 1 : 0 );
		//$u->setOption( 'marketingallowed', $this->mMarketingOptIn ? 1 : 0 );
		$u->setOption('skinoverwrite', 1);
		$u->saveSettings();
		
		# Update user count
		$ssUpdate = new SiteStatsUpdate( 0, 0, 0, 0, 1 );
		$ssUpdate->doUpdate();
		
		return $u;
	}
	
	/**
	 * Attaches the Facebook ID to an existing wiki account. If the user does
	 * not exist, or the supplied password does not match, then an error page
	 * is sent. Otherwise, the accounts are matched in the database and the new
	 * user object is logged in.
	 *
	 * NOTE: This isn't used by Wikia and hasn't been tested with some of the new
	 * code. Does it handle setting push-preferences correctly?
	 */
	protected function attachUser($fbid, $name, $password, $updatePrefs) {
		global $wgOut, $wgUser;
		wfProfileIn(__METHOD__);
		
		// The user must be logged into Facebook before choosing a wiki username
		if ( !$fbid ) {
			wfDebug("Facebook: aborting in attachUser(): no Facebook ID was reported.\n");
			$wgOut->showErrorPage( 'facebook-error', 'facebook-errortext' );
			return false;
		}
		// Look up the user by their name
		$user = new FacebookUser(User::newFromName($name, 'creatable' ));
		if (!$user || !$user->checkPassword($password)) {
			$this->sendPage('chooseNameFormView', 'wrongpassword');
			return false;
		}
		
		// Attach the user to their Facebook account in the database
		FacebookDB::addFacebookID($user, $fbid);
		
		// Update the user with settings from Facebook
		if (count($updatePrefs)) {
			foreach ($updatePrefs as $option) {
				$user->setOption("Facebookupdate-on-login-$option", '1');
			}
		}
		$user->updateFromFacebook();
		
		// Setup the session
		global $wgSessionStarted;
		if (!$wgSessionStarted) {
			wfSetupSession();
		}
		
		// Log the user in and store the new user as the global user object
		$user->setCookies();
		$wgUser = $user;
		
		// Similar to what's done in LoginForm::authenticateUserData().
		// Load $wgUser now. This is necessary because loading $wgUser (say
		// by calling getName()) calls the UserLoadFromSession hook, which
		// potentially creates the user in the local database.
		$sessionUser = User::newFromSession();
		$sessionUser->load();
		
		// User has been successfully attached and logged in
		wfRunHooks( 'SpecialConnect::userAttached', array( &$this ) );
		$this->sendPage('displaySuccessAttachingView');
		wfProfileOut(__METHOD__);
		return true;
	}

	/**
	 * Generates a unique username for a wiki account based on the prefix specified
	 * in the message 'facebook-usernameprefix'. The number appended is equal to
	 * the number of Facebook Connect to user ID associations in the user_fbconnect
	 * table, so quite a few numbers will be skipped. However, this approach is
	 * more scalable. For smaller wiki installations, uncomment the line $i = 1 to
	 * have consecutive usernames starting at 1.
	 */
	public function generateUserName() {
		// Because $i is incremented the first time through the while loop
		$i = FacebookDB::countUsers();
		#$i = 1; // This is the DUMB WAY to do this for large databases
		while ($i < PHP_INT_MAX) {
			$name = $this->userNamePrefix . $i;
			if (FacebookUser::userNameOK($name)) {
				return $name;
			}
			++$i;
		}
		return $prefix;
	}
	
	/**
	 * Tests whether the name is OK to use as a user name.
	 *
	public function userNameOK ($name) {
		global $wgReservedUsernames;
		
		$name = trim( $name );
		
		if ( empty( $name ) ) {
			return false;
		}
		
		$u = User::newFromName( $name, 'creatable' );
		if ( !is_object( $u ) ) {
			return false;
		}
		
		if ( !empty($wgReservedUsernames) && in_array($name, $wgReservedUsernames) ) {
			return false;
		}
		
		$mExtUser = ExternalUser::newFromName( $name );
		if ( is_object( $mExtUser ) && ( 0 != $mExtUser->getId() ) ) {
			return false;
		} elseif ( 0 != $u->idForName( true ) ) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Check to see if the user can create a Facebook-linked account.
	 */
	function checkCreateAccount() {
		global $wgUser, $facebook;
		// Response object to send return to the client
		$response = new AjaxResponse();
		
		$fb_user = $facebook->getUser();
		if (empty($fb_user)) {
			$response->addText(json_encode(array(
				'status' => 'error',
				'code' => 1,
				'message' => 'User is not logged into Facebook',
			)));
			return $response;
		}
		if(( (int)$wgUser->getId() ) != 0) {
			$response->addText(json_encode(array(
				'status' => 'error',
				'code' => 2,
				'message' => 'User is already logged into the wiki',
			)));
			return $response;
		}
		if( FacebookDB::getUser($fb_user) != null) {
			$response->addText(json_encode(array(
				'status' => 'error',
				'code' => 3,
				'message' => 'This Facebook account is connected to a different user',
			)));
			return $response;
		}
		if ( wfReadOnly() ) {
			$response->addText(json_encode(array(
				'status' => 'error',
				'code' => 4,
				'message' => 'The wiki is in read-only mode',
			)));
			return $response;
		}
		if ( $wgUser->isBlockedFromCreateAccount() ) {
			$response->addText(json_encode(array(
				'status' => 'error',
				'code' => 5,
				'message' => 'User does not have permission to create an account on this wiki',
			)));
			return $response;
		}
		$titleObj = SpecialPage::getTitleFor( 'Connect' );
		if ( count( $permErrors = $titleObj->getUserPermissionsErrors( 'createaccount', $wgUser, true ) ) > 0 ) {
			$response->addText(json_encode(array(
				'status' => 'error',
				'code' => 6,
				'message' => 'User does not have permission to create an account on this wiki',
			)));
			return $response;
		}
		
		// Success!
		$response->addText(json_encode(array('status' => 'ok')));
		return $response;
	}
	
	function ajaxModalChooseName() {
		global $wgRequest;
		wfLoadExtensionMessages('Facebook');
		$response = new AjaxResponse();
		
		$specialConnect = new SpecialConnect();
		$form = new ChooseNameForm($wgRequest, 'signup');
		$form->mainLoginForm( $specialConnect, '' );
		$tmpl = $form->getAjaxTemplate();
		$tmpl->set('isajax', true);
		ob_start();
		$tmpl->execute();
		$html = ob_get_clean();
		
		$response->addText( $html );
		return $response;
	}
}
