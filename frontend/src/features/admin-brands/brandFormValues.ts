import type { components } from '../../api/schema'

type BrandResource = components['schemas']['BrandResource']
type BrandPathNode = components['schemas']['BrandListItemResource']['category_path'][number]

/**
 * 銘柄の編集フォームの入力(#64)。欄に出す文字のまま持ち、送るときに multipart の値にする(toBrandFormData)。
 * 数の欄(度数・開始位置)も文字のまま持つ。空の欄を 0 にせず、空のまま送って API の検証(必須など)に任せるため。
 */
export type BrandFormValues = {
  /** 所属するノード。まだ選んでいなければ null */
  categoryId: number | null
  name: string
  nameEng: string
  /** 度数(%) */
  strength: string
  description: string
  /** YouTube の URL か動画 ID */
  youtube: string
  youtubeStartHour: string
  youtubeStartMinute: string
  youtubeStartSecond: string
  /** 画像のリンクの文字と URL(旧の「リンクテキスト」「リンクURL」) */
  imageTxt: string
  imageUrl: string
  isMajor: boolean
  isLike: boolean
  /** 新しく上げる画像。選んでいなければ null(今の画像のまま) */
  image: File | null
}

/** 新規の画面の最初の入力。旧(BrandEdit.vue の detailEditObj)と同じく、すべて空でメジャー・お気に入りは外す */
export const EMPTY_BRAND_FORM: BrandFormValues = {
  categoryId: null,
  name: '',
  nameEng: '',
  strength: '',
  description: '',
  youtube: '',
  youtubeStartHour: '',
  youtubeStartMinute: '',
  youtubeStartSecond: '',
  imageTxt: '',
  imageUrl: '',
  isMajor: false,
  isLike: false,
  image: null,
}

const text = (value: string | number | null): string => (value === null ? '' : String(value))

/** 編集で開いた銘柄(GET /api/admin/brands/{id})を、フォームの入力にする */
export function brandFormValuesOf(brand: BrandResource): BrandFormValues {
  return {
    categoryId: brand.category_id,
    name: brand.name,
    nameEng: brand.name_eng,
    strength: text(brand.strength),
    description: brand.description ?? '',
    youtube: brand.youtube ?? '',
    youtubeStartHour: text(brand.youtube_start_hour),
    youtubeStartMinute: text(brand.youtube_start_minute),
    youtubeStartSecond: text(brand.youtube_start_second),
    imageTxt: brand.image_txt ?? '',
    imageUrl: brand.image_url ?? '',
    isMajor: brand.is_major,
    isLike: brand.is_like,
    image: null,
  }
}

/**
 * フォームの入力を、銘柄の新規・更新の API に送る multipart の本文にする。
 *
 * 画像を上げられるよう、画像を選んでいないときも multipart で送る(旧も FormData で送っていた)。
 * 空の欄は空の文字のまま送る(Laravel が null に読み、必須の欄なら 422 になる)。所属を選んでいなければ送らない。
 * 真偽値は API が読める "1"・"0" にする。更新は PHP が PUT の multipart を読まないので、POST に `_method=PUT` を付けて送る
 * (SaveBrandRequest の説明のとおり)。
 */
export function toBrandFormData(values: BrandFormValues, method: 'POST' | 'PUT'): FormData {
  const form = new FormData()
  if (method === 'PUT') {
    form.append('_method', 'PUT')
  }
  if (values.categoryId !== null) {
    form.append('category_id', String(values.categoryId))
  }
  form.append('name', values.name)
  form.append('name_eng', values.nameEng)
  form.append('strength', values.strength)
  form.append('description', values.description)
  form.append('youtube', values.youtube)
  form.append('youtube_start_hour', values.youtubeStartHour)
  form.append('youtube_start_minute', values.youtubeStartMinute)
  form.append('youtube_start_second', values.youtubeStartSecond)
  form.append('image_txt', values.imageTxt)
  form.append('image_url', values.imageUrl)
  form.append('is_major', values.isMajor ? '1' : '0')
  form.append('is_like', values.isLike ? '1' : '0')
  if (values.image !== null) {
    form.append('image', values.image)
  }
  return form
}

/** 全角の数字を半角にする(旧 Helper.onlyNumberAndDot の前半と同じ) */
const toHalfWidthDigits = (value: string): string =>
  value.replace(/[０-９]/g, (digit) => String.fromCharCode(digit.charCodeAt(0) - 0xfee0))

/** 度数の欄に入れられる文字数(旧の欄の counter と同じ 4 文字) */
export const STRENGTH_MAX_LENGTH = 4

/**
 * 度数の欄に入れた文字から、数字と `.` だけを残す(旧 NamesAndDescription.vue の onlyNumberAndDot と同じ。全角の数字は半角にする)。
 * 4 文字を超えた分は捨てる(#64 の追記: 旧と同じく 4 文字まで)。
 */
export function sanitizeStrength(input: string): string {
  return toHalfWidthDigits(input)
    .replace(/[^0-9.]/g, '')
    .slice(0, STRENGTH_MAX_LENGTH)
}

/**
 * 開始位置(時・分・秒)の欄に入れた文字から、数字だけを残して maxLength 桁までにし、数にして出す
 * (旧の onlyNumber1digit・onlyNumber2digit と同じ。全角の数字は半角にし、`.` も捨てる。時は 1 桁、分・秒は 2 桁)。
 * 旧と同じく、数字の無い文字を入れたら 0、先頭の 0 は落とす(`05` は `5`)。欄を空にしたときだけ空のまま。
 */
export function sanitizeDigits(input: string, maxLength: number): string {
  if (input === '') {
    return ''
  }
  const digits = toHalfWidthDigits(input)
    .replace(/[^0-9]/g, '')
    .slice(0, maxLength)
  return String(Number(digits))
}

/** 英名の全角の英数字を半角にする(旧 Helper.Big_to_hankaku と同じ。旧は英名の欄を離れたときにかけていた) */
export function toHalfWidthAlphanumeric(input: string): string {
  return input.replace(/[Ａ-Ｚａ-ｚ０-９]/g, (char) => String.fromCharCode(char.charCodeAt(0) - 0xfee0))
}

/**
 * 一覧の 1 行の所属(#64 の追記)。根を除いたパンくずのうち、いちばん上の段と所属のノード(末尾)だけを「/」でつなぎ
 * (#283 の 3。間の段は省く)、その段の有名な銘柄がこの銘柄なら、段の名前の前に ★ を付ける(旧 ListComponent.vue の append と同じ書き方)。
 * 所属がいちばん上の段なら、その 1 段だけ。パンくずが空なら空の文字。
 */
export function brandPlacementLabel(path: readonly BrandPathNode[]): string {
  const shown = path.length <= 1 ? path : [path[0], path[path.length - 1]]
  return shown.map((node) => `${node.is_famous_brand ? '★' : ''}${node.name}`).join('/')
}
