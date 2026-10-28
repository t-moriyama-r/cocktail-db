import Description, { type DescriptionSegment } from './Description'

/**
 * 部品を画面で確かめるための見本(preview.html から開く)。種類ごとのリンク・動画ありの色・改行・HTML を含む文字を並べる
 */
export default function DescriptionPreview() {
  return (
    <main style={{ display: 'grid', gap: 'var(--space-5)', padding: 'var(--space-4)', maxWidth: 640 }}>
      {SAMPLES.map(({ title, segments }) => (
        <section key={title}>
          <h2>{title}</h2>
          <Description segments={segments} />
        </section>
      ))}
    </main>
  )
}

const SAMPLES: { title: string; segments: DescriptionSegment[] }[] = [
  {
    title: '種類ごとのリンク(動画なし)',
    segments: [
      { type: 'text', text: 'ノード: ' },
      { type: 'node', id: 12, text: 'ジン', has_youtube: false },
      { type: 'text', text: ' / カクテル: ' },
      { type: 'cocktail', id: 3, text: 'マティーニ', has_youtube: false },
      { type: 'text', text: ' / 銘柄: ' },
      { type: 'brand', id: 5, text: 'ビーフィーター', has_youtube: false },
      { type: 'text', text: ' / 用語: ' },
      { type: 'dictionary', id: 7, text: 'ステア', has_youtube: false },
    ],
  },
  {
    title: '種類ごとのリンク(動画あり)',
    segments: [
      { type: 'text', text: 'ノード: ' },
      { type: 'node', id: 12, text: 'ジン', has_youtube: true },
      { type: 'text', text: ' / カクテル: ' },
      { type: 'cocktail', id: 3, text: 'マティーニ', has_youtube: true },
      { type: 'text', text: ' / 銘柄: ' },
      { type: 'brand', id: 5, text: 'ビーフィーター', has_youtube: true },
      { type: 'text', text: ' / 用語: ' },
      { type: 'dictionary', id: 7, text: 'ステア', has_youtube: true },
    ],
  },
  {
    title: '改行(1 つ・2 つ続き・\\r\\n)',
    segments: [{ type: 'text', text: '1行目\n2行目\n\n4行目\r\n5行目' }],
  },
  {
    title: 'HTML を含む文字',
    segments: [{ type: 'text', text: '<script>alert(1)</script> <b>太字</b> &amp; 記号' }],
  },
]
