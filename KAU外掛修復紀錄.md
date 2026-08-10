# KAU WordPress 外掛紀錄

最後更新：2026-07-02

## 現行架構（v2.x — 資料庫版）

WordPress.com 站台目前運作的是 **`kau-site-lite`** 外掛（雖然 slug 叫 lite，內容已是完整版）。

```
WordPress 後台
├── KAU Site（新外掛 v2.1.0）── 啟用
│   ├── 頁面內容（4 個頁面 HTML 存資料庫）
│   ├── 商品管理（CRUD，存 wp_options）
│   └── 最新情報（CRUD，存 wp_options）
└── kau-site（舊版 v2.1.0）── 停用、刪除失敗但無影響
```

**外掛目錄路徑**：`/wp-content/plugins/kau-site-lite/`
- `kau-site.php`（主程式，~50KB）
- `assets/`（73 個檔案：字體、SVG icons、JS、cms-content.js）

## 資料儲存位置

| 資料 | 位置 |
|------|------|
| 4 個頁面 HTML | `wp_options.kau_site_pages_v2` |
| 商品 / 新聞 / global 等結構化資料 | `wp_options.kau_site_data_v2` |
| 字體、CSS、JS、SVG 圖示 | 外掛資料夾 `assets/` |
| 上傳的商品圖、Hero 圖 | WordPress 媒體庫 |

## REST API Endpoints

| Endpoint | 方法 | 用途 |
|----------|------|------|
| `/wp-json/kau-site/v1/data` | GET | cms-content.js 抓取 site.json 資料（公開） |
| `/wp-json/kau-site/v1/data` | POST | 推送完整 site.json（需 edit_theme_options） |
| `/wp-json/kau-site/v1/import` | POST | 推單一頁面 HTML（key=home/about/products/news, html=...） |
| `/wp-json/kau-site/v1/asset` | POST | 寫入檔案到外掛資料夾（name, data=base64, sub=資料夾名） |

## 認證

- **使用者**：`wroughthomeservice`（顯示名 KAU，email vul3xu4503@gmail.com）
- **Application Password**（可作廢時隨時重建）：在 wp-admin/profile.php 建立
- 目前可用 password 不在這份檔案保存（每次 reset 後失效，需重建）

## 部署流程（將來改 PHP 時）

1. 編輯 `D:\Akiu\KAU\KAU-offline\wordpress-plugin\kau-site\kau-site.php`
2. 打包輕量版（不含 assets）：
   ```powershell
   $folder = "$env:TEMP\kau-lite-pack"
   New-Item -ItemType Directory -Path $folder -Force | Out-Null
   Copy-Item "D:\Akiu\KAU\KAU-offline\wordpress-plugin\kau-site\kau-site.php" -Destination (Join-Path $folder "kau-site.php")
   Compress-Archive -Path (Join-Path $folder "*") -DestinationPath "D:\Akiu\KAU\KAU-offline\wordpress-plugin\kau-site-lite.zip" -Force
   ```
3. WordPress 後台 → 外掛 → 上傳外掛 → 取代並啟用
4. 若 WordPress.com 上傳建立新 slug（kau-site-lite-1 之類），用瀏覽器 REST 切換啟用狀態：
   ```js
   var nonce = window.wpApiSettings.nonce;
   fetch('/wp-json/wp/v2/plugins/{old_slug}', {method:'POST', credentials:'include', headers:{'Content-Type':'application/json','X-WP-Nonce':nonce}, body: JSON.stringify({status:'inactive'}) });
   fetch('/wp-json/wp/v2/plugins/{new_slug}', {method:'POST', credentials:'include', headers:{'Content-Type':'application/json','X-WP-Nonce':nonce}, body: JSON.stringify({status:'active'}) });
   ```

## HTML 來源備份

