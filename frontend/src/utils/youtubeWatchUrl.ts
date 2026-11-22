/** 動画は YouTube の動画の ID で持っていて、旧と同じく YouTube の動画のページを開く */
export function youtubeWatchUrl(videoId: string): string {
  return `https://www.youtube.com/watch?v=${encodeURIComponent(videoId)}`
}
