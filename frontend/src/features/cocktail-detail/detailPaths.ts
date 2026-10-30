/** カクテルの詳細の画面から行くリンク先(App.tsx のルートと同じ URL) */

export function categoryPath(id: number): string {
  return `/category/${id}`
}

export function brandPath(id: number): string {
  return `/detail/brand/${id}`
}

export function dictionaryPath(id: number): string {
  return `/dictionary/${id}`
}
