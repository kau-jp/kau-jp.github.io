# KAU WordPress 正式版視覺編輯架構

最後更新：2026-06-17

## 目標

把目前的 KAU 靜態網站正式放進 WordPress，但仍然維持兩個核心要求：

1. 前台畫面要跟原始靜態頁一模一樣。
2. 使用者要能在前台視覺化修改文字、連結、圖片，不要欄位式後台。

正式版不應再依賴 GitHub Pages 當前台資源來源。GitHub 可以保留作為程式碼備份或部署來源，但 `kau-jp.com` 的前台輸出應該由 WordPress 本身完成。

## 目前狀態

目前線上 WordPress 使用自製外掛：

- 啟用中：`KAU Original Visual Editor v20`
- 功能：攔截 WordPress 前台頁面，輸出原始 KAU 靜態頁，並在登入狀態下提供 `?kau_edit=1` 前台視覺編輯模式。
- 現況仍有一部分資源從 `https://kau-jp.github.io/` 載入，這是過渡版做法。
- 目前已確認四頁可顯示原始內容：
  - `/`
  - `/about/`
  - `/products/`
  - `/news/`
- 目前視覺編輯模式可用：
  - 文字可直接點選修改
  - 左上角工具列有 `Save`
  - 圖片可點選上傳
  - 連結可選取後用 `Link` 修改 URL

注意：WordPress.com 後台曾留下 v18/v19 的未啟用殘列，原因是先前 zip 包裝路徑錯誤導致 WordPress.com 刪除不完全。它們未啟用，不應影響前台。正式版應以乾淨單一外掛或主題重新整理。

## 為什麼不要繼續用 GitHub 當前台來源

使用 GitHub Pages 當唯一 HTML/assets 來源的好處是保真度高，因為 WordPress 幾乎不碰原始檔。

但正式營運有幾個缺點：

- 使用者會疑惑為什麼 `kau-jp.com` 還在載入 `kau-jp.github.io`。
- WordPress 不是完整自足，一旦 GitHub Pages 改動或失效，前台會受影響。
- SEO 工具、社群預覽、非 Google 搜尋引擎可能不一定完整理解前端套用後的內容。
- 圖片與內容資料分散在 GitHub、WordPress option、WordPress 媒體庫之間，不直覺。

正式版應改為：

```text
WordPress 外掛或主題內建原始 HTML/CSS/JS/assets
→ WordPress 伺服器端讀取 HTML
→ 套用已儲存的文字/連結/圖片覆寫
→ 直接輸出最終 HTML 給訪客與搜尋引擎
```

## SEO 原則

正式版不會因為改成 WordPress 來源而傷 SEO，前提是做法正確。

必須符合：

- 不使用 iframe。
- 不只靠前端 JS 產生主要內容。
- WordPress 直接回傳完整 HTML。
- 已修改後的文字要在伺服器端就寫進 HTML response。
- 每頁保留獨立 URL：
  - `/`
  - `/about/`
  - `/products/`
  - `/news/`
- 每頁要有正確：
  - `<title>`
  - `<meta name="description">`
  - canonical
  - og:title / og:description / og:image
  - 圖片 `alt`
- 圖片應從 WordPress 媒體庫輸出正式 URL。

正式版比目前過渡版更適合 SEO，因為搜尋引擎看到的是已套用修改後的最終 HTML，而不是原始 HTML 再等 JS 覆寫。

## 正式版資料責任

### HTML/CSS/JS/assets

放在 WordPress 外掛或主題內。

建議路徑：

```text
kau-original-site/
  kau-original-site.php
  static/
    home.html
    about.html
    products.html
    news.html
  assets/
  media/
```

不要再從 `https://kau-jp.github.io/` 載入前台必要資源。

### 使用者修改內容

使用 WordPress option 儲存即可，先不需要自訂資料表。

建議 option：

```text
kau_visual_page_edits
```

資料結構：

```json
{
  "home": {
    "text": {
      "text-15": { "html": "COLLECTION" }
    },
    "link": {
      "link-3": { "href": "/products/" }
    },
    "media": {
      "media-2": {
        "src": "https://kau-jp.com/wp-content/uploads/...",
        "attachmentId": 123,
        "alt": "..."
      }
    }
  }
}
```

### 圖片

正式版圖片流程應該是：

1. 編輯模式點圖片。
2. 顯示圖片工具列或 modal。
3. 可選：
   - 上傳新圖片
   - 從媒體庫選既有圖片
   - 修改 alt
   - 取消
4. 選定後先更新畫面。
5. 按 `Save` 後才寫入該頁 edits。

圖片應存到 WordPress 媒體庫，不要存 GitHub。

## 前台視覺編輯 UX

進入方式：

```text
/?kau_edit=1
/about/?kau_edit=1
/products/?kau_edit=1
/news/?kau_edit=1
```

工具列應包含：

