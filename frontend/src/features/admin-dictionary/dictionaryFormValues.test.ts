import { describe, expect, test } from 'vitest'
import {
  EMPTY_DICTIONARY_FORM,
  toDictionaryForm,
  toDictionaryFormData,
  toSaveDictionaryBody,
  type DictionaryFormValues,
} from './dictionaryFormValues'

const filled: DictionaryFormValues = {
  ...EMPTY_DICTIONARY_FORM,
  dictionary_category_id: '2',
  name: 'タンブラー',
  name_eng: 'Tumbler',
  description: '脚の無いグラス。',
  youtube: 'https://www.youtube.com/watch?v=abcdefghijk',
  youtube_start_hour: '0',
  youtube_start_minute: '1',
  youtube_start_second: '',
}

describe('欄の値を API の本文にする', () => {
  test('TC-66-16: 分類を選んでいなければ本文を作らない(送らない)', () => {
    expect(toSaveDictionaryBody({ ...filled, dictionary_category_id: '' })).toBeNull()
  })

  test('TC-66-17: 空の欄は null、数字の欄は数、0 は 0 のまま', () => {
    expect(toSaveDictionaryBody(filled)).toEqual({
      dictionary_category_id: 2,
      name: 'タンブラー',
      name_eng: 'Tumbler',
      description: '脚の無いグラス。',
      youtube: 'https://www.youtube.com/watch?v=abcdefghijk',
      youtube_start_hour: 0,
      youtube_start_minute: 1,
      youtube_start_second: null,
      image_txt: null,
      image_url: null,
    })
  })

  test('TC-66-18: FormData には null の項目を入れず、画像を消す指定は "1"、画像はファイルで入れる', () => {
    const body = toSaveDictionaryBody({ ...filled, remove_image: true })!
    const image = new File(['png'], 'glass.png', { type: 'image/png' })

    const form = toDictionaryFormData(body, image)

    const keys: string[] = []
    form.forEach((_, key) => keys.push(key))
    expect(keys).toEqual([
      'dictionary_category_id',
      'name',
      'name_eng',
      'description',
      'youtube',
      'youtube_start_hour',
      'youtube_start_minute',
      'remove_image',
      'image',
    ])
    expect(form.get('dictionary_category_id')).toBe('2')
    expect(form.get('youtube_start_hour')).toBe('0')
    expect(form.get('remove_image')).toBe('1')
    expect(form.get('image')).toBeInstanceOf(Blob)
  })
})

describe('開いた用語を欄の値にする', () => {
  test('TC-66-19: null の項目は空の欄にし、数は文字にする', () => {
    expect(
      toDictionaryForm({
        id: 7,
        dictionary_category_id: 2,
        name: 'タンブラー',
        name_eng: 'Tumbler',
        description: null,
        youtube: 'abcdefghijk',
        youtube_start_hour: 0,
        youtube_start_minute: 1,
        youtube_start_second: 30,
        image: 'glass.png',
        image_txt: null,
        image_url: null,
        updated_at: null,
      }),
    ).toEqual({
      ...EMPTY_DICTIONARY_FORM,
      dictionary_category_id: '2',
      name: 'タンブラー',
      name_eng: 'Tumbler',
      youtube: 'abcdefghijk',
      youtube_start_hour: '0',
      youtube_start_minute: '1',
      youtube_start_second: '30',
    })
  })
})
