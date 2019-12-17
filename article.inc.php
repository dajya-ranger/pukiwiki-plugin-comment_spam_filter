<?php
// PukiWiki - Yet another WikiWikiWeb clone
// article.inc.php
// Copyright
//   2002-2017 PukiWiki Development Team
//   2002      Originally written by OKAWARA,Satoshi <kawara@dml.co.jp>
//             http://www.dml.co.jp/~kawara/pukiwiki/pukiwiki.php
// License: GPL v2 or (at your option) any later version
//
// article: BBS-like plugin

 /*
 メッセージを変更したい場合はLANGUAGEファイルに下記の値を追加してからご使用ください
	$_btn_name    = 'お名前';
	$_btn_article = '記事の投稿';
	$_btn_subject = '題名: ';

 ※$_btn_nameはcommentプラグインで既に設定されている場合があります

 投稿内容の自動メール転送機能をご使用になりたい場合は
 -投稿内容のメール自動配信
 -投稿内容のメール自動配信先
 を設定の上、ご使用ください。

 */

/**
 * 修正情報
 *
 * PukiWiki article.inc.php スパムフィルタ対応プラグイン
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
 * @example		@linkの内容を参照
 * @license		Apache License 2.0
 * @version		0.1.0
 * @since 		0.1.0 2019/12/17 暫定初公開
 *
 */

// レスポンシブデザインに対応したため従来プラグインの設定はコメントアウト
//define('PLUGIN_ARTICLE_COLS',	70); // テキストエリアのカラム数
//define('PLUGIN_ARTICLE_NAME_COLS',	24); // 名前テキストエリアのカラム数
//define('PLUGIN_ARTICLE_SUBJECT_COLS',	60); // 題名テキストエリアのカラム数

define('PLUGIN_ARTICLE_ROWS',	 5); // テキストエリアの行数
define('PLUGIN_ARTICLE_NAME_FORMAT',	'[[$name]]'); // 名前の挿入フォーマット
define('PLUGIN_ARTICLE_SUBJECT_FORMAT',	'**$subject'); // 題名の挿入フォーマット

define('PLUGIN_ARTICLE_INS',	0); // 挿入する位置 1:欄の前 0:欄の後
define('PLUGIN_ARTICLE_COMMENT',	1); // 書き込みの下に一行コメントを入れる 1:入れる 0:入れない
define('PLUGIN_ARTICLE_AUTO_BR',	0); // 改行を自動的変換 1:する 0:しない

define('PLUGIN_ARTICLE_MAIL_AUTO_SEND',	0); // 投稿内容のメール自動配信 1:する 0:しない
define('PLUGIN_ARTICLE_MAIL_FROM',	''); // 投稿内容のメール送信時の送信者メールアドレス
define('PLUGIN_ARTICLE_MAIL_SUBJECT_PREFIX', "[someone's PukiWiki]"); // 投稿内容のメール送信時の題名

// URL入力欄利用フラグ（≠1:不使用 1:使用・名前リンクに使用）
define('PLUGIN_ARTICLE_USE_URL', 1);
// URL入力キャプション（プレースホルダ）
define('PLUGIN_ARTICLE_CAPTION_URL',  'URL:');
// コメントプラグイン（'#comment'か'#pcomment'を指定）
define('PLUGIN_ARTICLE_COMMENT_PLUGIN', '#comment');
// pcomment利用時のコメント保存ページ（ページ・題名ごとに別ページにする）
define('PLUGIN_ARTICLE_PCOMMENT_PAGE', 'Comments/%s/%s');
// pcomment利用時の引数（カンマ区切りで指定）
define('PLUGIN_ARTICLE_PCOMMENT_ARGS', 'reply');


// 投稿内容のメール自動配信先
global $_plugin_article_mailto;
$_plugin_article_mailto = array (
	''
);

function plugin_article_init() {
	// スパムフィルタ読み込み
	require_once(LIB_DIR . 'spam_filter.php');
}