原始 HTML 仍存於：
```
D:\Akiu\KAU\KAU-offline\wordpress-plugin\kau-original-site-editor\static\
├── home.html, about.html, products.html, news.html
├── assets/ (73 個檔案)
├── cms-content.js
└── content/site.json
```

**注意**：HTML 推到資料庫時，`src="cms-content.js"` 改寫為 `src="assets/cms-content.js"`（因為 endpoint 限制不能寫到外掛根目錄）。

## 已知問題 / 限制

1. **WordPress.com 無 SFTP**（非 Business Plan）→ 無法直接管檔案，只能用上傳外掛 + REST API
2. **Application Password 不能操作個別外掛**（list 可、get/POST/DELETE single 404）→ 需用瀏覽器 session
3. **每次「無法移除目前版本外掛」上傳失敗** → 會建立 `-1` 後綴的新資料夾。需透過瀏覽器 session REST 切換啟用狀態。
4. **WordPress.com 重設網站** = 砍掉所有外掛、文章、媒體（但保留使用者帳號）

## 歷史紀錄

### 2026-08-10 v2.3.19 後台 UI 改善（已部署）

- **Sticky 儲存列**（首頁 / 會社概要 / 全域設定 三個表單頁共用 `kau_site_admin_savebar()`）：
  儲存按鈕固定在視窗底部，不用再捲到頁尾；含「全部展開 / 全部收合」與
  「有尚未儲存的變更」提示（表單 input/change/新增列/刪列時亮起），
  未儲存就離開會跳 beforeunload 確認，送出後解除。
- **列項目欄位對齊**：row-grid 內 label 改 110px 固定標籤欄 + 輸入欄（沿革 / 公司概要 / 品牌原則等），
  server 端與 JS 新增列共用同一 markup 所以一體適用。
- **會社概要頁去重複**：原本自帶一份跟共用函式幾乎相同的 inline <style>，改呼叫共用
  `kau_site_admin_accordion_styles()`（消掉樣式分岔，之後只要改一處）。
- **最新情報頁**：類別改 badge 膠囊（與商品管理一致）；目前注目記事列米金色底＋實心 ★（disabled），
  其他列顯示 ☆ 可點設為注目；編輯中列淡藍底＋「（編輯中）」標記；刪除 confirm 帶記事標題；
  編輯狀態下側欄提供「取消編輯」連結。
- 部署後實測：展開/收合 7/7、dirty 提示 hidden→visible、會社概要存檔往返資料無損、
  前台四頁輸出正常（editorTrash=0、asset 指向 2319）。

**部署與清理**：`kau-site-lite-2318`（停用，rollback）→ `kau-site-lite-2319`（現行 active）；
`kau-site-lite-2317` 已移除（REST DELETE 回 500 但條目消失，與歷來行為一致）。

### 2026-08-10 v2.3.18 媒體庫 modal 烤進 DB 清除（已部署）

全站健檢發現：訪客 HTML 裡帶著 wp.media 媒體庫 modal 的整包 markup
（home 44 / about 23 / products 24 處 `data-kau-ve-media-id`、含 attachment-preview 縮圖清單），
首頁因此多 39KB，且 attachment 縮圖引用造成一個 404（Home-furniture_One-Tone_2-300x225.png）。

機制：編輯模式開過媒體庫後，wp.media 會把 `<div id="__wp-uploader-id-N" class="supports-drag-drop">`
（內含 `<div id="wp-media-modal" tabindex="0" class="media-modal wp-core-ui" role="dialog">`）掛在 body 上。
cleanForSave 沒有移除這批節點；PHP 端 `kau_site_strip_wp_pollution` 雖有 media-modal 規則，
但 regex 假設 `role="dialog"` 在 `class` 之前，實際 markup 屬性順序不同 → 永遠 match 不到。

