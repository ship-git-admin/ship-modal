=== Ship Modal ===
Contributors: shipinc
Requires at least: 4.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

HTML、画像、画像＋テキスト、ページャー、表示期間、表示頻度、計測に対応したWordPress用モーダル管理プラグインです。

== Description ==

管理者だけが編集できるモーダルを、全ページ・トップページ・投稿・指定ページ・ショートコードへ表示できます。
日別集計、CSV出力、GTM/GA4向けdataLayerイベント、GitHub mainブランチからの自動更新に対応します。
期間指定は画面上の表示制御です。開始前の内容もHTMLソースには含まれるため、公開前の機密情報には使用しないでください。

== Installation ==

1. `ship-modal`フォルダを`/wp-content/plugins/`へ配置します。
2. WordPress管理画面でShip Modalを有効化します。
3. 「モーダル」から内容と表示設定を登録します。

== Changelog ==

= 1.6.6 =
* プレビュー・CSV・リセットURLのクエリ崩れを修正。
* 計測、期間判定、複数モーダル、ページャー編集、アクセシビリティを強化。
* GitHub mainブランチを優先する更新検出へ修正。
