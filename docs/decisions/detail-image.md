# 詳細の画面の画像の URL

#585 で決めたこと。詳細の画面(今はカテゴリの詳細。画像と出典を出す部品 `CreditedImage`)で、画像の列(`image`)の値から `<img>` の src を作る決まり。

## 決まったこと

1. **API は画像の列を保存した値のまま返し、URL にするのは画面**(`frontend/src/utils/storageImageUrl.ts`)。API の決まりは [dictionary.md](dictionary.md)・[brand-detail.md](brand-detail.md) とカクテルの詳細の API と同じ
2. **ファイル名は `/storage/<ファイル名>` を指す**。旧(`asset('storage/…')`)と同じ配信先。ファイル名のまま src に入れると、画面の URL からの相対パス(`/category/2` で開くと `/category/<ファイル名>`)になって画像が出なかった(#585 で FE のテストで再現した)。ファイル名はエンコードしない(旧の `asset()` と同じ。空白や日本語はブラウザがエンコードして取りに行く)
3. **外部の URL(`http://`・`https://` で始まる値。大文字・小文字は問わない)は、そのまま src にする**。見分け方は BE の `PublicDiskImage::isExternal`(`legacy:verify-images` が外部の URL として数えるもの)と同じ

## 旧との差分

テストの表では `差分あり(意図: docs/decisions/detail-image.md)` としてここを指す。旧の挙動は本番の旧ソース(cocktail-legacy の `laravel13/app/Http/Resources/Detail/*ResourceForDetail.php` と `laravel6/resources/js/components/Detail/component/image.vue`)から読み取ったもので、旧を動かして確かめてはいない。

- 画像の列が外部の URL のとき: 旧は API が外部の URL にも `storage/` を付けて(`<サイトの URL>/storage/https://…`)返し、画面がそのまま src にしていた(画像が出ない) → 新は外部の URL をそのまま src にする

## 理由

- 2: 移行した画像も、管理画面で上げる画像(`AdminImageStore`)も、public ディスクの直下に置いて `/storage/<値>` で配信している。画面の URL によらず同じ画像を指すよう、`/` から始まるパスにする
- 3: 旧の形(`/storage/https://…`)では画像が出ず、旧に合わせて得るものが無い。画像の列に外部の URL が入っていることは、`legacy:verify-images` が数えて確かめている
