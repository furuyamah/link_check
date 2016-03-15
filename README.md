# link_check
link check tool for mobile web.

フィーチャーフォン対応リンクチェックスクリプト

## Usage
使い方 : ./link_check.php [URL] [User agent]
[user agent] s: Android / a: iPhone / i: Docomo FP / e: AU FP / y: SoftBank FP";

指定されたURLの外部ドメインは再帰的には辿らない
一度チェックしたURLはチェックしない
指定された深さ以下はチェックしない
チェックした結果を各ページ毎に存在するリンク切れURLのリストという形でメールする
