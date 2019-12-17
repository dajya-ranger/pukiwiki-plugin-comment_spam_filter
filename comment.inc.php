<?php
// PukiWiki - Yet another WikiWikiWeb clone
// comment.inc.php
// Copyright
//   2002-2017 PukiWiki Development Team
//   2001-2002 Originally written by yu-ji
// License: GPL v2 or (at your option) any later version
//
// Comment plugin

/**
 * 修正情報
 *
 * PukiWiki comment.inc.php スパムフィルタ対応プラグイン
 *
 * ※PukiWiki1.5.2用spam_filter.phpスパムフィルタ最新版の導入が前提
 *
 * ---------------------------------------------------------------------------
 * 本プラグインではPukiWikiコメントにURL入力欄を追加することを可能にしている。
 * 従来プラグインでは名前が入力された場合に [[名前]] といったブラケット名での
 * リンクだったが、URL入力欄を追加することで、入力した名前をURLへのリンクに
 * 自動で編集するように仕様を拡張した。
 * この時、入力された名前のリンクとなるURLは外部リンクであることが想定される
 * ため、別窓で開く（target="_blank"）リンクとして編集する。
 * この「別窓で開くリンク」に関しては、本サイトの次の記事の改造がされている前提
 * とする。
 * 
 * PukiWikiでリンクおよびページ添付画像・PDFを別窓（target=”_blank”）で開く！
 * https://dajya-ranger.com/pukiwiki/link-target-blank/
 * ---------------------------------------------------------------------------
 *
 * @author		オヤジ戦隊ダジャレンジャー <red@dajya-ranger.com>
 * @copyright	Copyright © 2019, dajya-ranger.com
 * @link		https://dajya-ranger.com/pukiwiki/setting-comment-responsive/
 * @example		#comment([nofb][,nourl])
 * @example		@linkの内容を参照
 * @license		Apache License 2.0
 * @version		0.1.0
 * @since 		0.1.0 2019/12/17 暫定初公開
 *
 */

define('PLUGIN_COMMENT_DIRECTION_DEFAULT', '1'); // 1: above 0: below
// レスポンシブデザインに対応したため従来プラグインの設定はコメントアウト
//define('PLUGIN_COMMENT_SIZE_MSG',  70);
//define('PLUGIN_COMMENT_SIZE_NAME', 15);

// Facebookコメント利用フラグ（≠1:PukiWikiコメント 1:Facebookコメント）
define('PLUGIN_COMMENT_USE_FACEBOOK', 1);
// Facebookコメント投稿数（設定より多い投稿数だと自動でスクロールバーが出る）
define('PLUGIN_COMMENT_POST_FACEBOOK', 5);
// PukiWikiコメントURL入力欄利用フラグ（≠1:不使用 1:使用・名前リンクに使用）
define('PLUGIN_COMMENT_USE_URL', 1);
// PukiWikiコメントURL入力キャプション（プレースホルダ）
define('PLUGIN_COMMENT_CAPTION_URL',  'URL:');
// PukiWikiコメント行数（1:従来プラグインと動作互換 2以上で複数行入力対応）
define('PLUGIN_COMMENT_SIZE_ROW', 1);

// ----
define('PLUGIN_COMMENT_FORMAT_MSG',  '$msg');
define('PLUGIN_COMMENT_FORMAT_NAME', '[[$name]]');
define('PLUGIN_COMMENT_FORMAT_NOW',  '&new{$now};');
define('PLUGIN_COMMENT_FORMAT_STRING', "\x08MSG\x08 -- \x08NAME\x08 \x08NOW\x08");

function plugin_comment_init() {
	// スパムフィルタ読み込み
	require_once(LIB_DIR . 'spam_filter.php');
}

