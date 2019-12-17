<?php
// PukiWiki - Yet another WikiWikiWeb clone
// pcomment.inc.php
// Copyright 2002-2017 PukiWiki Development Team
// License: GPL v2 or (at your option) any later version
//
// pcomment plugin - Show/Insert comments into specified (another) page

// Usage: #pcomment([page][,max][,options])
//
//   page -- An another page-name that holds comments
//           (default:PLUGIN_PCOMMENT_PAGE)
//   max  -- Max number of recent comments to show
//           (0:Show all, default:PLUGIN_PCOMMENT_NUM_COMMENTS)
//
// Options:
//   above -- Comments are listed above the #pcomment (added by chronological order)
//   below -- Comments are listed below the #pcomment (by reverse order)
//   reply -- Show radio buttons allow to specify where to reply

/**
 * 修正情報
 *
 * PukiWiki pcomment.inc.php スパムフィルタ対応プラグイン
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
 * @example		#pcomment([page][,max][,options][,nourl])
 * @example		@linkの内容を参照
 * @license		Apache License 2.0
 * @version		0.1.0
 * @since 		0.1.0 2019/12/17 暫定初公開
 *
 */

// Default recording page name (%s = $vars['page'] = original page name)
define('PLUGIN_PCOMMENT_PAGE', '[[Comments/%s]]');
define('PLUGIN_PCOMMENT_PAGE_COMPATIBLE', '[[コメント/%s]]'); // for backword compatible of 'ja' pcomment

define('PLUGIN_PCOMMENT_NUM_COMMENTS',     10); // Default 'latest N posts'
define('PLUGIN_PCOMMENT_DIRECTION_DEFAULT', 1); // 1: above 0: below
// レスポンシブデザインに対応したため従来プラグインの設定はコメントアウト
//define('PLUGIN_PCOMMENT_SIZE_MSG',  70);
//define('PLUGIN_PCOMMENT_SIZE_NAME', 15);

// Auto log rotation
define('PLUGIN_PCOMMENT_AUTO_LOG', 0); // 0:off 1-N:number of comments per page

// Update recording page's timestamp instead of parent's page itself
define('PLUGIN_PCOMMENT_TIMESTAMP', 0);


// URL入力欄利用フラグ（≠1:不使用 1:使用・名前リンクに使用）
define('PLUGIN_PCOMMENT_USE_URL', 1);
// URL入力キャプション（プレースホルダ）
define('PLUGIN_PCOMMENT_CAPTION_URL',  'URL:');
// コメント行数（1:従来プラグインと動作互換 2以上で複数行入力対応）
define('PLUGIN_PCOMMENT_SIZE_ROW', 1);
// ラジオボタン新規コメントキャプション
define('PLUGIN_PCOMMENT_RADIO_NEW', '新規コメント追加');
// ラジオボタンレスコメントキャプション
define('PLUGIN_PCOMMENT_RADIO_RES', 'このコメントにレス');

// ----
define('PLUGIN_PCOMMENT_FORMAT_NAME',	'[[$name]]');
define('PLUGIN_PCOMMENT_FORMAT_MSG',	'$msg');
define('PLUGIN_PCOMMENT_FORMAT_NOW',	'&new{$now};');

// "\x01", "\x02", "\x03", and "\x08" are used just as markers
define('PLUGIN_PCOMMENT_FORMAT_STRING',
	"\x08" . 'MSG' . "\x08" . ' -- ' . "\x08" . 'NAME' . "\x08" . ' ' . "\x08" . 'DATE' . "\x08");

function plugin_pcomment_init() {
	// スパムフィルタ読み込み
	require_once(LIB_DIR . 'spam_filter.php');
}

function plugin_pcomment_action()
{
	global $vars;

	if (PKWK_READONLY) die_message('PKWK_READONLY prohibits editing');

	if (! isset($vars['msg']) || $vars['msg'] == '') return array();
	$refer = isset($vars['refer']) ? $vars['refer'] : '';

	$retval = plugin_pcomment_insert();
	if ($retval['collided']) {
		$vars['page'] = $refer;
		return $retval;
	}

	pkwk_headers_sent();
	header('Location: ' . get_page_uri($refer, PKWK_URI_ROOT));
	exit;
}