- cleanForSave 新增 DOM 移除：`.supports-drag-drop, .media-modal, .media-modal-backdrop, [id^="__wp-uploader-id"]`（防再累積）
- PHP 端改用屬性順序無關的遞迴 regex（named group `kau_mm` 平衡巢狀 div），輸出時即時清掉 DB 既有殘留
- 部署後在編輯模式對四頁各存檔一次，DB 洗淨：home 165→127KB、about 112→95KB、products 121→103KB、news 93→87KB

驗證：本機 PHP 8.4 對線上四頁 HTML 跑 regex（modal token 全歸零、div 巢狀平衡不變、無內容遺失）；
部署後四頁 editorTrash=0、19 個 asset 全 200、編輯器改字→儲存→訪客頁往返測試通過。

已知無害噪音（不修）：每頁載入會 fetch `.image-slots.state.json` 得到 403 —— 這是 image-slot
元件（omelette scaffold，hero/商品卡圖片都靠它）內建的 sidecar 讀取，正式站上拿不到而已。

**部署與清理**：線上資料夾 `kau-site-lite-2317`（停用，留作 rollback）→ `kau-site-lite-2318`（現行 active）。

### 2026-08-07 v2.3.17 商品列表欄位對齊（已部署）

分類／價格／精選三欄的標題靠左、內容卻偏右（badge 與星號在欄位裡各自置中），
改成標題與內容一起置中。實測三欄的標題中心與內容中心偏移都是 0px。

### 2026-08-07 v2.3.16 商品管理後台介面重做（已部署）

原本整頁靠 inline style 拼出來：邊框顏色兩套、縮圖 48px、價格空值顯示成孤零零的「¥」、
精選只能進編輯表單改、表單是一長串沒有分組的 `<p>`。

- 列表：搜尋框（即時過濾名稱／描述／分類／標籤）＋ 分類與「首頁精選」快篩 chips
- 每列：56px 縮圖、商品名可點進編輯、分類做成 badge、特色標籤與多圖張數、價格套千分位（空值顯示「未設定」）
- 精選改成可直接點的星號按鈕 → 新增 `toggle_featured` action（只翻布林值，不跑整份 sanitize 以免洗掉其他欄位）
- 表單分組（基本 / 圖片 / 購買連結 / 顯示設定），圖片有縮圖預覽，價格欄有 ¥ 前綴，sticky 面板內部捲動
- 空狀態、查無結果、hover / active / focus-visible 狀態補齊
- 設計語言沿用 wp-admin 自己的色票與系統字體，不外掛第三方字型（後台其他頁面才不會看起來兩套風格）

驗證：先用 stub 把這個畫面渲染成獨立 HTML 配真正的 wp-admin CSS 在本機看排版與互動，
部署後再回線上後台確認渲染、篩選與星號切換（切掉再切回，精選數維持 4）。

### 2026-08-07 v2.3.15 首頁主視覺圖兩個 key 合併（已部署）

`#b-hero` 元素掛的是 `data-kau-media="home.hero.bg"`，視覺編輯器換圖寫進 `bg`（首頁實際顯示的那張），
後台「主視覺圖片 URL」欄位寫的是 `image`，而 cms-content.js 的 `image()` 遇到帶 `data-kau-media`
的元素會直接跳過 → 後台那格從來沒生效過（線上 image=macaron-hero.png，實際顯示 Home-furniture）。

- 別名表補上 `home.hero.bg` → `home.hero.image`
- 新增 `kau_site_legacy_wins_paths()`：合併時這一組以舊 key（bg）為準，首頁大圖才不會無聲換掉
- 結果：`image` = 原本顯示的圖、`bg` 刪除；之後後台與視覺編輯器換圖都會生效

### 2026-08-07 v2.3.13–2.3.14 BOM、favicon/JSON-LD 寫死路徑（已部署）

- v2.3.13：拿掉 `kau-site.php` 開頭的 UTF-8 BOM（啟用時 WordPress 警告「3 個未預期輸出字元」）；
  新增 `kau_site_favicon_url()`，取代寫死的 `plugins/kau-site-lite/assets/053b….svg`
  （資料夾一漂移就 404，而且它在 `rewrite_asset_paths` 之後才注入，逐檔 fallback 救不到）