function plugin_comment_action()
{
	global $vars, $now, $_title_updated, $_no_name;
	global $_msg_comment_collided, $_title_comment_collided;
	global $_comment_plugin_fail_msg;

	if (PKWK_READONLY) die_message('PKWK_READONLY prohibits editing');

	if (! isset($vars['msg'])) return array('msg'=>'', 'body'=>''); // Do nothing

	// 複数行コメント入力対応
	if (PLUGIN_COMMENT_SIZE_ROW == 1) {
		// 1行コメントの場合は改行を削除する
		$vars['msg'] = str_replace(PHP_EOL, '', $vars['msg']);
	} else {
		// 複数行コメントの場合は改行を&br;に置き換える
		$vars['msg'] = str_replace(PHP_EOL, '&br;', rtrim($vars['msg']));
		$vars['msg'] .= '&br;';
	}
	$head = '';
	$match = array();
	if (preg_match('/^(-{1,2})-*\s*(.*)/', $vars['msg'], $match)) {
		$head        = & $match[1];
		$vars['msg'] = & $match[2];
	}
	if ($vars['msg'] == '') return array('msg'=>'', 'body'=>''); // Do nothing
	$comment  = str_replace('$msg', $vars['msg'], PLUGIN_COMMENT_FORMAT_MSG);
	if(isset($vars['name']) || ($vars['nodate'] != '1')) {
		$_name = (! isset($vars['name']) || $vars['name'] == '') ? $_no_name : $vars['name'];
		// 名前リンク編集
		if (isset($vars['use_url']) && $vars['use_url'] == '1') {
			// URL入力欄がある場合
			if (isset($vars['url']) && ($vars['url'] != '') && ($_name != '')) {
				// URLと名前が入力されている場合は外部リンクにする
				$_name = '[[' . $_name . '>>' . $vars['url'] . ']]';
			}
		} else {
			// 従来通り名前のみをブラケットリンクにする
			$_name = ($_name == '') ? '' : str_replace('$name', $_name, PLUGIN_COMMENT_FORMAT_NAME);
		}
		$_now  = ($vars['nodate'] == '1') ? '' :
			str_replace('$now', $now, PLUGIN_COMMENT_FORMAT_NOW);
		$comment = str_replace("\x08MSG\x08",  $comment, PLUGIN_COMMENT_FORMAT_STRING);
		$comment = str_replace("\x08NAME\x08", $_name, $comment);
		$comment = str_replace("\x08NOW\x08",  $_now,  $comment);
	}
	$comment = '-' . $head . ' ' . $comment;

	$postdata    = '';
	$comment_no  = 0;
	$above       = (isset($vars['above']) && $vars['above'] == '1');
	$comment_added = FALSE;
	foreach (get_source($vars['refer']) as $line) {
		if (! $above) $postdata .= $line;
		if (preg_match('/^#comment/i', $line) && $comment_no++ == $vars['comment_no']) {
			$comment_added = TRUE;
			if ($above) {
				$postdata = rtrim($postdata) . "\n" .
					$comment . "\n" .
					"\n";  // Insert one blank line above #commment, to avoid indentation
			} else {
				$postdata = rtrim($postdata) . "\n" .
					$comment . "\n";
			}
		}
		if ($above) $postdata .= $line;
	}
	$title = $_title_updated;
	$body = '';
	if ($comment_added) {
		// new comment added
		if (md5(get_source($vars['refer'], TRUE, TRUE)) !== $vars['digest']) {
			$title = $_title_comment_collided;
			$body  = $_msg_comment_collided . make_pagelink($vars['refer']);
		}
		page_write($vars['refer'], $postdata);
	} else {
		// failed to add the comment
		$title = $_title_comment_collided;
		$body  = $_comment_plugin_fail_msg . make_pagelink($vars['refer']);
	}

	/* 
	-------------------------------------------------------------------------
	2019/12/16 オヤジ戦隊ダジャレンジャー <red@dajya-ranger.com>

	「コメントの挿入」ボタン押下後に「～を更新しました」ページになる。
	このまま（従来仕様）では、知らないユーザがページを再読み込みすると
	「更新の衝突を検知しました」となってしまう問題がある。
	→F5キーで更新したりしたときに、同じメッセージが再度書かれる
	そこで次のコードをコメントアウトし、再度自動でページを読み直すように修正。

	参照：https://pukiwiki.osdn.jp/dev/?BugTrack/2239#r184daf0
	-------------------------------------------------------------------------
	$retvars['msg']  = $title;
	$retvars['body'] = $body;
	$vars['page'] = $vars['refer'];

	return $retvars;
	-------------------------------------------------------------------------
	*/
	pkwk_headers_sent();
	header('Location: ' . get_page_uri($vars['refer'], PKWK_URI_ROOT));
	exit;
	// 	---------------------------------------------------------------------

}

