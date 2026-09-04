=== Ship Modal ===
Contributors: shipinc
Requires at least: 4.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

HTML、画像、画像＋テキスト、ページャー、表示期間、表示頻度、計測に対応したWordPress用モーダル管理プラグインです。

現行リリースでは運用方針により、管理画面の内容入力を画像専用に一時制限しています。既存のHTML・テキスト・ページャーの保存データは削除せず、公開表示も維持します。HTML等の入力を再開する場合は、プラグイン読込前に `SHIP_MODAL_IMAGE_ONLY_MODE` を `false` として定義するか、`ship_modal_image_only_mode` フィルターで `false` を返してください。

== Description ==

管理者と編集者が編集できるモーダルを、全ページ・トップページ・投稿・指定ページ・ショートコードへ表示できます。
日別集計、CSV出力、GTM/GA4向けdataLayerイベント、GitHub mainブランチからの自動更新に対応します。
期間指定は画面上の表示制御です。開始前の内容もHTMLソースには含まれるため、公開前の機密情報には使用しないでください。

== Installation ==

1. `ship-modal`フォルダを`/wp-content/plugins/`へ配置します。
2. WordPress管理画面でShip Modalを有効化します。
3. 「モーダル」から内容と表示設定を登録します。

== Changelog ==

= 1.7.2 =
* 画像専用モードで画像フィールドがcontent_typeの値に左右されず表示されるよう修正。
* 画像フィールドが非画像フレームで意図せず残らないよう表示条件を整理。

= 1.7.1 =
* スマホ用画像の切り替え幅を767px以下に統一。
* 画像フィールドを「基本画像」「スマホ用画像」「共通alt」の順に整理。

= 1.7.0 =
* 基本画像のalt入力欄を追加。
* スマホ用画像を任意で設定できる2枠構成を追加。未設定時は基本画像を使用。
* 画像専用モードの管理画面案内を簡略化。

= 1.6.9 =
* 画像専用モードを導入。管理画面では画像とクリック先URLのみ入力可能にし、HTML・テキスト・ページャー等の既存データは保持。
* `SHIP_MODAL_IMAGE_ONLY_MODE` 定数と `ship_modal_image_only_mode` フィルターで、将来のHTML入力再開を可能にした。

= 1.6.8 =
* 管理者に加えて編集者もモーダルの作成・編集・公開・プレビュー・計測操作を行えるよう権限を拡張。
* GitHubの更新先をship-git-admin/ship-modalへ変更。

= 1.6.7 =
* ページャー矢印をCSS描画へ変更し、フォント差に左右されない中央配置へ修正。

= 1.6.6 =
* プレビュー・CSV・リセットURLのクエリ崩れを修正。
* 計測、期間判定、複数モーダル、ページャー編集、アクセシビリティを強化。
* GitHub mainブランチを優先する更新検出へ修正。
