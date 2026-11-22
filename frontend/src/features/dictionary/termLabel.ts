/**
 * 用語辞典の分類・用語の名前の出し方「〈名前〉(〈英名〉)」(旧 Dictionary/index.vue・detail.vue と同じ。括弧は半角)。
 * 一覧の見出しと用語、詳細の題とページのタイトルで使う
 */
export function termLabel({ name, name_eng }: { name: string; name_eng: string }): string {
  return `${name}(${name_eng})`
}
