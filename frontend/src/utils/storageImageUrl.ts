/**
 * 画像の列(image)の値から、`<img>` の src にする URL を作る。
 *
 * API は画像の列を保存した値のまま返す(public ディスクの直下のファイル名か、外部の URL)。
 * ファイル名は、旧(`asset('storage/…')`)と同じく `/storage/<ファイル名>` で配信しているので、その下を指す。
 * ファイル名のまま src に入れると、画面の URL からの相対パス(`/category/2` なら `/category/<ファイル名>`)になり、画像が出ない(#585)。
 * 外部の URL は、そのまま使う(旧は外部の URL にも `storage/` を付けていた。docs/decisions/detail-image.md の「旧との差分」)。
 * 外部の URL の見分け方は BE の `PublicDiskImage::isExternal` と同じ。
 */
export function storageImageUrl(image: string): string {
  return /^https?:\/\//i.test(image) ? image : `/storage/${image}`
}
