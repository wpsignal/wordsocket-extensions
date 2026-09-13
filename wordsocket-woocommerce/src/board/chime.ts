/**
 * A short two-tone chime for new orders, synthesised so the plugin ships no
 * audio file. Browsers only allow audio after a user gesture, so the first
 * call after page load may be silent; the context is created lazily and kept.
 */
let context: AudioContext | null = null;

export function playChime(): void {
  try {
    context ??= new AudioContext();
    if (context.state === "suspended") {
      void context.resume();
    }
    const now = context.currentTime;
    [880, 1175].forEach((frequency, i) => {
      const osc = context!.createOscillator();
      const gain = context!.createGain();
      osc.type = "sine";
      osc.frequency.value = frequency;
      gain.gain.setValueAtTime(0.0001, now + i * 0.15);
      gain.gain.exponentialRampToValueAtTime(0.2, now + i * 0.15 + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + i * 0.15 + 0.3);
      osc.connect(gain).connect(context!.destination);
      osc.start(now + i * 0.15);
      osc.stop(now + i * 0.15 + 0.32);
    });
  } catch {
    // No audio available: the board still updates.
  }
}
