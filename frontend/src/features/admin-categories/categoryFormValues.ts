import type { components } from '../../api/schema'

type CategoryResource = components['schemas']['CategoryResource']
type CategoryRole = components['schemas']['CategoryRole']

/**
 * ノードの編集フォームの入力(#308)。欄に出す文字のまま持ち、送るときに multipart の値にする(toCategoryFormData)。
 * 開始位置も文字のまま持つ。空の欄を 0 にせず、空のまま送って API の検証に任せるため(銘柄のフォームと同じ)。
 */
export type CategoryFormValues = {
  /** 親。新規でまだ選んでいなければ null。根の編集でも null(根のまま保存する) */
  parentId: number | null
  name: string
  nameEng: string
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
  isPowder: boolean
  /** 新しく上げる画像。選んでいなければ null */
  image: File | null
  /** 今の画像を消すか。新しい画像を選んだときは使わない(差し替えになる) */
  removeImage: boolean
}

/**
 * このフォームでは変えないが、保存のときに今の値のまま送り直すもの。
 *
 * - 根の役割(#310 で変えられるようにする)。根の保存で送らないと、API は役割を消す
 *
 * 有名な銘柄は、有名な銘柄の欄(FamousBrandField。#309)の入力で送る(famousBrandValues.ts)
 */
export type CategoryKeptValues = {
  /** 根の編集か。根は親を空(null)で送る */
  isRoot: boolean
  role: CategoryRole | null
}

/** 新規の画面の最初の入力。旧(CategoryEdit.vue・MiddleEdit.vue の detailEditObj)と同じく、すべて空でチェックは外す */
export const EMPTY_CATEGORY_FORM: CategoryFormValues = {
  parentId: null,
  name: '',
  nameEng: '',
  description: '',
  youtube: '',
  youtubeStartHour: '',
  youtubeStartMinute: '',
  youtubeStartSecond: '',
  imageTxt: '',
  imageUrl: '',
  isMajor: false,
  isLike: false,
  isPowder: false,
  image: null,
  removeImage: false,
}

/** 新規のノードは根でなく(根を作るのは #310)、役割を持たない */
export const NEW_CATEGORY_KEPT: CategoryKeptValues = { isRoot: false, role: null }

const text = (value: string | number | null): string => (value === null ? '' : String(value))

/** 編集で開いたノード(GET /api/admin/categories/{id})を、フォームの入力にする */
export function categoryFormValuesOf(node: CategoryResource): CategoryFormValues {
  return {
    parentId: node.parent_id,
    name: node.name,
    nameEng: node.name_eng,
    description: node.description ?? '',
    youtube: node.youtube ?? '',
    youtubeStartHour: text(node.youtube_start_hour),
    youtubeStartMinute: text(node.youtube_start_minute),
    youtubeStartSecond: text(node.youtube_start_second),
    imageTxt: node.image_txt ?? '',
    imageUrl: node.image_url ?? '',
    isMajor: node.is_major,
    isLike: node.is_like,
    isPowder: node.is_powder,
    image: null,
    removeImage: false,
  }
}

/** 編集で開いたノードから、このフォームでは変えずに送り直す値を取り出す */
export function categoryKeptValuesOf(node: CategoryResource): CategoryKeptValues {
  return { isRoot: node.parent_id === null, role: node.role }
}

/**
 * フォームの入力を、ノードの新規・更新の API に送る multipart の本文にする。
 *
 * 画像を上げられるよう、画像を選んでいないときも multipart で送る(旧も FormData で送っていた)。
 * 空の欄は空の文字のまま送る(Laravel が null に読み、必須の欄なら 422 になる)。
 * 親は、根の編集なら空(根のまま)、新規でまだ選んでいなければ送らない(API が「親が要る」の 422 を返す)。
 * 真偽値は API が読める "1"・"0" にする。更新は PHP が PUT の multipart を読まないので、POST に `_method=PUT` を付けて送る。
 * 有名な銘柄の欄は、appendFamousBrand(famousBrandValues.ts)が足す。
 */
export function toCategoryFormData(values: CategoryFormValues, kept: CategoryKeptValues, method: 'POST' | 'PUT'): FormData {
  const form = new FormData()
  if (method === 'PUT') {
    form.append('_method', 'PUT')
  }
  if (kept.isRoot) {
    form.append('parent_id', '')
    if (kept.role !== null) {
      form.append('role', kept.role)
    }
  } else if (values.parentId !== null) {
    form.append('parent_id', String(values.parentId))
  }
  form.append('name', values.name)
  form.append('name_eng', values.nameEng)
  form.append('description', values.description)
  form.append('youtube', values.youtube)
  form.append('youtube_start_hour', values.youtubeStartHour)
  form.append('youtube_start_minute', values.youtubeStartMinute)
  form.append('youtube_start_second', values.youtubeStartSecond)
  form.append('image_txt', values.imageTxt)
  form.append('image_url', values.imageUrl)
  form.append('is_major', values.isMajor ? '1' : '0')
  form.append('is_like', values.isLike ? '1' : '0')
  form.append('is_powder', values.isPowder ? '1' : '0')
  if (values.image !== null) {
    form.append('image', values.image)
  } else if (values.removeImage) {
    form.append('remove_image', '1')
  }
  return form
}
