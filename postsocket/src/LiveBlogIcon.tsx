/**
 * The live blog icon: a feed of entries with the newest one marked, as an inline SVG.
 *
 * @since 0.1.0
 */
export default function LiveBlogIcon({ size = 24 }: { size?: number }): JSX.Element {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width={size} height={size} aria-hidden="true" focusable="false">
      <path fill="currentColor" d="M6.5 1a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Zm0 1.5a3 3 0 1 1 0 6 3 3 0 0 1 0-6Z" />
      <circle fill="currentColor" cx="6.5" cy="5.5" r="1.5" />
      <rect fill="currentColor" x="5.75" y="8.5" width="1.5" height="11.25" rx="0.75" />
      <circle fill="currentColor" cx="6.5" cy="13" r="1.6" />
      <circle fill="currentColor" cx="6.5" cy="19" r="1.6" />
      <rect fill="currentColor" x="12.25" y="3.5" width="9.5" height="1.5" rx="0.75" />
      <rect fill="currentColor" x="12.25" y="6.25" width="6" height="1.5" rx="0.75" />
      <rect fill="currentColor" x="12.25" y="12.25" width="8.5" height="1.5" rx="0.75" />
      <rect fill="currentColor" x="12.25" y="18.25" width="6.5" height="1.5" rx="0.75" />
    </svg>
  );
}