- v2.3.14：SEO 區塊剩下的 4 處同樣寫死路徑（og:image、JSON-LD logo / publisher.logo）一併改用該函式

**部署與清理紀錄**：線上資料夾 `kau-site-lite-2311` → `-2312` → `-2313` → `-2314`（現行 active）。
舊的 2311 / 2312 / 2313 / 2-1 / 2310 / -3 已從外掛清單移除（WP.com 會回報「無法完整移除」，
但外掛條目確實會消失、殘檔無影響）。`kau-site-lite-2.3.10/kau-site/` 因為是巢狀資料夾，
後台與 WP.com API 都刪不掉，維持停用即可。頁面 HTML 內烤死的舊資料夾 asset 網址由
`kau_site_asset_fallback_url` 自動改指到現行資料夾，實測 19 個 asset 全部 200。

### 2026-08-06 v2.3.12 視覺編輯器存檔後訪客頁面被舊資料蓋回去（已部署）

症狀：在 `?kau_edit=1` 改文字並儲存，編輯頁看得到，切到一般網址卻是舊文字。

機制：存檔時編輯器把 `data-kau-edit` 的 path 經 `PATH_ALIASES` 轉成正式 key 才寫進
`kau_site_data_v2`（`home.hero.sub` → `home.hero.subtitle`），但訪客頁面的
`kau-cms-final-sync` 是直接拿元素原始 path 去查資料，讀到沒被更新的舊 key，
載入後把剛存的文字蓋回去。編輯模式不注入 final-sync，所以編輯頁「看起來有存到」。

- 新增 `kau_site_path_aliases()` / `kau_site_sync_skip_paths()` 當唯一對照表，
  以 `kau-cms-path-maps` script 注入 `window.KAU_PATH_ALIASES` / `KAU_SYNC_SKIP`，
  編輯器 JS 與 final-sync 共用同一份（原本三處各有一份、內容還不一樣）
- final-sync 新增 `readPath()`：先查正式 key，沒有才退回原 path；skip 清單改共用
- `kau_site_set_path()` 改用同一份表，並在寫入正式 key 後 `unset` 舊 key，不留影子值
- `kau_site_get_data()` 遞迴正規化 `*_url`（沿用 `kau_site_sanitize_link_url`）→
  修掉前台把 `http://products.html` 寫回 href 的壞連結（頁面 HTML 早就修了，資料層沒修）
- `kau_site_migrate_path_aliases()` 一次性清資料裡的舊 key（option `kau_site_aliases_migrated`）
- cms-content.js 改讀正式 key（`hero.subtitle` / `home.philosophy` 優先）
- **刻意不合併** `home.hero.bg` → `home.hero.image`：兩個 key 存著不同的圖
  （首頁實際顯示 bg 的 Home-furniture，後台欄位是 macaron-hero），合併會無聲換掉主視覺

### 2026-07-02 v2.3.1–2.3.3 資產 404 修復 + 商品圖改走媒體庫

v2.3.0 部署後發現：hashed 頁面 JS（動畫引擎等 12 檔）只存在舊資料夾 `kau-site-lite-3`，
新啟用的 `kau-site-lite` 沒有 → 全站頁面 JS 404。

- v2.3.1：`kau_site_asset_fallback_url` 輸出時逐檔檢查，缺檔自動指向有檔案的兄弟資料夾；
  `kau_site_self_heal_assets` 在 admin_init / 啟用時把兄弟資料夾的檔案複製回自己資料夾（每版本跑一次，option `kau_site_assets_healed`）
