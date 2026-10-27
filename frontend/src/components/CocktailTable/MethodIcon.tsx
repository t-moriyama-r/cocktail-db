import FontAwesomeIcon from './FontAwesomeIcon'

/**
 * 技法(ビルド・シェークなど)を、旧と同じ Font Awesome のアイコンで出す。
 * アイコンだけでは何の技法か分からない人(読み上げ)のために、技法の名前を読み上げる名前にする。
 * 旧に無い技法の ID は、旧と同じく何も出さない。
 */
export default function MethodIcon({ method }: { method: { id: number; name: string } }) {
  const icon = METHOD_ICONS[method.id]
  if (!icon) {
    return null
  }
  return <FontAwesomeIcon icon={icon} label={method.name} />
}

/** 技法の ID とアイコン(旧 Results/Tables/Cocktails.vue と同じ対応) */
const METHOD_ICONS: Partial<Record<number, 'slash' | 'fill-drip' | 'glass-martini-alt' | 'blender' | 'glass-whiskey' | 'mug-hot'>> = {
  1: 'slash',
  2: 'fill-drip',
  3: 'glass-martini-alt',
  4: 'blender',
  5: 'glass-whiskey',
  6: 'mug-hot',
}