function plugin_article_action()
{
	global $post, $vars, $cols, $rows, $now;
	global $_title_collided, $_msg_collided, $_title_updated;
	global $_plugin_article_mailto, $_no_subject, $_no_name;
	global $_msg_article_mail_sender, $_msg_article_mail_page;

	$script = get_base_uri();
	if (PKWK_READONLY) die_message('PKWK_READONLY prohibits editing');

	if ($post['msg'] == '')
		return array('msg'=>'','body'=>'');

	$name = ($post['name'] == '') ? $_no_name : $post['name'];
	// 名前リンク編集
	if (isset($post['use_url']) && $post['use_url'] == '1') {
		// URL入力欄がある場合
		if (isset($post['url']) && ($post['url'] != '') && ($name != '')) {
			// URLと名前が入力されている場合は外部リンクにする
			$name = '[[' . $name . '>>' . $post['url'] . ']]';
		}
	} else {
		// 従来通り名前のみをブラケットリンクにする
		$name = ($name == '') ? '' : str_replace('$name', $name, PLUGIN_ARTICLE_NAME_FORMAT);
	}


	$subject = ($post['subject'] == '') ? $_no_subject : $post['subject'];
	$subject = ($subject == '') ? '' : str_replace('$subject', $subject, PLUGIN_ARTICLE_SUBJECT_FORMAT);
	$article  = $subject . ' -- ' . $name . ' &new{' . $now . '};' . "\n";

	$msg = rtrim($post['msg']);
	if (PLUGIN_ARTICLE_AUTO_BR) {
		//改行の取り扱いはけっこう厄介。特にURLが絡んだときは…
		//コメント行、整形済み行には~をつけないように arino
		$msg = join("\n", preg_replace('/^(?!\/\/)(?!\s)(.*)$/', '$1~', explode("\n", $msg)));
	}
	$article .= $msg . "\n\n" . '//';

	// コメントプラグイン
	if (PLUGIN_ARTICLE_COMMENT) {
		if (PLUGIN_ARTICLE_COMMENT_PLUGIN == '#pcomment') {
			// pcommentプラグイン指定の場合
			// コメント保存ページ名を編集する（Comments/現在のページ名/題名）
			$comment_page = sprintf(PLUGIN_ARTICLE_PCOMMENT_PAGE, $post['refer'], $post['subject']);
			if ($post['use_url'] == '1') {
				// URL入力欄使用
				$add_arg = (PLUGIN_ARTICLE_PCOMMENT_ARGS == '') ? '(' . $comment_page . ')' : '(' . $comment_page . ',' . PLUGIN_ARTICLE_PCOMMENT_ARGS . ')';
			} else {
				// URL入力欄不使用
				$add_arg = (PLUGIN_ARTICLE_PCOMMENT_ARGS == '') ? '(' . $comment_page . ')' : '(' . $comment_page . ',' . PLUGIN_ARTICLE_PCOMMENT_ARGS . ',nourl)';
			}
		} else {
			// commentプラグイン指定の場合（デフォルト）
			$add_arg = ($post['use_url'] == '1') ? '(nofb)' : '(nofb,nourl)';
		}
		// コメントプラグイン編集
		$article .= "\n\n" . PLUGIN_ARTICLE_COMMENT_PLUGIN . $add_arg ."\n";
	}

	$postdata = '';
	$postdata_old  = get_source($post['refer']);
	$article_no = 0;

	foreach($postdata_old as $line) {
		if (! PLUGIN_ARTICLE_INS) $postdata .= $line;
		if (preg_match('/^#article/i', $line)) {
			if ($article_no == $post['article_no'] && $post['msg'] != '')
				$postdata .= $article . "\n";
			++$article_no;
		}
		if (PLUGIN_ARTICLE_INS) $postdata .= $line;
	}

	$postdata_input = $article . "\n";
	$body = '';

	if (md5(get_source($post['refer'], TRUE, TRUE)) !== $post['digest']) {
		$title = $_title_collided;

		$body = $_msg_collided . "\n";

		$s_refer    = htmlsc($post['refer']);
		$s_digest   = htmlsc($post['digest']);
		$s_postdata = htmlsc($postdata_input);
		$body .= <<<EOD
<span class="comment">
<form action="$script?cmd=preview" method="post">
 <div>
  <input type="hidden" name="refer" value="$s_refer" />
  <input type="hidden" name="digest" value="$s_digest" />
  <textarea name="msg" rows="$rows" id="textarea" placeholder="$_msg_comment">$s_postdata</textarea><br />
 </div>
</form>
</span>
EOD;

	} else {
		page_write($post['refer'], trim($postdata));

		// 投稿内容のメール自動送信
		if (PLUGIN_ARTICLE_MAIL_AUTO_SEND) {
			$mailaddress = implode(',', $_plugin_article_mailto);
			$mailsubject = PLUGIN_ARTICLE_MAIL_SUBJECT_PREFIX . ' ' . str_replace('**', '', $subject);
			if ($post['name'])
				$mailsubject .= '/' . $post['name'];
			$mailsubject = mb_encode_mimeheader($mailsubject);

			$mailbody = $post['msg'];
			$mailbody .= "\n\n" . '---' . "\n";
			$mailbody .= $_msg_article_mail_sender . $post['name'] . ' (' . $now . ')' . "\n";
			$mailbody .= $_msg_article_mail_page . $post['refer'] . "\n";
			$mailbody .= '   URL: ' . get_page_uri($post['refer'], PKWK_URI_ABSOLUTE) . "\n";
			$mailbody = mb_convert_encoding($mailbody, 'JIS');

			$mailaddheader = 'From: ' . PLUGIN_ARTICLE_MAIL_FROM;

			mail($mailaddress, $mailsubject, $mailbody, $mailaddheader);
		}

		$title = $_title_updated;
	}

	/* 
	-------------------------------------------------------------------------
	2019/12/16 オヤジ戦隊ダジャレンジャー <red@dajya-ranger.com>

	「記事の投稿」ボタン押下後に「～を更新しました」ページになる。
	このまま（従来仕様）では、知らないユーザがページを再読み込みすると
	「【更新の衝突】が起きました」となってしまう問題がある。
	そこで次のコードをコメントアウトし、再度自動でページを読み直すように修正。

	参照：https://pukiwiki.osdn.jp/dev/?BugTrack/2239#r184daf0
	-------------------------------------------------------------------------
	$retvars['msg'] = $title;
	$retvars['body'] = $body;

	$post['page'] = $post['refer'];
	$vars['page'] = $post['refer'];

	return $retvars;
	-------------------------------------------------------------------------
	*/
	pkwk_headers_sent();
	header('Location: ' . get_page_uri($post['refer'], PKWK_URI_ROOT));
	exit;
	// 	---------------------------------------------------------------------

}

