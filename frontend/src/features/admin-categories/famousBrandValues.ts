import type { components } from '../../api/schema'
import { toComparableKana } from '../../utils/toComparableKana'
import type { BrandFormValues } from '../admin-brands/brandFormValues'

type CategoryResource = components['schemas']['CategoryResource']

/** 有名な銘柄に選べる銘柄(API の famous_brand_candidates の 1 件。そのノードの部分木の銘柄。#158) */
export type FamousBrandCandidate = CategoryResource['famous_brand_candidates'][number]

/** その場で作る銘柄の入力(#309)。銘柄のフォームと同じ欄で、所属は保存するノードの直下に決まるので持たない */
export type NewBrandValues = Omit<BrandFormValues, 'categoryId'>

/** 有名な銘柄の欄の入力 */
export type FamousBrandValues = {
  /** 選んだ既存の銘柄の ID。「新しく作成する」なら null */
  selectedId: number | null
  /** 「新しく作成する」のときに送る、その場で作る銘柄の入力 */
  newBrand: NewBrandValues
}

/** 有名な銘柄の欄を出すノードの、候補と今の有名な銘柄。根には有名な銘柄を入れられないので、根では欄を出さない(null) */
export type FamousBrandSetting = {
  /** 候補(旧と同じく、名前の順に並べ直したもの。sortFamousBrandCandidates) */
  candidates: readonly FamousBrandCandidate[]
  famousBrandId: number | null
}

/** その場で作る銘柄の最初の入力。旧(NewBrandProp.js)と同じく、すべて空でメジャー・お気に入りは外す */
export const EMPTY_NEW_BRAND: NewBrandValues = {
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

/** 新規のノードの有名な銘柄の欄。まだ部分木に銘柄が無いので、候補は無い */
export const NEW_NODE_FAMOUS_BRAND: FamousBrandSetting = { candidates: [], famousBrandId: null }

/**
 * 有名な銘柄の候補を、旧(FamousBrandSelect.vue の select_brand が Helper.aiu で並べた順)と同じく名前の順にする。
 * 名前はカタカナをひらがなに、全角の英数字を半角に、英字を小文字にそろえてから、文字コードの大小で比べる
 * (localeCompare は使わない)。同じ名前なら API の順(ID の順)のまま。
 */
export function sortFamousBrandCandidates(candidates: readonly FamousBrandCandidate[]): FamousBrandCandidate[] {
  const keyOf = (candidate: FamousBrandCandidate) => toComparableKana(candidate.name).toLowerCase()
  return [...candidates].sort((a, b) => {
    const aKey = keyOf(a)
    const bKey = keyOf(b)
    if (aKey === bKey) return 0
    return aKey > bKey ? 1 : -1
  })
}

/** 編集で開いたノードの有名な銘柄の欄。根(famous_brand_requirement が none)では出さない */
export function famousBrandSettingOf(node: CategoryResource): FamousBrandSetting | null {
  if (node.famous_brand_requirement === 'none') {
    return null
  }
  return { candidates: sortFamousBrandCandidates(node.famous_brand_candidates), famousBrandId: node.famous_brand_id }
}

/**
 * 有名な銘柄の欄の最初の入力。今の有名な銘柄を選んでおき、無ければ「新しく作成する」にする(旧と同じ)。
 * 今の有名な銘柄が候補に無いときも「新しく作成する」にする(選べない銘柄を送り直さない)
 */
export function famousBrandValuesOf(setting: FamousBrandSetting): FamousBrandValues {
  const current = setting.candidates.find((candidate) => candidate.id === setting.famousBrandId)
  return { selectedId: current?.id ?? null, newBrand: EMPTY_NEW_BRAND }
}

/** その場で作る銘柄の項目(API の名前)。「新しく作成する」のときだけ欄を出すので、422 のメッセージもそのときだけ欄の下に出す */
export const NEW_BRAND_FIELDS = [
  'brand_name',
  'brand_name_eng',
  'brand_strength',
  'brand_description',
  'brand_is_major',
  'brand_is_like',
  'brand_youtube',
  'brand_youtube_start_hour',
  'brand_youtube_start_minute',
  'brand_youtube_start_second',
  'brand_image',
  'brand_image_txt',
  'brand_image_url',
] as const

/** 有名な銘柄の欄が、欄の下に 422 のメッセージを出す項目 */
export function famousBrandFieldsOnForm(values: FamousBrandValues): readonly string[] {
  return values.selectedId === null ? ['famous_brand_id', ...NEW_BRAND_FIELDS] : ['famous_brand_id']
}

/**
 * 有名な銘柄の入力を、ノードの保存の multipart の本文に足す(SaveCategoryRequest の famous_brand_id・brand_*)。
 *
 * 既存の銘柄を選んだら famous_brand_id だけを送る。「新しく作成する」なら famous_brand_id を送らず、その場で作る銘柄の欄を送る
 * (空の欄も空のまま送り、必須かどうかは API が根の役割で決める。#155・#158)。
 * YouTube とリンクテキスト・リンクURL は、銘柄の欄の値を送る(旧はノードの YouTube を銘柄に入れ、リンクは保存しなかった。#309)。
 */
export function appendFamousBrand(form: FormData, values: FamousBrandValues): void {
  if (values.selectedId !== null) {
    form.append('famous_brand_id', String(values.selectedId))
    return
  }
  const brand = values.newBrand
  form.append('brand_name', brand.name)
  form.append('brand_name_eng', brand.nameEng)
  form.append('brand_strength', brand.strength)
  form.append('brand_description', brand.description)
  form.append('brand_youtube', brand.youtube)
  form.append('brand_youtube_start_hour', brand.youtubeStartHour)
  form.append('brand_youtube_start_minute', brand.youtubeStartMinute)
  form.append('brand_youtube_start_second', brand.youtubeStartSecond)
  form.append('brand_image_txt', brand.imageTxt)
  form.append('brand_image_url', brand.imageUrl)
  form.append('brand_is_major', brand.isMajor ? '1' : '0')
  form.append('brand_is_like', brand.isLike ? '1' : '0')
  if (brand.image !== null) {
    form.append('brand_image', brand.image)
  }
}