- v2.3.2：`kau_site_media_library_url` 把 `media/` 商品圖（GitHub Pages 路徑）改對應到 WordPress 媒體庫同名檔
  （含 WP 重名 -1/-2 後綴變體，例：商品_21.png → 商品_21-1.png），媒體庫沒有才退回 github.io。
  實測 12 張 github.io 圖全部在媒體庫有對應檔案。
- v2.3.3：`kau_site_get_data()` 輸出時遞迴映射資料裡的舊圖片網址（商品/新聞的 image 欄位）→
  一次修好 /data API（前端商品卡）、SEO JSON-LD、og:image。v2.3.2 只修了頁面 HTML，漏了資料層。
- v2.3.4：編輯模式媒體庫 modal 排版修復（`kau-cms-media-fix` style）。前台沒有 wp-admin 的
  common.css → .screen-reader-text 顯形（"Selected media actions"）、頁面 reset 滲入 modal 擠壞排版。
  補齊隱藏規則 + 用 !important 釘回 modal/標題/分頁/工具列/按鈕版面。存檔時自動剔除不入 DB。
- v2.3.5：商品彈窗「詳細資訊」換行消失 → kau-cms-style 注入 `.product-detail-desc{white-space:pre-line}`。
  本地 products.html 早有這條但 DB 內是舊版 CSS（本地與 DB 頁面不同步的例子）。

### 2026-07-02 v2.3.0 網站健檢修復（已部署）

健檢發現線上 DB 內的頁面 HTML 因視覺編輯器存檔累積了大量垃圾：

- 首頁 253 個重複的 `stats.wp.com/w.js` script、22 份 help-center/gravatar script
- 瀏覽器擴充功能注入物被烤進 DB（stickynote CSS、`<a id="bottomBar">` 浮動 icon）
- 視覺編輯器曾把站內連結存成 `http://products.html`（會被當成網域，點了壞掉）——首頁 hero 的「製品を見る」「KAUについて」等 10 處
- 內頁（about/products/news）幾乎沒有 `data-reveal` 標記，所以只有首頁有動畫

v2.3.0 修復（全部改在 kau-site.php，上傳外掛即生效，不用碰 DB）：

1. `kau_site_strip_wp_pollution` 補齊清理規則（stats/widgets/gravatar/瀏覽器擴充/a11y 空 div/壞連結正規化）
2. 輸出時（`kau_site_serve`）即時清理 → 訪客立即拿到乾淨 HTML；存檔時（`kau_site_save_page`）也過一次 → DB 會在下次存檔自動洗乾淨
3. 編輯器 `cleanForSave` 的 WP_PATTERNS/WP_IDS 補齊同樣規則，防止再累積
4. 編輯器 `applyLink` 正規化站內連結輸入（`products.html` / `http://products.html` → `/products.html`）
5. 注入 `kau-cms-reveal`：DOMContentLoaded 先幫內頁靜態區塊補上 `data-reveal` 交給頁面本來就有的「KAU animation engine」動畫；引擎 init 後才動態長出的商品/新聞卡片用自己的 IntersectionObserver（`.kau-rv` class）補一次性 fade-up。編輯模式不注入。JS 掛掉有 4 秒安全網強制顯示，不會白屏。

部署：`wordpress-plugin/kau-site-lite.zip` 已重打包，照下方部署流程上傳即可。

### 2026-06-18 v1.x（舊架構，已淘汰）
- 外掛：`kau-original-site-editor` v1.7.3
- HTML 打包在外掛資料夾 `static/` 內
- 每次改內容都要重打 zip + 上傳，痛點極多
- 後因部署混亂、site-plugin-list-repair 過濾器導致無法管理，整站重設

### 2026-06-18 v2.x（資料庫版，目前）
- 外掛：`kau-site` → 部署為 `kau-site-lite`
- HTML 全存 wp_options，編輯後立即生效
- 視覺化編輯：點文字、圖片、連結直接改
- 圖片用 WordPress 媒體庫
- 商品 / 新聞有完整 CRUD 後台