function plugin_article_convert()
{
	global $vars, $digest;
	global $_btn_article, $_btn_name, $_btn_subject, $_msg_comment;
	static $numbers = array();
	// 定数展開用
	$_ = function($const){return $const;};

	$script = get_base_uri();
	if (PKWK_READONLY) return ''; // Show nothing

	if( $_SERVER['REQUEST_METHOD'] == 'POST' ){
		// フォームがポスト（ボタン押下）されたタイミングでスパムフィルタをキックする
		spam_filter('article');
	}

	if (! isset($numbers[$vars['page']])) $numbers[$vars['page']] = 0;

	$article_no = $numbers[$vars['page']]++;

	$s_page       = htmlsc($vars['page']);
	$s_digest     = htmlsc($digest);

	// URL入力欄
	if (PLUGIN_ARTICLE_USE_URL == 1) {
		$use_url = '1';
		$url = '<input type="text" name="url" placeholder="' . PLUGIN_ARTICLE_CAPTION_URL . '" /><br />';
	}

	$string = <<<EOD
<span class="comment">
<form action="$script" method="post">
 <div>
  <input type="hidden" name="article_no" value="$article_no" />
  <input type="hidden" name="plugin" value="article" />
  <input type="hidden" name="digest" value="$s_digest" />
  <input type="hidden" name="refer" value="$s_page" />
  <input type="hidden" name="use_url" value="$use_url" />
  <input type="text" name="subject" id="_p_article_subject_$article_no" placeholder="$_btn_subject" /><br />
  <input type="text" name="name" id="_p_article_name_$article_no" placeholder="$_btn_name" /><br />
  $url
  <textarea name="msg" rows="{$_(PLUGIN_ARTICLE_ROWS)}"></textarea><br />
  <input type="submit" name="article" value="$_btn_article" /><br />
 </div>
</form>
</span>
<br />
EOD;

	return $string;
}
