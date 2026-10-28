/**
 * 度数を小数 1 桁の文字にする(旧 Modules/helper.js の fixDecimalPoint と同じ)。
 * 旧は度数が無いと `0.0` と出していて、それを旧と同じにすると決めている(#282 の既定)ので、null も 0 として出す。
 */
export function strengthLabel(strength: number | null): string {
  return (strength ?? 0).toFixed(1)
}