- `Save`
- `Exit`
- `Link`
- 儲存狀態：`Saved` / `Unsaved` / `Saving...` / error message

文字編輯：

- 點文字直接變成可編輯。
- 有修改時狀態變成 `Unsaved`。
- 按 `Save` 後寫入 WordPress。

連結與按鈕：

- 編輯模式下，頁面內的 `<a>` 與 `<button>` 不應真的跳頁。
- 點連結時只選取該連結。
- 按 `Link` 才打開 URL 編輯欄。
- `Exit` 是工具列按鈕，才允許離開編輯模式。

圖片：

- 點圖片不跳頁。
- 點圖片應開啟上傳/媒體庫選擇。
- 圖片選定後狀態變 `Unsaved`。
- 按 `Save` 後固定。

離開保護：

- 有未儲存修改時，離開頁面應跳出瀏覽器確認。

## 伺服器端輸出邏輯

正式版關鍵是「伺服器端套用 edits」，不要只靠 JS。

建議流程：

```php
template_redirect
  -> 判斷目前 slug: home/about/products/news
  -> 讀取 static/{slug}.html
  -> 修正 assets/media URL 指向 plugin_url()
  -> 讀取 option 中該頁 edits
  -> 用 DOMDocument 或 WP_HTML_Tag_Processor 套用 edits
  -> 若是 ?kau_edit=1 且有權限，注入 visual editor runtime
  -> echo 最終 HTML
  -> exit
```

套用 edits 時不要用脆弱的全文字串 replace。應該依照穩定 ID 套用。

目前過渡版用 `text-0`, `text-1` 這種 runtime index。正式版更好做法是在第一次匯入 HTML 時，為可編輯元素加上固定屬性：

```html
<h1 data-kau-edit="home.hero.title">すわる、を<br>美しく。</h1>
<a data-kau-link="home.hero.cta-products" href="/products/">製品を見る</a>
<img data-kau-media="home.hero.image" src="..." alt="">
```

這樣未來 HTML 小改版時，不會因為順序改變導致內容套錯位置。

## 建議實作順序

### Step 1：建立乾淨正式外掛

不要延續 v18/v19/v20 測試外掛名稱。

建議新外掛：

```text
kau-original-site-editor/
  kau-original-site-editor.php
```

外掛名稱：

```text
KAU Original Site Editor
```

### Step 2：把靜態頁與 assets 內建進外掛

複製：

- `home.html`
- `about.html`
- `products.html`
- `news.html`
- `assets/`
- `media/`

前台資源全部指向外掛內 URL，不再指向 GitHub。

### Step 3：改成伺服器端套用 edits

先支援：

- text
- link
- media src
- media alt

### Step 4：重建 visual editor runtime

編輯模式才注入 JS/CSS。

JS 負責：

- contenteditable
- 防止連結跳頁
- 圖片選擇
- 收集 edits
- AJAX save

但公開訪客看到的內容應該已由 PHP 套好，不依賴 JS。

### Step 5：加入媒體庫選圖

WordPress admin/front-end 可用 `wp_enqueue_media()`，讓使用者從媒體庫選圖片。

若前台相容性不好，可先保留「本機上傳」，再加媒體庫選擇。

### Step 6：SEO 補強

每頁輸出：

- title
- meta description
- canonical
- og tags
- schema.org Organization / WebSite 基礎資料

### Step 7：測試

必測：

- 未登入訪客看不到工具列。
- 登入管理者 `?kau_edit=1` 看得到工具列。
- 改文字 → Save → 重新整理仍存在。
- 改連結 → Save → 公開頁連結正確。
- 點按鈕/連結不會在編輯模式跳頁。
- 換圖片 → Save → 公開頁圖片正確。
- 搜尋引擎抓取 HTML 裡直接含修改後文字。
- 關閉 GitHub Pages 或阻擋 `kau-jp.github.io` 時，`kau-jp.com` 仍正常。

## 不要做的事

- 不要用 iframe 嵌 GitHub。
- 不要用 Elementor 重做一份「看起來像」的頁面。
- 不要把主要內容只存在 JS 裡。
- 不要讓正式前台依賴 `kau-jp.github.io`。
- 不要用欄位式 ACF 當主要編輯介面，因為使用者明確不要欄位式。
- 不要繼續累積 v18/v19/v20 這類測試外掛。正式版要乾淨命名、乾淨安裝。

## 給下一個 AI 的備註

使用者真正要的是：

```text
原始 HTML 保真
+ WordPress 自己當前台來源
+ 前台視覺化直接編輯
+ SEO 友善
+ 圖片走 WordPress 媒體庫
```

不要再回答「可以用 Elementor」作為主要方案，因為使用者已經明確拒絕「重新捏一份」與「欄位式修改」。

如果要開始實作，請先建立新的正式外掛，不要再基於線上的 v18/v19/v20 測試殘留繼續疊版本。