function plugin_pcomment_convert()
{
	global $vars;
	global $_pcmt_messages;

	$params = array(
		'noname'=>FALSE,
		'nodate'=>FALSE,
		'below' =>FALSE,
		'above' =>FALSE,
		'reply' =>FALSE,
		'nourl' =>FALSE,
		'_args' =>array()
	);

	foreach(func_get_args() as $arg)
		plugin_pcomment_check_arg($arg, $params);

	if( $_SERVER['REQUEST_METHOD'] == 'POST' ){
		// フォームがポスト（ボタン押下）されたタイミングでスパムフィルタをキックする
		spam_filter('pcomment');
	}

	$vars_page = isset($vars['page']) ? $vars['page'] : '';
	if (isset($params['_args'][0]) && $params['_args'][0] != '') {
		$page = $params['_args'][0];
	} else {
		$raw_vars_page = strip_bracket($vars_page);
		$page = sprintf(PLUGIN_PCOMMENT_PAGE, $raw_vars_page);
		$raw_page = strip_bracket($page);
		if (!is_page($raw_page)) {
			// If the page doesn't exist, search backward-compatible page
			// If only compatible page exists, set the page as comment target
			$page_compat = sprintf(PLUGIN_PCOMMENT_PAGE_COMPATIBLE, $raw_vars_page);
			if (is_page(strip_bracket($page_compat))) {
				$page = $page_compat;
			}
		}
	}
	$count = isset($params['_args'][1]) ? intval($params['_args'][1]) : 0;
	if ($count == 0) $count = PLUGIN_PCOMMENT_NUM_COMMENTS;

	$_page = get_fullname(strip_bracket($page), $vars_page);
	if (!is_pagename($_page))
		return sprintf($_pcmt_messages['err_pagename'], htmlsc($_page));

	$dir = PLUGIN_PCOMMENT_DIRECTION_DEFAULT;
	if ($params['below']) {
		$dir = 0;
	} elseif ($params['above']) {
		$dir = 1;
	}

	list($comments, $digest) = plugin_pcomment_get_comments($_page, $count, $dir, $params['reply']);

	if (PKWK_READONLY) {
		$form_start = $form = $form_end = '';
	} else {
		// Show a form

		if ($params['noname']) {
			$name = '';
		} else {
			$name = '<input type="text" name="name" placeholder="' . $_pcmt_messages['btn_name'] . '" /><br />';
		}

		// URL入力欄
		$use_url = PLUGIN_PCOMMENT_USE_URL;
		if ($params['nourl']) {
			// PukiWikiコメントURL入力欄不使用指定の場合はそれに従う
			$use_url = 0;
		}
		$url = ($use_url == 1) ? '<input type="text" name="url" placeholder="' . PLUGIN_PCOMMENT_CAPTION_URL . '" /><br />' : '';

		$radio   = $params['reply'] ?
			'<input type="radio" name="reply" value="0" tabindex="0" checked="checked" /> ' . PLUGIN_PCOMMENT_RADIO_NEW . '<br />' : '';

		// コメント複数行対応
		if (PLUGIN_PCOMMENT_SIZE_ROW > 1) {
			// 複数行
			$comment = '<textarea name="msg"  rows="' . 
				PLUGIN_PCOMMENT_SIZE_ROW . '" placeholder="' . 
				$_pcmt_messages['msg_comment'] . '"></textarea><br />';
		} else {
			// 1行
			$comment = '<input type="text" name="msg" placeholder="' .
				$_pcmt_messages['msg_comment'] . '" /><br />';
		}

		$s_page   = htmlsc($page);
		$s_refer  = htmlsc($vars_page);
		$s_nodate = htmlsc($params['nodate']);
		$s_count  = htmlsc($count);

		$form_start = '<form action="' . get_base_uri() . '" method="post">' . "\n";
		$form = <<<EOD
  <div>
  <input type="hidden" name="digest" value="$digest" />
  <input type="hidden" name="plugin" value="pcomment" />
  <input type="hidden" name="refer"  value="$s_refer" />
  <input type="hidden" name="page"   value="$s_page" />
  <input type="hidden" name="nodate" value="$s_nodate" />
  <input type="hidden" name="dir"    value="$dir" />
  <input type="hidden" name="count"  value="$count" />
  <input type="hidden" name="use_url" value="$use_url" />
  $radio $name $url $comment
  <input type="submit" value="{$_pcmt_messages['btn_comment']}" />
  </div>
EOD;
		$form_end = '</form>' . "\n";
	}

	if (! is_page($_page)) {
		$link   = make_pagelink($_page);
		$recent = $_pcmt_messages['msg_none'];
	} else {
		$msg    = ($_pcmt_messages['msg_all'] != '') ? $_pcmt_messages['msg_all'] : $_page;
		$link   = make_pagelink($_page, $msg);
		$recent = ! empty($count) ? sprintf($_pcmt_messages['msg_recent'], $count) : '';
	}

	if ($dir) {
		return '<div><span class="comment">' .
			'<p>' . $recent . ' ' . $link . '</p>' . "\n" .
			$form_start .
				$comments . "\n" .
				$form .
			$form_end .
			'</div></span>' . "\n";
	} else {
		return '<div><span class="comment">' .
			$form_start .
				$form .
				$comments. "\n" .
			$form_end .
			'<p>' . $recent . ' ' . $link . '</p>' . "\n" .
			'</div></span>' . "\n";
	}
}

