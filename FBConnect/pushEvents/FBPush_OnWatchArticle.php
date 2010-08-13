<?php
/**
 * @author Sean Colombo
 *
 * Pushes an item to Facebook News Feed when the user adds an article to their watchlist.
 */

global $wgExtensionMessagesFiles;
$pushDir = dirname(__FILE__) . '/';
$wgExtensionMessagesFiles['FBPush_OnWatchArticle'] = $pushDir . "FBPush_OnWatchArticle.i18n.php";

class FBPush_OnWatchArticle extends FBConnectPushEvent {
	protected $isAllowedUserPreferenceName = 'fbconnect-push-allow-OnWatchArticle'; // must correspond to an i18n message that is 'tog-[the value of the string on this line]'.
	static $messageName = 'fbconnect-msg-OnWatchArticle';
	
	public function init(){
		global $wgHooks;
		wfProfileIn(__METHOD__);

		$wgHooks['WatchArticleComplete'][] = 'FBPush_OnWatchArticle::onWatchArticleComplete';
		
		wfProfileOut(__METHOD__);
	}
	
	public function loadMsg() {
		wfProfileIn(__METHOD__);
				
		wfLoadExtensionMessages('FBPush_OnWatchArticle');
		
		wfProfileOut(__METHOD__);
	}
	
	public static function onWatchArticleComplete(&$user, &$article ){
		global $wgContentNamespaces, $wgSitename;
		wfProfileIn(__METHOD__); 
		
		if( $article->getTitle()->getFirstRevision() == null ) {
			return true;
		}
		
		$params = array(
			'$ARTICLENAME' => $article->getTitle()->getText(),
			'$WIKINAME' => $wgSitename,
			'$ARTICLE_URL' => $article->getTitle()->getFullURL()
		);
		
		self::pushEvent(self::$messageName, $params);
		
		wfProfileOut(__METHOD__);
		return true;
	}
}
