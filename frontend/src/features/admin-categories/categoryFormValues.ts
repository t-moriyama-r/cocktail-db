import type { components } from '../../api/schema'

type CategoryResource = components['schemas']['CategoryResource']
type CategoryRole = components['schemas']['CategoryRole']

/**
 * ノードの編集フォームの入力(#308)。欄に出す文字のまま持ち、送るときに multipart の値にする(toCategoryFormData)。
 * 開始位置も文字のまま持つ。空の欄を 0 にせず、空のまま送って API の検証に任せるため(銘柄のフォームと同じ)。
 */
export type CategoryFormValues = {
  /** 親。新規でまだ選んでいなければ null。根(isRoot)なら使わない */
  parentId: number | null
  /**
   * 根のノードか。新規なら「親なし」を選ぶと true(根を作る。#310)。編集では開いたノードが根なら true で、変えない
   * (フォームの親の欄で選び直すのは #675)
   */
  isRoot: boolean
  /** 根の役割(なし = null・ソフトドリンク・その他。#310)。根のときだけ送る */
  role: CategoryRole | null
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

/** 新規の画面の最初の入力。旧(CategoryEdit.vue・MiddleEdit.vue の detailEditObj)と同じく、すべて空でチェックは外す */
export const EMPTY_CATEGORY_FORM: CategoryFormValues = {
  parentId: null,
  isRoot: false,
  role: null,
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

const text = (value: string | number | null): string => (value === null ? '' : String(value))

/** 編集で開いたノード(GET /api/admin/categories/{id})を、フォームの入力にする */
export function categoryFormValuesOf(node: CategoryResource): CategoryFormValues {
  return {
    parentId: node.parent_id,
    isRoot: node.parent_id === null,
    role: node.role,
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

/** 保存する親。parentId の子として保存する(null なら根) */
export type SavedParent = { parentId: number | null }

/**
 * このノードを保存する親(#705)。保存で送る親(toCategoryFormData)と、名前・英名の重なりを比べる親(CategoryForm。#674)は、
 * ずれないようここだけで決める。
 *
 * 根(新規で「親なし」を選んだ・根の編集)なら根どうし(`{ parentId: null }`)。前に選んでいた親があっても使わない。
 * それ以外は、今の親か選んでいる親。新規でまだ親を選んでいなければ、決まらないので null。
 */
export function savedParentOf(values: Pick<CategoryFormValues, 'isRoot' | 'parentId'>): SavedParent | null {
  if (values.isRoot) {
    return { parentId: null }
  }
  return values.parentId === null ? null : { parentId: values.parentId }
}

/**
 * フォームの入力を、ノードの新規・更新の API に送る multipart の本文にする。
 *
 * 画像を上げられるよう、画像を選んでいないときも multipart で送る(旧も FormData で送っていた)。
 * 空の欄は空の文字のまま送る(Laravel が null に読み、必須の欄なら 422 になる)。
 * 親は savedParentOf で決め、根なら空にして役割を添え、決まらない(新規でまだ選んでいない)なら送らない
 * (API が「親が要る」の 422 を返す)。役割は「なし」なら送らない(API は送られない役割を null にする)。
 * 根以外では役割を送らない(役割を持てるのは根だけ。#153)。
 * 真偽値は API が読める "1"・"0" にする。更新は PHP が PUT の multipart を読まないので、POST に `_method=PUT` を付けて送る。
 * 有名な銘柄の欄は、appendFamousBrand(famousBrandValues.ts)が足す。
 */
export function toCategoryFormData(values: CategoryFormValues, method: 'POST' | 'PUT'): FormData {
  const form = new FormData()
  if (method === 'PUT') {
    form.append('_method', 'PUT')
  }
  const parent = savedParentOf(values)
  if (parent !== null) {
    form.append('parent_id', parent.parentId === null ? '' : String(parent.parentId))
  }
  if (values.isRoot && values.role !== null) {
    form.append('role', values.role)
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
