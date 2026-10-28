/**
 * 甘さ(-10〜10 の数)を、表に出す言葉にする(旧 Modules/helper.js の sweet_degree_str と同じ)。
 *
 * 99 は旧のデータで「甘さを決めていない」印として使われていた値なので、null と同じく「-」にする。
 * 旧には「中甘」の分岐もあったが、「甘め」の分岐より後ろにあって出ることが無かった。旧と同じ出方にするため置かない(#282 の既定)。
 */
export function sweetDegreeLabel(degree: number | null): string {
  if (degree === null || degree === UNSET_SWEET_DEGREE) return '-'
  if (degree <= -7) return '辛口'
  if (degree <= -4) return '中辛'
  if (degree <= -1) return '辛め'
  if (degree <= 1) return '普通'
  if (degree <= 3) return '甘め'
  return '甘口'
}

const UNSET_SWEET_DEGREE = 99