function plugin_pcomment_insert()
{
	global $vars, $now, $_title_updated, $_no_name, $_pcmt_messages;

	$refer = isset($vars['refer']) ? $vars['refer'] : '';
	$page  = isset($vars['page'])  ? $vars['page']  : '';
	$page  = get_fullname($page, $refer);

	if (! is_pagename($page))
		return array(
			'msg' =>'Invalid page name',
			'body'=>'Cannot add comment' ,
			'collided'=>TRUE
		);

	check_editable($page, true, true);

	$ret = array('msg' => $_title_updated, 'collided' => FALSE);

	$msg = str_replace('$msg', rtrim($vars['msg']), PLUGIN_PCOMMENT_FORMAT_MSG);
	$name = (! isset($vars['name']) || $vars['name'] == '') ? $_no_name : $vars['name'];

	// 名前リンク編集
	if (isset($vars['use_url']) && $vars['use_url'] == '1') {
		// URL入力欄がある場合
		if (isset($vars['url']) && ($vars['url'] != '') && ($name != '')) {
			// URLと名前が入力されている場合は外部リンクにする
			$name = '[[' . $name . '>>' . $vars['url'] . ']]';
		}
	} else {
		// 従来通り名前のみをブラケットリンクにする
		$name = ($name == '') ? '' : str_replace('$name', $name, PLUGIN_PCOMMENT_FORMAT_NAME);
	}
	// 複数行対応
	if (PLUGIN_PCOMMENT_SIZE_ROW !== 1) {
		// 名前・日付・時刻の前に改行を追加
		$msg .= "&br;";
	}

	$date = (! isset($vars['nodate']) || $vars['nodate'] != '1') ?
		str_replace('$now', $now, PLUGIN_PCOMMENT_FORMAT_NOW) : '';
	if ($date != '' || $name != '') {
		$msg = str_replace("\x08" . 'MSG'  . "\x08", $msg,  PLUGIN_PCOMMENT_FORMAT_STRING);
		$msg = str_replace("\x08" . 'NAME' . "\x08", $name, $msg);
		$msg = str_replace("\x08" . 'DATE' . "\x08", $date, $msg);
	}

	$reply_hash = isset($vars['reply']) ? $vars['reply'] : '';
	if ($reply_hash || ! is_page($page)) {
		$msg = preg_replace('/^\-+/', '', $msg);
	}
	$msg = rtrim($msg);

	if (! is_page($page)) {
		$postdata = '[[' . htmlsc(strip_bracket($refer)) . ']]' . "\n\n" .
			'-' . $msg . "\n";
	} else {
		$postdata = get_source($page);
		$count    = count($postdata);

		$digest = isset($vars['digest']) ? $vars['digest'] : '';
		if (md5(join('', $postdata)) !== $digest) {
			$ret['msg']  = $_pcmt_messages['title_collided'];
			$ret['body'] = $_pcmt_messages['msg_collided'];
		}

		$start_position = 0;
		while ($start_position < $count) {
			if (preg_match('/^\-/', $postdata[$start_position])) break;
			++$start_position;
		}
		$end_position = $start_position;

		$dir = isset($vars['dir']) ? $vars['dir'] : '';

		// Find the comment to reply
		$level   = 1;
		$b_reply = FALSE;
		if ($reply_hash != '') {
			while ($end_position < $count) {
				$matches = array();
				if (preg_match('/^(\-{1,2})(?!\-)(.*)$/', $postdata[$end_position++], $matches)
					&& md5($matches[2]) === $reply_hash)
				{
					$b_reply = TRUE;
					$level   = strlen($matches[1]) + 1;

					while ($end_position < $count) {
						if (preg_match('/^(\-{1,3})(?!\-)/', $postdata[$end_position], $matches)
							&& strlen($matches[1]) < $level) break;
						++$end_position;
					}
					break;
				}
			}
		}

		if ($b_reply == FALSE)
			$end_position = ($dir == '0') ? $start_position : $count;

		// Insert new comment
		array_splice($postdata, $end_position, 0, str_repeat('-', $level) . $msg . "\n");

		if (PLUGIN_PCOMMENT_AUTO_LOG) {
			$_count = isset($vars['count']) ? $vars['count'] : '';
			plugin_pcomment_auto_log($page, $dir, $_count, $postdata);
		}

		$postdata = join('', $postdata);
	}
	page_write($page, $postdata, PLUGIN_PCOMMENT_TIMESTAMP);

	if (PLUGIN_PCOMMENT_TIMESTAMP) {
		if ($refer != '') pkwk_touch_file(get_filename($refer));
		put_lastmodified();
	}

	return $ret;
}

