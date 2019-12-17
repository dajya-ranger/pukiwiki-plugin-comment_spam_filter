# pukiwiki-plugin-comment_spam_filter

PukiWiki用スパムフィルタ対応レスポンシブコメントプラグイン

- 暫定公開版です（[PukiWiki1.5.2](https://pukiwiki.osdn.jp/?PukiWiki/Download/1.5.2)で動作確認済）
- [PukiWiki1.5.2用スパムフィルタspam_filter.php（最新版）](https://dajya-ranger.com/sdm_downloads/spam-filter-library/)の導入が前提となります
- 次のPukiWiki標準プラグインをスパムフィルタ・レスポンシブデザイン・複数行入力に対応しています
	- comment（コメントプラグイン・Facebookコメントにも対応）
	- pcomment（コメントを別ページに記録するレス機能付きコメントプラグイン）
	- article（ページに掲示板を設置するプラグインで、commentの他にpcommentプラグインにも対応）
- 従来のプラグインをベースに開発しているので、プラグインの動作に互換があります（既に当該プラグインを使って運用しているサイトでも導入が可能です）
- URL入力欄を追加しているので、入力された投稿者の名前を入力されたURLのリンクとして編集します（URL入力欄を省略した場合は従来通りブラケット名によるリンク）
- URL入力欄によって作成するURLのリンクは別窓で開く（target="_blank"）リンクとして編集するため、自サイトの記事「[PukiWikiでリンクおよびページ添付画像・PDFを別窓（target=”_blank”）で開く！](https://dajya-ranger.com/pukiwiki/link-target-blank/)」での改造がされている前提です
- 入力されたコメントはempty（空）入力チェックと[Akismet](https://akismet.com/development/)フィルタで不正・スパムコメントを排除します
- commentプラグインはFacebookコメントとして利用することも可能です（必要な手順があるので、詳しくは自サイトの記事「[PukiWiki1.5.2をソーシャルメディアに接続してFacebookコメントを実装する！]」(https://dajya-ranger.com/pukiwiki/connect-social-media/)を参照して下さい）
- 動作サンプルは[PukiWiki配布テストサイト](https://pukiwiki.dajya-ranger.com)及び[太宰治真理教](https://dazai.dajya-ranger.com/)を参照して下さい
	- commentによるFacebookコメント→[FrontPage](https://pukiwiki.dajya-ranger.com)ページ
	- articleとpcommentを使った掲示板→[Ｑ＆Ａ掲示板](https://pukiwiki.dajya-ranger.com/?3ed3076714)ページ
	- articleとcommentを使った掲示板→[ダザイスト交流掲示板](https://dazai.dajya-ranger.com/?29ce31df85)ページ
- 設置と設定に関しては自サイトの記事「[コメントプラグインをスパムフィルタ対応にしてレスポンシブデザインにしてみた！](https://dajya-ranger.com/pukiwiki/setting-comment-responsive/)」（執筆予定）を参照して下さい