function plugin_comment_convert()
{
	global $vars, $digest, $_btn_comment, $_btn_name, $_msg_comment;
	static $numbers = array();
	// 定数展開用
	$_ = function($const){return $const;};

	if (PKWK_READONLY) return ''; // Show nothing

	if( $_SERVER['REQUEST_METHOD'] == 'POST' ){
		// フォームがポスト（ボタン押下）されたタイミングでスパムフィルタをキックする
		spam_filter('comment');
	}

	$page = $vars['page'];
	if (! isset($numbers[$page])) $numbers[$page] = 0;
	$comment_no = $numbers[$page]++;

	$options = func_num_args() ? func_get_args() : array();
	// Facebookコメント不使用指定の場合はそれに従う
	$use_fb = in_array('nofb', $options) ? FALSE : (PLUGIN_COMMENT_USE_FACEBOOK == 1);
	// PukiWikiコメントURL入力欄不使用指定の場合はそれに従う
	$use_url = in_array('nourl', $options) ? 0 : PLUGIN_COMMENT_USE_URL;

	if (in_array('noname', $options)) {
		$nametags = '';
	} else {
		$nametags = '<input type="text" name="name" id="_p_comment_name_' .
			$comment_no . '" placeholder="' . $_btn_name . '" /><br />' . "\n";
		// URL入力利用チェック
		if ($use_url != 0) {
			// URL入力欄を使用する場合
			$nametags .= '<input type="text" name="url" id="_p_comment_name_' .
			$comment_no . '" placeholder="' . PLUGIN_COMMENT_CAPTION_URL . 
			'" /><br />' . "\n";
		}
	}
	$nodate = in_array('nodate', $options) ? '1' : '0';
	$above  = in_array('above',  $options) ? '1' :
		(in_array('below', $options) ? '0' : PLUGIN_COMMENT_DIRECTION_DEFAULT);

	$script = get_page_uri($page);
	$s_page = htmlsc($page);

	// コメント複数行対応
	if (PLUGIN_COMMENT_SIZE_ROW > 1) {
		// 複数行
		$comment = '<textarea name="msg" id="_p_comment_comment_' . $comment_no . 
			'"' . ' rows="' . PLUGIN_COMMENT_SIZE_ROW . '" placeholder="' . 
			$_msg_comment . '"></textarea><br />';
	} else {
		// 1行
		$comment = '<input type="text" name="msg" placeholder="' .
			$_msg_comment . '" /><br />';
	}

	// コメント実装部HTML
	if ($use_fb) {
		// Facebookコメント
		// パーマリンクセット
		$permalink = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$string = <<<EOD
<br />
<!-- Facebookコメント実装部 -->
<div class="fb-comments" data-href="$permalink" data-width="100%" data-numposts="{$_(PLUGIN_COMMENT_POST_FACEBOOK)}"></div>
EOD;
	} else {
		// PukiWikiコメント
$string = <<<EOD
<br />
<span class="comment">
<form action="$script" method="post">
 <div>
  <input type="hidden" name="plugin" value="comment" />
  <input type="hidden" name="refer"  value="$s_page" />
  <input type="hidden" name="comment_no" value="$comment_no" />
  <input type="hidden" name="nodate" value="$nodate" />
  <input type="hidden" name="above"  value="$above" />
  <input type="hidden" name="digest" value="$digest" />
  <input type="hidden" name="use_url" value="$use_url" />
  $nametags
  $comment
  <input type="submit" name="comment" value="$_btn_comment" />
 </div>
</form>
</span>
<br />
EOD;
	}

	return $string;
}