// Auto log rotation
function plugin_pcomment_auto_log($page, $dir, $count, & $postdata)
{
	if (! PLUGIN_PCOMMENT_AUTO_LOG) return;

	$keys = array_keys(preg_grep('/(?:^-(?!-).*$)/m', $postdata));
	if (count($keys) < (PLUGIN_PCOMMENT_AUTO_LOG + $count)) return;

	if ($dir) {
		// Top N comments (N = PLUGIN_PCOMMENT_AUTO_LOG)
		$old = array_splice($postdata, $keys[0], $keys[PLUGIN_PCOMMENT_AUTO_LOG] - $keys[0]);
	} else {
		// Bottom N comments
		$old = array_splice($postdata, $keys[count($keys) - PLUGIN_PCOMMENT_AUTO_LOG]);
	}

	// Decide new page name
	$i = 0;
	do {
		++$i;
		$_page = $page . '/' . $i;
	} while (is_page($_page));

	page_write($_page, '[[' . $page . ']]' . "\n\n" . join('', $old));

	// Recurse :)
	plugin_pcomment_auto_log($page, $dir, $count, $postdata);
}

// Check arguments
function plugin_pcomment_check_arg($val, & $params)
{
	if ($val != '') {
		$l_val = strtolower($val);
		foreach (array_keys($params) as $key) {
			if (strpos($key, $l_val) === 0) {
				$params[$key] = TRUE;
				return;
			}
		}
	}

	$params['_args'][] = $val;
}

function plugin_pcomment_get_comments($page, $count, $dir, $reply)
{
	global $_msg_pcomment_restrict;

	if (! check_readable($page, false, false))
		return array(str_replace('$1', $page, $_msg_pcomment_restrict));

	$reply = (! PKWK_READONLY && $reply); // Suprress radio-buttons

	$data = get_source($page);
	$data = preg_replace('/^#pcomment\(?.*/i', '', $data);	// Avoid eternal recurse

	if (! is_array($data)) return array('', 0);

	$digest = md5(join('', $data));

	// Get latest N comments
	$num  = $cnt     = 0;
	$cmts = $matches = array();
	if ($dir) $data = array_reverse($data);
	foreach ($data as $line) {
		if ($count > 0 && $dir && $cnt == $count) break;

		if (preg_match('/^(\-{1,2})(?!\-)(.+)$/', $line, $matches)) {
			if ($count > 0 && strlen($matches[1]) == 1 && ++$cnt > $count) break;

			// Ready for radio-buttons
			if ($reply) {
				++$num;
				$cmts[] = $matches[1] . "\x01" . $num . "\x02" .
					md5($matches[2]) . "\x03" . $matches[2] . "\n";
				continue;
			}
		}
		$cmts[] = $line;
	}
	$data = $cmts;
	if ($dir) $data = array_reverse($data);
	unset($cmts, $matches);

	// Remove lines before comments
	while (! empty($data) && substr($data[0], 0, 1) != '-')
		array_shift($data);

	$comments = convert_html($data);
	unset($data);

	// ラジオボタンレスコメントキャプション
	if (PLUGIN_PCOMMENT_SIZE_ROW !== 1) {
		$radio_res = PLUGIN_PCOMMENT_RADIO_RES . '<br />';
	}
	// Add radio buttons
	if ($reply)
		$comments = preg_replace('/<li>' . "\x01" . '(\d+)' . "\x02" . '(.*)' . "\x03" . '/',
			'<li class="pcmt"><input class="pcmt" type="radio" name="reply" value="$2" tabindex="$1" /> ' . $radio_res,
			$comments);

	return array($comments, $digest);
}
