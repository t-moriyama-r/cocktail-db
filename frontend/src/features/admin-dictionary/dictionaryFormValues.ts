import type { components } from '../../api/schema'

type DictionaryResource = components['schemas']['DictionaryResource']

/** 用語の新規・更新の API(POST・PUT /api/admin/dictionaries)に送る本文。画像のファイルは別に FormData へ足す */
export type SaveDictionaryBody = components['schemas']['SaveDictionaryRequest']

/**
 * 用語の編集の画面の欄の値。入力の欄はどれも文字列で持ち、送るときに API の型(SaveDictionaryBody)にする。
 * 分類は選んでいなければ空文字。
 */
export type DictionaryFormValues = {
  dictionary_category_id: string
  name: string
  name_eng: string
  description: string
  youtube: string
  youtube_start_hour: string
  youtube_start_minute: string
  youtube_start_second: string
  image_txt: string
  image_url: string
  /** 新しく上げる画像。選んでいなければ null */
  image: File | null
  /** 今の画像を消すか */
  remove_image: boolean
}

/** 新規の画面の初めの値(分類は選ばせる) */
export const EMPTY_DICTIONARY_FORM: DictionaryFormValues = {
  dictionary_category_id: '',
  name: '',
  name_eng: '',
  description: '',
  youtube: '',
  youtube_start_hour: '',
  youtube_start_minute: '',
  youtube_start_second: '',
  image_txt: '',
  image_url: '',
  image: null,
  remove_image: false,
}

const toText = (value: string | number | null): string => (value === null ? '' : String(value))

/** 編集で開いた用語(GET /api/admin/dictionaries/{id})を、欄の値にする */
export function toDictionaryForm(dictionary: DictionaryResource): DictionaryFormValues {
  return {
    dictionary_category_id: String(dictionary.dictionary_category_id),
    name: dictionary.name,
    name_eng: dictionary.name_eng,
    description: toText(dictionary.description),
    youtube: toText(dictionary.youtube),
    youtube_start_hour: toText(dictionary.youtube_start_hour),
    youtube_start_minute: toText(dictionary.youtube_start_minute),
    youtube_start_second: toText(dictionary.youtube_start_second),
    image_txt: toText(dictionary.image_txt),
    image_url: toText(dictionary.image_url),
    image: null,
    remove_image: false,
  }
}

/** 空の欄は null(API は空と null を同じに読む) */
const nullableText = (value: string): string | null => (value.trim() === '' ? null : value)

/** 数字の欄。空なら null。数字でない値はそのまま数にして送り、API の検証(整数か・範囲)に任せる */
const nullableNumber = (value: string): number | null => (value.trim() === '' ? null : Number(value))

/**
 * 欄の値を、API に送る本文にする。分類を選んでいなければ null(送らずに、分類を選ぶよう出す)。
 * 分類のほかの必須・長さ・形の検証は API がし、誤りは 422 で欄ごとに返る(#284 の既定)。
 */
export function toSaveDictionaryBody(values: DictionaryFormValues): SaveDictionaryBody | null {
  if (values.dictionary_category_id === '') {
    return null
  }
  return {
    dictionary_category_id: Number(values.dictionary_category_id),
    name: values.name,
    name_eng: values.name_eng,
    description: values.description,
    youtube: nullableText(values.youtube),
    youtube_start_hour: nullableNumber(values.youtube_start_hour),
    youtube_start_minute: nullableNumber(values.youtube_start_minute),
    youtube_start_second: nullableNumber(values.youtube_start_second),
    image_txt: nullableText(values.image_txt),
    image_url: nullableText(values.image_url),
    ...(values.remove_image ? { remove_image: true } : {}),
  }
}

/**
 * 本文を multipart の FormData にする(画像を上げるので、API は multipart で受ける)。
 * null の項目は送らない(API は送らない項目を空として読む)。真偽値は、API の boolean の規則が読む "1" で送る
 * ("true" は 422 になる。AdminImageStore::rules)。
 */
export function toDictionaryFormData(body: SaveDictionaryBody, image: File | null): FormData {
  const form = new FormData()
  for (const [key, value] of Object.entries(body)) {
    if (value === null || value === undefined || value === false) {
      continue
    }
    form.append(key, value === true ? '1' : String(value))
  }
  if (image !== null) {
    form.append('image', image)
  }
  return form
}
